<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Exceptions\CheckoutBlockedException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turns a cart into orders. The whole of checkout.
 *
 * Five things happen, in one transaction, in this order:
 *
 *   1. the cart is locked, so a second checkout waits rather than racing
 *   2. every variant in it is locked, in id order, so two overlapping baskets
 *      cannot deadlock
 *   3. every line is validated again, against the locked stock
 *   4. one order per shop is written, snapshotting what was agreed
 *   5. stock is taken, and only then is the cart emptied
 *
 * **It is all or nothing.** One unavailable line refuses the whole checkout
 * rather than placing orders for the shops that happened to be fine. The buyer
 * pressed a button under a basket and a set of subtotals; quietly buying some
 * of it is not what they asked for, and it would leave them to work out which
 * part went through.
 *
 * That is also why the cart is emptied last and inside the transaction. A
 * failure anywhere above leaves the cart exactly as it was, with no stock
 * taken and no order half-written.
 */
final class PlaceOrders
{
    /**
     * No I, L, O, U, 0 or 1. A reference gets read down a telephone and typed
     * back in, and those are the characters that come back wrong.
     */
    private const string REFERENCE_ALPHABET = 'ABCDEFGHJKMNPQRSTVWXYZ23456789';

    private const int REFERENCE_LENGTH = 10;

    /**
     * @return Collection<int, Order> one per shop, ordered by shop name
     *
     * @throws CheckoutBlockedException
     */
    public function handle(User $buyer): Collection
    {
        return DB::transaction(function () use ($buyer): Collection {
            $cart = $buyer->cart;

            if (! $cart instanceof Cart) {
                throw CheckoutBlockedException::emptyCart();
            }

            // A second checkout of the same cart waits here, and then finds it
            // empty - which is a 409 rather than a duplicate set of orders.
            Cart::query()->whereKey($cart->id)->lockForUpdate()->firstOrFail();

            $lines = $cart->lines()
                ->sortBy(static fn (CartItem $line): string => $line->seller->shop_name)
                ->values();

            if ($lines->isEmpty()) {
                throw CheckoutBlockedException::emptyCart();
            }

            $this->revalidate($lines, $this->lockStock($lines));

            $orders = $this->write($buyer, $lines);

            $cart->items()->delete();
            $cart->touch();

            return $orders;
        });
    }

    /**
     * Locks every variant in the cart and reads its stock under that lock.
     *
     * **Ordered by id**, which is the part that matters. Two shoppers checking
     * out baskets that share two variants would otherwise be able to take them
     * in opposite orders and deadlock; taking them in the same order means the
     * second simply waits.
     *
     * @param  Collection<int, CartItem>  $lines
     * @return array<int, int> stock, keyed by variant id
     */
    private function lockStock(Collection $lines): array
    {
        $ids = $lines
            ->map(static fn (CartItem $line): ?int => $line->product_variant_id)
            ->filter(static fn (?int $id): bool => $id !== null)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        $stock = [];

        $locked = ProductVariant::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'stock']);

        foreach ($locked as $variant) {
            $stock[$variant->id] = $variant->stock;
        }

        return $stock;
    }

    /**
     * The whole cart, checked again, against the numbers just locked.
     *
     * The stock read a moment ago when the lines were loaded is replaced by the
     * one read under the lock, and then `CartItem::availability()` answers -
     * the same method the cart page uses. Re-deriving availability here would
     * be a second definition of what can be bought, and the two would drift.
     *
     * @param  Collection<int, CartItem>  $lines
     * @param  array<int, int>  $stock
     *
     * @throws CheckoutBlockedException
     */
    private function revalidate(Collection $lines, array $stock): void
    {
        $blocked = [];

        foreach ($lines as $line) {
            $variant = $line->purchasableVariant;

            if ($variant instanceof ProductVariant) {
                $variant->setAttribute('stock', $stock[$variant->id] ?? 0);
            }

            if ($line->isAvailable()) {
                continue;
            }

            $blocked[] = [
                'id' => $line->id,
                'product_name' => $line->product_name,
                'variant_name' => $line->variant_name,
                'availability' => $line->availability()->value,
                'available' => $line->availableQuantity(),
            ];
        }

        if ($blocked !== []) {
            throw CheckoutBlockedException::unavailable($blocked);
        }
    }

    /**
     * One order per shop, and the stock taken for it.
     *
     * Totals are summed here, from prices read from the catalogue moments ago
     * under lock. Nothing the client sent contributes a figure.
     *
     * @param  Collection<int, CartItem>  $lines
     * @return Collection<int, Order>
     */
    private function write(User $buyer, Collection $lines): Collection
    {
        /** @var Collection<int, Order> $orders */
        $orders = new Collection;

        foreach ($lines->groupBy('seller_id') as $shopLines) {
            $first = $shopLines->first();

            if (! $first instanceof CartItem) {
                continue;
            }

            $shop = $first->seller;

            $order = new Order;
            $order->forceFill([
                'reference' => $this->uniqueReference(),
                'user_id' => $buyer->id,
                'seller_id' => $shop->id,
                'status' => OrderStatus::Pending,

                // Snapshotted from the shop. It cannot change today, and a
                // receipt should not depend on that staying true.
                'currency' => $shop->currency,

                'total_minor' => 0,
            ])->save();

            $total = 0;

            foreach ($shopLines as $line) {
                $variant = $line->purchasableVariant;

                if (! $variant instanceof ProductVariant) {
                    // Unreachable: every line was checked above, in this
                    // transaction and behind these locks. The branch proves the
                    // type rather than promising it, and fails closed if the
                    // order of the steps above is ever changed.
                    throw new RuntimeException('A cart line reached checkout without a variant.');
                }

                $unitPrice = $variant->price_minor;
                $total += $unitPrice * $line->quantity;

                $item = new OrderItem;
                $item->forceFill([
                    'order_id' => $order->id,
                    'product_variant_id' => $variant->id,

                    // What it is called now, not what the cart remembered from
                    // when it was added. The catalogue is the source of truth
                    // right up to this line, and this is where it stops being.
                    'product_name' => $variant->product->name,
                    'variant_name' => $variant->name,

                    'unit_price_minor' => $unitPrice,
                    'quantity' => $line->quantity,
                ])->save();

                // Taken here rather than at payment, because an order that
                // holds no stock is an order two people can place for the same
                // last item. The CHECK constraint on the column is the last
                // line of defence if this is ever reached without the lock.
                $variant->decrement('stock', $line->quantity);
            }

            $order->forceFill(['total_minor' => $total])->save();

            $orders->push($order->load(['items', 'seller']));
        }

        return $orders;
    }

    private function uniqueReference(): string
    {
        do {
            $reference = $this->randomReference();
        } while (Order::query()->where('reference', $reference)->exists());

        return $reference;
    }

    private function randomReference(): string
    {
        $alphabet = self::REFERENCE_ALPHABET;
        $lastIndex = strlen($alphabet) - 1;
        $reference = '';

        for ($character = 0; $character < self::REFERENCE_LENGTH; $character++) {
            $reference .= $alphabet[random_int(0, $lastIndex)];
        }

        return $reference;
    }
}
