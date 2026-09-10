<?php

declare(strict_types=1);

namespace App\Actions\Products;

use App\Models\Product;

/**
 * Edits the parts of a listing its seller may change at any time.
 *
 * The omissions matter more than the inclusions:
 *
 *   slug          the product's address. Moving it breaks saved and shared
 *                 links, and search engines that already found it.
 *   status        publishing is its own decision with its own rules.
 *   price, stock  those are the variants', and there is no single price to
 *                 change on a product with two sizes.
 *
 * Editing a published product does **not** take it off sale. A corrected typo
 * in a description is not a reason to withdraw something people are buying.
 */
final class UpdateProductDetails
{
    /**
     * @param  array{name?: string, description?: string|null}  $attributes
     */
    public function handle(Product $product, array $attributes): Product
    {
        $product->fill($attributes)->save();

        return $product;
    }
}
