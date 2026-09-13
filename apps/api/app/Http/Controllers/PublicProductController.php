<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Reviews\LeaveReview;
use App\Http\Resources\PaginatedCollection;
use App\Http\Resources\PublicProductCollection;
use App\Http\Resources\PublicProductResource;
use App\Models\Product;
use App\Models\Seller;
use App\Models\User;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The storefront. No authentication.
 *
 * Every query here goes through `Seller::scopePublic()` and
 * `Product::scopePublic()`, which between them require an approved shop and a
 * published listing. Both checks are inside the query, so neither is one
 * somebody can forget - and a shop that has not been reviewed cannot put a
 * single product in front of a shopper.
 */
final class PublicProductController extends Controller
{
    /**
     * Shaped like the category listing rather than as `$shop->products()`, and
     * the reason is the generated contract rather than taste.
     *
     * A scope reached through a relation - `$shop->products()->public()` - goes
     * via Laravel's `__call` forwarding, which Scramble cannot follow. The
     * chain came out untyped, this endpoint alone published no `meta`, and the
     * frontend was told a paginated listing was a plain array. Starting from
     * the model keeps the scope on a builder, where it is legible to both the
     * reader and the generator.
     *
     * Nothing is lost: this is a public browse filtered by shop, exactly as the
     * category listing is a public browse filtered by category, and the rule
     * that matters is still inside `scopePublic()`.
     */
    #[QueryParameter('page', PaginatedCollection::PAGE_PARAMETER, type: 'int', default: 1)]
    public function index(string $shopSlug): PublicProductCollection
    {
        $products = Product::query()
            ->public()
            ->withRating()
            ->where('seller_id', $this->publicShop($shopSlug)->id)
            ->with(['variants', 'images', 'seller', 'category'])
            ->orderByDesc('published_at')
            ->paginate(24);

        return new PublicProductCollection($products);
    }

    /**
     * The listing's own page, which is the one place the caller's standing with
     * it matters: whether they may review it, and what they said if they have
     * (ADR 0047). A grid of cards asks neither, because nobody reviews from a
     * grid - so the answer costs one query here and none per card.
     */
    public function show(Request $request, string $shopSlug, string $productSlug, LeaveReview $reviews): JsonResponse
    {
        $seller = $this->publicShop($shopSlug);

        // 404, not 403, for a draft or a deleted listing. Saying "this product
        // is not published" would confirm that the seller has one under that
        // name, and a draft is nobody's business but the shop's.
        $product = $seller->products()
            ->public()
            ->withRating()
            ->with(['variants', 'images', 'seller', 'category'])
            ->where('slug', $productSlug)
            ->firstOrFail();

        $buyer = $request->user();

        if (! $buyer instanceof User) {
            return (new PublicProductResource($product))->response();
        }

        return (new PublicProductResource(
            $product,
            $reviews->isAllowedFor($buyer, $product),
            $buyer->reviews()->where('product_id', $product->id)->first(),
        ))->response();
    }

    /**
     * The one way into a shop from the storefront.
     *
     * `scopePublic()` is the only definition of what a shopper may see, and
     * putting it inside the lookup means neither method can forget it. An
     * unapproved shop is a 404 here rather than a 403: saying "awaiting review"
     * would tell anybody who guessed a slug that somebody applied under it
     * (ADR 0007).
     */
    private function publicShop(string $shopSlug): Seller
    {
        return Seller::query()->public()->where('slug', $shopSlug)->firstOrFail();
    }
}
