<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Currency;
use App\Enums\ProductStatus;
use Carbon\CarbonInterface;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A listing.
 *
 * It has no price and no stock: those belong to its variants, and a product
 * with nothing to choose from still has exactly one. Asking a product what it
 * costs is a question with no single answer once it has two sizes, so it is
 * not a question this class answers.
 *
 * `slug`, `status` and `published_at` are absent from the fillable list. None
 * is a field a request body sets - the slug is derived once and publication is
 * its own endpoint.
 *
 * @property-read Seller $seller
 * @property-read Category|null $category
 * @property-read Collection<int, ProductVariant> $variants
 * @property-read Collection<int, ProductImage> $images
 * @property-read Collection<int, Review> $reviews
 * @property CarbonInterface|null $removed_at
 * @property string|null $removal_reason
 * @property int|null $removed_by
 * @property int $shipping_minor
 */
#[Fillable(['name', 'description', 'category_id', 'shipping_minor'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
            'published_at' => 'datetime',

            // Integer minor units, in the shop's currency (ADR 0057). Cast so
            // arithmetic against a price is never a string.
            'shipping_minor' => 'integer',

            // Cast for the same reason `Review::hidden_at` is: `ProductResource`
            // publishes it as an ISO string, and without this it is a raw
            // string from the driver that has no `toIso8601String()` on it.
            'removed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Seller, $this> */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    /**
     * Whether the platform took this listing down (ADR 0054).
     *
     * Distinct from both of the seller's own ways of removing something: a
     * draft is theirs to publish again, and a soft delete is theirs to make.
     * This one is sticky, and `PublishProduct` refuses it - the database says
     * so too, with `products_removed_is_not_published`.
     */
    public function wasRemovedByStaff(): bool
    {
        return $this->removed_at !== null;
    }

    /**
     * What kind of thing this is.
     *
     * Nullable in the column and required to publish: a draft can be anything,
     * a listing on sale has to be findable. `PublishProduct` holds that rule,
     * the same way it holds the one about an approved shop (ADR 0017).
     *
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<ProductVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Photographs, in the order the seller arranged them.
     *
     * The first is the one a grid shows. That is an ordering rather than an
     * `is_primary` column because an order needs no rule to keep exactly one of
     * them true.
     *
     * @return HasMany<ProductImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)
            ->orderBy('position')
            ->orderBy('id')
            /*
             * Sets each image's `product` back to this one as they are loaded.
             *
             * Not a micro-optimisation: `ProductImage::url()` asks whether its
             * product is public in order to decide whether the URL needs
             * signing, and without this every image on a page of 24 listings
             * would go and fetch the product it was just loaded from.
             */
            ->chaperone();
    }

    /**
     * What people who bought this thought of it (ADR 0047).
     *
     * Newest first, because a listing that was good two years ago and is bad
     * now should read that way round.
     *
     * @return HasMany<Review, $this>
     */
    public function reviews(): HasMany
    {
        /*
         * **Visible ones only** (ADR 0054), and this single condition is what
         * keeps a hidden review out of four different readers: the rating
         * average, the rating count, and the fallback each of those runs when
         * the scope was forgotten. None of them mentions hiding.
         *
         * `Review::scopeVisible()` is the one definition; this delegates to it
         * rather than repeating `whereNull`, so there is nowhere for the two to
         * disagree.
         */
        return $this->hasMany(Review::class)->visible()->latest('id');
    }

    /**
     * The rating and how many gave it, in one join.
     *
     * **Carried by a scope so the next endpoint cannot forget it.** A card, a
     * search result and the listing's own page all show a rating, and four
     * separate queries build them; without this the fourth would be an
     * aggregate per row. It is the same reasoning `scopePublic` gives, applied
     * to a figure rather than a rule.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeWithRating(Builder $query): void
    {
        $query
            ->withAvg('reviews as rating_average', 'rating')
            ->withCount('reviews as rating_count');
    }

    /**
     * What this is rated out of five, or null when nobody has said.
     *
     * **"Nobody has reviewed it" and "the query forgot to ask" are different
     * things**, and telling them apart is why this reads the attribute's
     * presence rather than its value. Both arrive as null otherwise, and a page
     * that dropped `withRating()` would quietly publish "no reviews" about a
     * listing with forty of them.
     *
     * The fallback is a real query, so the answer is never wrong - only slower,
     * and only where somebody forgot.
     */
    public function averageRating(): ?float
    {
        if (! array_key_exists('rating_average', $this->attributes)) {
            $average = $this->reviews()->avg('rating');

            return $average === null ? null : (float) $average;
        }

        $average = $this->getAttribute('rating_average');

        return $average === null ? null : (float) $average;
    }

    /** How many reviews it has, by the same rule. */
    public function ratingCount(): int
    {
        if (! array_key_exists('rating_count', $this->attributes)) {
            return $this->reviews()->count();
        }

        return (int) $this->getAttribute('rating_count');
    }

    /**
     * What a shopper may see.
     *
     * **Two conditions, and both are load-bearing.** A published product in a
     * shop that has not been approved must not be public - otherwise applying
     * to sell and publishing immediately would put a shop on the marketplace
     * without anybody reviewing it, which is the thing approval exists to
     * prevent.
     *
     * They are combined here rather than at each call site so that the second
     * one cannot be the one somebody forgets.
     *
     * @param  Builder<Product>  $query
     */
    public function scopePublic(Builder $query): void
    {
        $query
            ->where('status', ProductStatus::Published)
            // Through a named method rather than an inline closure, so the
            // builder can be typed as the Seller's. An inline closure's
            // parameter is Builder<Model> to the analyser, which knows nothing
            // about scopePublic - and reaching past it to `where('status',
            // ...)` would be a second definition of "approved" living here.
            ->whereHas('seller', self::approvedSeller(...));
    }

    /**
     * @param  Builder<Seller>  $sellers
     */
    private static function approvedSeller(Builder $sellers): void
    {
        $sellers->public();
    }

    /**
     * The text search configuration, named in one place.
     *
     * It has to match the one baked into `search_vector` by the migration
     * exactly. A query using a different configuration would still run, would
     * still return rows, and would quietly stem differently from the index.
     */
    public const string SEARCH_CONFIG = 'english';

    /**
     * Listings matching what somebody typed into a search box.
     *
     * `websearch_to_tsquery` rather than `plainto_tsquery`, because people type
     * search syntax whether or not it is supported: quoted phrases, `or`, and a
     * leading `-` to exclude. It understands all three and never throws on
     * malformed input, which `to_tsquery` does.
     *
     * Words are combined with AND, so "olympus 50mm" means both.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeMatching(Builder $query, string $term): void
    {
        $query->whereRaw(
            sprintf("search_vector @@ websearch_to_tsquery('%s', ?)", self::SEARCH_CONFIG),
            [$term],
        );
    }

    /**
     * Best match first.
     *
     * Separate from `matching()` so a caller can search without ordering by
     * relevance - a category page filtered by a term still wants newest first,
     * because there "relevant" is not what the shopper is asking.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeByRelevance(Builder $query, string $term): void
    {
        $query->orderByRaw(
            sprintf("ts_rank(search_vector, websearch_to_tsquery('%s', ?)) DESC", self::SEARCH_CONFIG),
            [$term],
        );
    }

    public function isPublished(): bool
    {
        return $this->status->isPublished();
    }

    /**
     * `scopePublic()`, asked of one loaded row rather than of a query.
     *
     * The same two conditions, and they are stated here once so that a third
     * caller does not write them a third time. `ProductResource` asks it to
     * decide what to tell the seller; `CartItem` asks it to decide whether a
     * line can still be bought.
     *
     * Reading it needs the shop loaded, so callers that ask it of many rows
     * eager-load `seller`.
     */
    public function isPublic(): bool
    {
        return $this->isPublished() && $this->seller->isPublic();
    }

    /**
     * The currency every price on this product is denominated in.
     *
     * Read from the shop, because that is the only place it lives (ADR 0007).
     * A `currency` column here would be a second copy that can disagree.
     */
    public function currency(): Currency
    {
        return $this->seller->currency;
    }
}
