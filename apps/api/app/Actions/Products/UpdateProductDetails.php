<?php

declare(strict_types=1);

namespace App\Actions\Products;

use App\Exceptions\PublishedProductNeedsCategoryException;
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
     * `category_id` is here and the three above are not, because it is a field
     * the seller picks from a list the platform owns - not one derived, and not
     * one with its own workflow.
     *
     * @param  array{name?: string, description?: string|null, category_id?: int|null}  $attributes
     *
     * @throws PublishedProductNeedsCategoryException
     */
    public function handle(Product $product, array $attributes): Product
    {
        // `null` is a valid value for this column - it is what every draft has -
        // so the form request cannot refuse it, and the database will, with a
        // constraint violation rendered as a 500. This is the translation.
        if (array_key_exists('category_id', $attributes)
            && $attributes['category_id'] === null
            && $product->isPublished()
        ) {
            throw PublishedProductNeedsCategoryException::make();
        }

        $product->fill($attributes)->save();

        return $product;
    }
}
