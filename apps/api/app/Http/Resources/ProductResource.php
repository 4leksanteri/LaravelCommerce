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

            // What it costs to post, in the shop's currency (ADR 0057). Nought
            // is free shipping, which is an answer rather than an absent one -
            // so this is never null and the form always has a figure to show.
            'shipping_minor' => $this->product->shipping_minor,

            'can_edit' => $this->canEdit($viewer),
            'can_publish' => $this->canPublish($viewer),
            'is_public' => $this->isPublic(),

            /*
             * What the platform did about a report, on the one screen the
             * seller sees (ADR 0054).
             *
             * Note that `can_publish` above stays true, and that is the
             * existing design rather than an oversight: it is ownership, and
             * `ProductPolicy` deliberately keeps facts about the world out of
             * it - an unapproved shop is refused by `PublishProduct` in exactly
             * the same way.
             *
             * What is worth publishing is that this refusal is the one the
             * seller cannot wait out. A shop gets approved and a category gets
             * chosen; a listing the platform took down stays down, which is why
             * `PublishProduct` checks it first. Without this the seller gets a
             * button that will never work and no explanation.
             *
             * @var bool
             */
            'was_removed_by_staff' => $this->wasRemoved(),

            /*
             * Why, in the words staff gave when they upheld the report.
             *
             * Non-null exactly when the flag above is true, because
             * `products_removal_is_whole` makes the three columns move
             * together - and typed nullable anyway, since that is what the
             * column is and a resource should not promise otherwise.
             *
             * @var string|null
             */
            'removal_reason' => $this->product->removal_reason,

            /** @var string|null */
            'removed_at' => $this->product->removed_at?->toIso8601String(),
        ];
    }

    /**
     * Whether the platform took it down (ADR 0054).
     *
     * Asked of the model rather than restated here, for the reason `is_public`
     * below gives: one definition, in the place that owns it.
     */
    private function wasRemoved(): bool
    {
        return $this->product->wasRemovedByStaff();
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
