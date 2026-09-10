<?php

declare(strict_types=1);

namespace App\Actions\Products;

use App\Exceptions\CannotRemoveLastVariantException;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

/**
 * Removes one way of buying a product, keeping the product.
 */
final class RemoveProductVariant
{
    /**
     * @throws CannotRemoveLastVariantException
     */
    public function handle(ProductVariant $variant): void
    {
        DB::transaction(function () use ($variant): void {
            // The lock is taken on the **product**, not on the variants.
            //
            // Two requests each removing a different variant of a two-variant
            // product would otherwise both see two remaining and both proceed,
            // leaving a listing with none. Locking the parent serialises them,
            // and it is the right row to lock anyway: "at least one variant"
            // is the product's invariant, not any single variant's.
            //
            // Locking the variants instead does not work at all here -
            // PostgreSQL refuses FOR UPDATE combined with an aggregate, so
            // `lockForUpdate()->count()` is an error rather than a lock.
            Product::query()->whereKey($variant->product_id)->lockForUpdate()->firstOrFail();

            $remaining = ProductVariant::query()
                ->where('product_id', $variant->product_id)
                ->count();

            if ($remaining <= 1) {
                throw new CannotRemoveLastVariantException;
            }

            $variant->delete();
        });
    }
}
