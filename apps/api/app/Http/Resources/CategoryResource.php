<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Category;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A category, and whatever sits under it.
 *
 * `children` is present only when it was loaded, so this serves both jobs
 * without two classes: the navigation asks for the tree and gets one, and a
 * product's own category is a leaf with no children attached rather than a
 * second query per product.
 */
final class CategoryResource extends JsonResource
{
    public function __construct(private readonly Category $category)
    {
        parent::__construct($category);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->category->id,
            'slug' => $this->category->slug,
            'name' => $this->category->name,

            // Null for a top-level category. A client builds a breadcrumb from
            // this without a second request.
            'parent_slug' => $this->category->parent?->slug,

            /*
             * Always a collection of this same resource, even when empty.
             *
             * A bare `[]` for the unloaded case published this to the frontend
             * as `CategoryResource[] | string[]` - the empty literal has no
             * element type, so the generator invented one. Same family as the
             * `data: string[]` bug named in ProductCollection.
             *
             * Loaded for the navigation, absent on a product's own category,
             * which is what keeps that from being a query per product.
             */
            'children' => CategoryResource::collection(
                $this->category->relationLoaded('children')
                    ? $this->category->children
                    : new Collection,
            ),
        ];
    }
}
