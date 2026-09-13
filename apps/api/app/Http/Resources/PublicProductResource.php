<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A listing as a shopper sees it.
 *
 * A shorter allowlist than `ProductResource`, and the omissions are the point:
 * no status, no publication date, no `can_*`. Whether something is a draft is
 * not a shopper's business, and by construction nothing here is one.
 *
 * **Exact stock counts are not published either.** A shopper needs to know
 * whether they can buy the thing, not that four remain: a live count is a
 * competitor's inventory report, and it invites a race the checkout has to win
 * anyway. `in_stock` is the honest answer to the question actually being
 * asked.
 */
final class PublicProductResource extends JsonResource
{
    /**
     * The caller's standing with this listing is optional, and absent on a list
     * (ADR 0047). Nobody reviews from a grid of cards, so asking per card would
     * be a query per card for an answer nothing draws.
     */
    public function __construct(
        private readonly Product $product,
        private readonly bool $allowedToReview = false,
        private readonly ?Review $yourReview = null,
    ) {
        parent::__construct($product);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->product->slug,
            'name' => $this->product->name,
            'description' => $this->product->description,
            'currency' => $this->product->currency(),

            /*
             * Which shop this is from.
             *
             * Redundant on a shop's own storefront, where the caller supplied
             * the slug. Essential on a category page, which is the first place
             * listings from different shops sit next to each other and a card
             * has to say whose it is.
             */
            'shop_slug' => $this->product->seller->slug,
            'shop_name' => $this->product->seller->shop_name,

            // Null while a listing is a draft. It cannot be null here, because
            // publishing requires one - but the column allows it and the type
            // should say so rather than promise otherwise (ADR 0017).
            'category' => $this->product->category instanceof Category
                ? new CategoryResource($this->product->category)
                : null,

            'images' => ProductImageResource::collection($this->product->images),
            'variants' => $this->product->variants
                ->map(static fn (ProductVariant $variant): array => [
                    'id' => $variant->id,
                    'name' => $variant->name,
                    'price_minor' => $variant->price_minor,
                    'in_stock' => $variant->isInStock(),
                ])
                ->all(),

            /*
             * What a card says it costs, answered here rather than in the
             * browser.
             *
             * A listing with two sizes has two prices (ADR 0009), so "what does
             * this cost" has no single answer and somebody has to decide which
             * figure to advertise. That is a rule, and a rule the frontend
             * derives from `variants` is the copy that drifts - the day this
             * starts excluding sold-out sizes, every card would disagree with
             * it. Equal when there is one price; a card shows "from" when not.
             *
             * Over every variant, sold out or not. Availability is its own
             * answer below, so a sold-out listing still says what it cost
             * rather than losing its price.
             */
            'price_from_minor' => $this->lowestPrice(),
            'price_to_minor' => $this->highestPrice(),

            // Whether any size can be bought. The card needs "sold out", and
            // that is a question about the listing, not about one variant.
            'in_stock' => $this->isAvailable(),

            /*
             * What people who bought it thought (ADR 0047). Both read the
             * aggregate `withRating()` adds, so a page of 24 cards costs one
             * join rather than 24 counts.
             *
             * The annotation is load-bearing: the generator takes no null out
             * of a method's declared return type, and published this as a
             * number that is always there - which a listing nobody has reviewed
             * does not have.
             */
            /** @var float|null */
            'rating' => $this->product->averageRating(),
            'review_count' => $this->product->ratingCount(),

            /*
             * The caller's own standing, and the API's answer rather than a
             * rule the browser re-derives from an order history it cannot see.
             * False on a list, where there is nothing to draw it on.
             */
            /** @var bool */
            'can_review' => $this->canReview(),

            /** @var ReviewResource|null */
            'your_review' => $this->yourReview instanceof Review
                ? new ReviewResource($this->yourReview)
                : null,
        ];
    }

    /**
     * A method for the reader, and the annotation on the key for the generator.
     *
     * Read inline, this published `can_review` as a **string**, exactly as
     * `can_edit` once did. Declaring `: bool` here - the remedy
     * `apps/api/CLAUDE.md` section 8 prescribes - did not change that either,
     * because what it returns is a promoted constructor property rather than a
     * call the generator can follow. The `@var` annotation above the key is
     * what actually types it (ADR 0047).
     */
    private function canReview(): bool
    {
        return $this->allowedToReview;
    }

    /**
     * Null only for a listing with no variants, which publishing refuses - but
     * the relation can be empty and the type says so rather than promising
     * otherwise.
     */
    private function lowestPrice(): ?int
    {
        $lowest = null;

        foreach ($this->product->variants as $variant) {
            $lowest = $lowest === null ? $variant->price_minor : min($lowest, $variant->price_minor);
        }

        return $lowest;
    }

    private function highestPrice(): ?int
    {
        $highest = null;

        foreach ($this->product->variants as $variant) {
            $highest = $highest === null ? $variant->price_minor : max($highest, $variant->price_minor);
        }

        return $highest;
    }

    private function isAvailable(): bool
    {
        return $this->product->variants->contains(
            static fn (ProductVariant $variant): bool => $variant->isInStock(),
        );
    }
}
