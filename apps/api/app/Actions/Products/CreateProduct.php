<?php

declare(strict_types=1);

namespace App\Actions\Products;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\Seller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a listing and the variants it is sold as.
 *
 * Variants are created here rather than through a second request, because a
 * product with none is not a valid product: it has no price and cannot be
 * bought. Requiring at least one at creation is what makes "every product has
 * a variant" true from the first row rather than eventually.
 *
 * A product is always created as a draft. Publishing is its own decision, with
 * its own rules - see PublishProduct.
 */
final class CreateProduct
{
    /**
     * @param  array{name: string, description?: string|null, category_id?: int|null}  $attributes
     * @param  list<array{name: string, price_minor: int, stock?: int}>  $variants
     */
    public function handle(Seller $seller, array $attributes, array $variants): Product
    {
        return DB::transaction(function () use ($seller, $attributes, $variants): Product {
            $product = new Product;

            $product->forceFill([
                'seller_id' => $seller->id,
                'name' => $attributes['name'],
                'slug' => $this->uniqueSlug($seller, $attributes['name']),
                'description' => $attributes['description'] ?? null,

                // Optional here and required to publish (ADR 0017). Somebody
                // typing up a listing has not necessarily decided yet.
                'category_id' => $attributes['category_id'] ?? null,
                'status' => ProductStatus::Draft,
                'published_at' => null,
            ])->save();

            foreach ($variants as $position => $variant) {
                $product->variants()->create([
                    'name' => $variant['name'],
                    'price_minor' => $variant['price_minor'],
                    'stock' => $variant['stock'] ?? 0,
                    // The order they were sent in. Sizes are S, M, L and not
                    // alphabetical, and the seller already chose an order by
                    // typing them in one.
                    'position' => $position,
                ]);
            }

            return $product->load('variants');
        });
    }

    /**
     * Unique within the shop, not globally.
     *
     * Two shops may both sell a rye sourdough, and neither should have to call
     * theirs `rye-sourdough-2` because the other got there first. The unique
     * index is on (seller_id, slug) for the same reason.
     */
    private function uniqueSlug(Seller $seller, string $name): string
    {
        $base = Str::slug($name);

        if ($base === '') {
            $base = 'product';
        }

        $slug = $base;
        $suffix = 1;

        while ($seller->products()->withTrashed()->where('slug', $slug)->exists()) {
            $suffix++;
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }
}
