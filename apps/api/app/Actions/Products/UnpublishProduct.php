<?php

declare(strict_types=1);

namespace App\Actions\Products;

use App\Enums\ProductStatus;
use App\Models\Product;

/**
 * Takes a listing off sale, keeping it.
 *
 * A separate action from PublishProduct rather than one taking a boolean.
 * `publish($product, false)` reads as nothing at the call site, and the two
 * do not have the same preconditions: publishing asks whether the shop is
 * approved, and withdrawing something from sale never needs anybody's
 * permission.
 */
final class UnpublishProduct
{
    public function handle(Product $product): Product
    {
        if (! $product->isPublished()) {
            return $product;
        }

        // published_at goes with it. The column means "on sale since", and the
        // table's check constraint refuses a date on something not on sale.
        $product->forceFill([
            'status' => ProductStatus::Draft,
            'published_at' => null,
        ])->save();

        return $product;
    }
}
