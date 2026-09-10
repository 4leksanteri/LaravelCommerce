<?php

declare(strict_types=1);

namespace App\Actions\Products;

use App\Enums\ProductStatus;
use App\Exceptions\ProductNotPublishableException;
use App\Models\Product;

/**
 * Puts a listing on sale.
 *
 * The rule worth stating: **a shop that has not been approved cannot publish
 * anything.** Without it, applying to sell and immediately publishing would
 * put products on the marketplace that nobody reviewed, which is precisely
 * what approval exists to prevent.
 *
 * Note that this is not the only place that rule holds. `Product::scopePublic`
 * also requires an approved shop, so a product that somehow reached
 * `published` in an unapproved shop still would not be shown. This check is
 * what gives the seller an answer; that scope is what protects the storefront.
 * Neither is redundant.
 */
final class PublishProduct
{
    /**
     * @throws ProductNotPublishableException
     */
    public function handle(Product $product): Product
    {
        if (! $product->seller->isPublic()) {
            throw ProductNotPublishableException::shopNotApproved();
        }

        // A listing nobody can find is not on sale in any useful sense.
        // Drafting without one is fine - somebody typing up a listing has not
        // decided yet - but going on sale is where it has to be answered.
        if ($product->category_id === null) {
            throw ProductNotPublishableException::noCategory();
        }

        // Idempotent. Publishing something already on sale is not an error,
        // and re-stamping published_at would move a date that means "on sale
        // since" for no reason.
        if ($product->isPublished()) {
            return $product;
        }

        $product->forceFill([
            'status' => ProductStatus::Published,
            'published_at' => now(),
        ])->save();

        return $product;
    }
}
