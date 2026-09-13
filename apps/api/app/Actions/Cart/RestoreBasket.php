<?php

declare(strict_types=1);

namespace App\Actions\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Puts an order's lines back in the basket they came from.
 *
 * For an order that expired before anybody paid for it (ADR 0046). Checkout
 * empties the cart inside its own transaction (ADR 0011), so a buyer whose card
 * was declined lost the order **and** the basket that made it, and had to find
 * every item again.
 *
 * **The basket comes back, not the order.** The order is cancelled and its
 * stock has been returned; reviving it would be taking that stock a second
 * time. A cart reserves nothing and holds no prices, so putting the lines back
 * commits nobody to anything - what they cost and whether they can still be
 * bought is read when the cart is next shown (ADR 0010).
 *
 * **Additive, never destructive.** A variant already in the cart gains the
 * quantity, and nothing is removed or overwritten: the buyer may well have gone
 * shopping again while the order sat there unpaid.
 *
 * A line whose variant has since been deleted cannot go back, because a cart
 * line points at a variant. That is the same nullable reference ADR 0011
 * describes, and the rest of the basket is restored regardless.
 */
final class RestoreBasket
{
    /**
     * @return int how many lines went back
     */
    public function handle(Order $order): int
    {
        $items = $order->items()->whereNotNull('product_variant_id')->get();

        if ($items->isEmpty()) {
            return 0;
        }

        // Outside the transaction, for the reason AddToCart gives: a failed
        // insert aborts a PostgreSQL transaction, so the recovery in there
        // could not run inside one.
        $cart = $this->cartFor($order->user);

        return DB::transaction(function () use ($cart, $items, $order): int {
            // The lock is on the cart rather than the line, because the line
            // may not exist yet - and "is it already in there" is exactly what
            // two simultaneous writes would both get wrong.
            Cart::query()->whereKey($cart->id)->lockForUpdate()->firstOrFail();

            foreach ($items as $item) {
                $this->restore($cart, $order, $item);
            }

            // So `carts.updated_at` means "last changed" rather than "created".
            $cart->touch();

            return $items->count();
        });
    }

    private function restore(Cart $cart, Order $order, OrderItem $item): void
    {
        $line = $cart->items()->where('product_variant_id', $item->product_variant_id)->first();

        if ($line instanceof CartItem) {
            $line->forceFill(['quantity' => $line->quantity + $item->quantity])->save();

            return;
        }

        $line = new CartItem;

        $line->forceFill([
            'cart_id' => $cart->id,
            'product_variant_id' => $item->product_variant_id,

            // The order's own shop, not the variant's today. An order is one
            // shop's worth by construction (ADR 0011), and reading it from the
            // order means a line survives a variant that has since moved or
            // gone.
            'seller_id' => $order->seller_id,

            'quantity' => $item->quantity,

            // What it cost when it was bought. `added_price_minor` is a
            // snapshot for saying "this has gone up since", never a price to
            // charge (ADR 0010), and the order's figure is the honest one to
            // put there: it is what this person last agreed to pay.
            'added_price_minor' => $item->unit_price_minor,

            'product_name' => $item->product_name,
            'variant_name' => $item->variant_name,
        ])->save();
    }

    /**
     * This person's cart, started if they have not got one.
     *
     * The same twelve lines as `AddToCart`, deliberately not extracted. Two
     * call sites is where the root `CLAUDE.md` says to leave duplication alone
     * rather than reach for the abstraction, and the shared thing here would be
     * a find-or-create that belongs to neither action.
     */
    private function cartFor(User $user): Cart
    {
        $cart = Cart::query()->where('user_id', $user->id)->first();

        if ($cart instanceof Cart) {
            return $cart;
        }

        try {
            $cart = new Cart;
            $cart->forceFill(['user_id' => $user->id])->save();

            return $cart;
        } catch (UniqueConstraintViolationException) {
            return Cart::query()->where('user_id', $user->id)->firstOrFail();
        }
    }
}
