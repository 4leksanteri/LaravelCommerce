<?php

declare(strict_types=1);

namespace App\Actions\Cart;

use App\Exceptions\VariantNotPurchasableException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Puts a variant in somebody's cart, or adds to what is already there.
 *
 * Two rules, and they answer with two different statuses on purpose:
 *
 *   not for sale       404 - the variant is resolved through the storefront's
 *                      own scope, so it is simply not found
 *   not enough stock   409 - it is for sale, there are just not that many
 *
 * See ADR 0010 for why the first is a 404 rather than a 403 or a 422.
 */
final class AddToCart
{
    /**
     * @throws ModelNotFoundException<ProductVariant> when the variant is not on sale
     * @throws VariantNotPurchasableException when there is not enough stock
     */
    public function handle(User $user, int $variantId, int $quantity): Cart
    {
        $variant = $this->purchasableVariant($variantId);

        // Created outside the transaction. A failed insert aborts a PostgreSQL
        // transaction entirely, so the recovery below could not run inside one.
        $cart = $this->cartFor($user);

        return DB::transaction(function () use ($cart, $variant, $quantity): Cart {
            /*
             * The lock is on the cart, not on the line, because the line may
             * not exist yet - and "is it already in there" is exactly the read
             * two simultaneous adds would both get wrong. Locking the parent
             * serialises them, which is the same shape as RemoveProductVariant
             * locking a product.
             */
            Cart::query()->whereKey($cart->id)->lockForUpdate()->firstOrFail();

            $line = $cart->items()->where('product_variant_id', $variant->id)->first();
            $wanted = ($line instanceof CartItem ? $line->quantity : 0) + $quantity;

            // Read fresh, inside the lock: the seller may have sold the last
            // one between resolving the variant above and getting here.
            $stock = ProductVariant::query()->whereKey($variant->id)->value('stock');

            if (! is_int($stock) || $stock < $wanted) {
                throw VariantNotPurchasableException::onlyAvailable(is_int($stock) ? $stock : 0);
            }

            if ($line instanceof CartItem) {
                // Adding something already in the cart increases its quantity
                // rather than making a second line, which is what the unique
                // index on (cart_id, product_variant_id) requires and what a
                // shopper pressing "add" twice means.
                //
                // The snapshot is deliberately left alone. It records what this
                // cost when it first went in the cart, and re-stamping it here
                // would erase the price change it exists to detect.
                $line->forceFill(['quantity' => $wanted])->save();
            } else {
                $line = new CartItem;

                $line->forceFill([
                    'cart_id' => $cart->id,
                    'product_variant_id' => $variant->id,
                    'seller_id' => $variant->product->seller_id,
                    'quantity' => $wanted,
                    'added_price_minor' => $variant->price_minor,
                    'product_name' => $variant->product->name,
                    'variant_name' => $variant->name,
                ])->save();
            }

            // So `carts.updated_at` means "last changed" rather than "created".
            $cart->touch();

            return $cart;
        });
    }

    /**
     * The variant, resolved **through the storefront's own rules**.
     *
     * A draft, a deleted listing or an unapproved shop is not found, and the
     * caller gets a 404. That is the same answer the storefront gives for the
     * same product (ADR 0009), and it means there is one definition of "for
     * sale" rather than a copy of it here.
     *
     * Note what is deliberately **not** in the form request: an `exists` rule
     * on `variant_id`. With one, an id that does not exist would be a 422 and
     * an unpublished one a 404, and the difference between the two answers
     * would tell somebody guessing which ids are real.
     *
     * @throws ModelNotFoundException<ProductVariant>
     */
    private function purchasableVariant(int $variantId): ProductVariant
    {
        return ProductVariant::query()
            ->whereKey($variantId)
            ->whereHas('product', self::sellableProduct(...))
            ->with('product')
            ->firstOrFail();
    }

    /**
     * @param  Builder<Product>  $products
     */
    private static function sellableProduct(Builder $products): void
    {
        $products->public();
    }

    /**
     * This person's cart, started if they have not got one.
     *
     * The catch is not defensive noise. Two requests adding their first item at
     * once both find nothing and both insert; the unique index on
     * `carts.user_id` refuses the second, and what that request wants is the
     * row the first one just made.
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
