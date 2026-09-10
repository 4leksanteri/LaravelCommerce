<?php

declare(strict_types=1);

namespace App\Actions\Cart;

use App\Exceptions\VariantNotPurchasableException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

/**
 * Changes how many of a line somebody wants.
 *
 * Sets, rather than adds. A quantity box on a cart page sends the number in the
 * box, and an endpoint that added to it instead would double the line every
 * time somebody re-submitted the same form.
 *
 * Zero is not accepted here and is not a way to delete a line - the form
 * request refuses it with a 422 and `DELETE` removes lines. One way to do a
 * thing.
 */
final class SetCartItemQuantity
{
    /**
     * @throws VariantNotPurchasableException
     */
    public function handle(CartItem $line, int $quantity): CartItem
    {
        return DB::transaction(function () use ($line, $quantity): CartItem {
            // The same lock AddToCart takes, on the same row, so the two
            // cannot interleave on one cart.
            Cart::query()->whereKey($line->cart_id)->lockForUpdate()->firstOrFail();

            $variant = $line->purchasableVariant()->first();

            if (! $variant instanceof ProductVariant) {
                // The listing was unpublished, deleted, or its shop suspended
                // while this sat in the cart. 409: the line is theirs and the
                // number they sent is valid, and what is in the way is the
                // state of the catalogue.
                throw VariantNotPurchasableException::noLongerForSale();
            }

            if ($variant->stock < $quantity) {
                throw VariantNotPurchasableException::onlyAvailable($variant->stock);
            }

            $line->forceFill(['quantity' => $quantity])->save();
            $line->cart->touch();

            return $line;
        });
    }
}
