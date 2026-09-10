<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A listing as its seller sees it: drafts included, stock included.
 *
 * Not what a shopper sees - that is `PublicProductResource`.
 */
final class ProductResource extends JsonResource
{
    public function __construct(private readonly Product $product)
    {
        parent::__construct($product);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        return [
            'id' => $this->product->id,
            'name' => $this->product->name,
            'slug' => $this->product->slug,
            'description' => $this->product->description,
            'status' => $this->product->status,

            // Read from the shop, because that is the only place it lives
            // (ADR 0007). Sent on the product so a client does not have to
            // fetch the shop to know what a price means.
            'currency' => $this->product->currency(),

            'published_at' => $this->product->published_at?->toIso8601String(),
            'created_at' => $this->product->created_at?->toIso8601String(),
            'updated_at' => $this->product->updated_at?->toIso8601String(),

            // There is no `price` here, and there will not be. A product with
            // two sizes has two prices, and inventing a headline figure for it
            // would be the API guessing which one matters.
            'variants' => ProductVariantResource::collection($this->product->variants),

            'images' => ProductImageResource::collection($this->product->images),

            // Null while a draft, and the seller has to choose one before this
            // can go on sale - which `can_publish` alone would not explain.
            'category' => $this->product->category instanceof Category
                ? new CategoryResource($this->product->category)
                : null,

            'can_edit' => $this->canEdit($viewer),
            'can_publish' => $this->canPublish($viewer),
            'is_public' => $this->isPublic(),
        ];
    }

    private function canEdit(?Authenticatable $viewer): bool
    {
        return $viewer instanceof User && $viewer->can('update', $this->product);
    }

    private function canPublish(?Authenticatable $viewer): bool
    {
        return $viewer instanceof User && $viewer->can('publish', $this->product);
    }

    /**
     * Whether a shopper can actually see this, which needs both halves: the
     * listing on sale and the shop approved. The same pair `scopePublic` uses,
     * and the same one `Product::isPublic()` states - asked rather than
     * repeated, so there is one definition of what the storefront shows.
     *
     * The declared `: bool` is load-bearing. Computed inline in the array
     * below, the generator published this to the frontend as a string.
     */
    private function isPublic(): bool
    {
        return $this->product->isPublic();
    }
}
