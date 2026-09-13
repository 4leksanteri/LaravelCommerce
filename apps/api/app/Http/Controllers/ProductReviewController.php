<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Reviews\LeaveReview;
use App\Actions\Reviews\ReviseReview;
use App\Http\Controllers\Concerns\ResolvesAuthenticatedUser;
use App\Http\Requests\Reviews\ReviewRequest;
use App\Http\Resources\PaginatedCollection;
use App\Http\Resources\ReviewCollection;
use App\Http\Resources\ReviewResource;
use App\Models\Product;
use App\Models\Review;
use App\Models\Seller;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;

/**
 * What people who bought a listing thought of it (ADR 0047).
 *
 * **Reading is public and writing needs a completed order.** The listing is
 * resolved through the storefront's own scopes either way, so a draft or an
 * unapproved shop answers 404 here exactly as it does everywhere else - and
 * nobody can review something that is not on sale.
 *
 * There is one review per buyer per listing, so the write endpoints read as a
 * singleton: `POST` leaves one and refuses a second with a 409, `PATCH` changes
 * the one you left. Neither takes an id, because yours is the only one you can
 * touch and the path already names the listing.
 */
final class ProductReviewController extends Controller
{
    use ResolvesAuthenticatedUser;

    private const string REFUSED = 'You cannot review this listing: nothing you have received is this, or you have reviewed it already.';

    #[QueryParameter('page', PaginatedCollection::PAGE_PARAMETER, type: 'int', default: 1)]
    public function index(string $shopSlug, string $productSlug): ReviewCollection
    {
        $product = $this->publicProduct($shopSlug, $productSlug);

        /*
         * Started from the model rather than `$product->reviews()`, for the
         * reason `SellerOrderController` gives at length: a relation forwards
         * through `__call`, which the OpenAPI generator cannot follow, and the
         * endpoint gets published without the `meta` a paged list needs.
         */
        $reviews = Review::query()
            ->where('product_id', $product->id)
            ->with('user')
            ->latest('id')
            ->paginate(20);

        return new ReviewCollection($reviews);
    }

    /**
     * @throws ModelNotFoundException<Product>
     */
    #[Response(status: 409, description: self::REFUSED, type: 'array{message: string}')]
    public function store(
        ReviewRequest $request,
        string $shopSlug,
        string $productSlug,
        LeaveReview $leave,
    ): JsonResponse {
        $review = $leave->handle(
            $this->authenticatedUser($request),
            $this->publicProduct($shopSlug, $productSlug),
            $request->rating(),
            $request->body(),
        );

        return (new ReviewResource($review->load('user')))->response()->setStatusCode(201);
    }

    /**
     * Changes what you said. Yours only, which the policy states and the lookup
     * already guarantees - a review is found through the caller's own.
     *
     * @throws ModelNotFoundException<Review>
     */
    public function update(
        ReviewRequest $request,
        string $shopSlug,
        string $productSlug,
        ReviseReview $revise,
    ): JsonResponse {
        $product = $this->publicProduct($shopSlug, $productSlug);

        $review = $this->authenticatedUser($request)
            ->reviews()
            ->where('product_id', $product->id)
            ->firstOrFail();

        $this->authorize('update', $review);

        return (new ReviewResource(
            $revise->handle($review, $request->rating(), $request->body())->load('user'),
        ))->response();
    }

    /**
     * The listing, through the storefront's rules.
     *
     * @throws ModelNotFoundException<Product>
     */
    private function publicProduct(string $shopSlug, string $productSlug): Product
    {
        $seller = Seller::query()->public()->where('slug', $shopSlug)->firstOrFail();

        return $seller->products()
            ->public()
            ->where('slug', $productSlug)
            ->firstOrFail();
    }
}
