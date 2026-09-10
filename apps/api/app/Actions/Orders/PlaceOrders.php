<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Exceptions\CheckoutBlockedException;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
     * @throws ModelNotFoundException<Address> when the address is not this buyer's
     */
    public function handle(User $buyer, int $addressId): Collection
    {
        /*
         * Resolved through the buyer's own book, so somebody else's id and one
         * that does not exist give the same answer. Outside the transaction
         * because it is a read that decides whether there is anything to do.
         */
        $address = $buyer->addresses()->findOrFail($addressId);

        return DB::transaction(function () use ($buyer, $address): Collection {
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

            $orders = $this->write($buyer, $address, $lines);

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
    private function write(User $buyer, Address $address, Collection $lines): Collection
    {
        /** @var Collection<int, Order> $orders */
        $orders = new Collection;

        // Drawn once, before the loop, and shared by every order this checkout
        // produces. It is the only record that these were one purchase - three
        // orders a few milliseconds apart are otherwise indistinguishable from
        // three separate ones, and no later migration can recover the
        // difference. See ADR 0011.
        $checkoutReference = $this->unusedReference();

        foreach ($lines->groupBy('seller_id') as $shopLines) {
            $first = $shopLines->first();

            if (! $first instanceof CartItem) {
                continue;
            }

            $shop = $first->seller;

            $order = new Order;
            $order->forceFill([
                'reference' => $this->unusedReference(),
                'checkout_reference' => $checkoutReference,
                'user_id' => $buyer->id,
                'seller_id' => $shop->id,
                'status' => OrderStatus::Pending,

                // Snapshotted from the shop. It cannot change today, and a
                // receipt should not depend on that staying true.
                'currency' => $shop->currency,

                'total_minor' => 0,

                /*
                 * Frozen, not referenced. A buyer who moves house edits their
                 * address book, and without this every order they ever placed
                 * would silently start claiming it went somewhere it did not
                 * (ADR 0021).
                 *
                 * Every order in a multi-shop checkout gets the same one: one
                 * basket, one destination, however many parcels.
                 */
                ...$address->toOrderSnapshot(),
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

    /**
     * A reference that names nothing yet.
     *
     * Checked against **both** columns, so a given string is either an order or
     * a checkout and never both. Somebody reading a reference down a telephone
     * should not have to say which kind it is.
     */
    private function unusedReference(): string
    {
        do {
            $reference = $this->randomReference();
        } while (
            Order::query()
                ->where('reference', $reference)
                ->orWhere('checkout_reference', $reference)
                ->exists()
        );

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
