<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Moderation\ReportContent;
use App\Http\Controllers\Concerns\ResolvesAuthenticatedUser;
use App\Http\Requests\Moderation\ReportContentRequest;
use App\Http\Resources\ReportResource;
use App\Models\Product;
use App\Models\Review;
use App\Models\Seller;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;

/**
 * Reporting something that should not be on the marketplace (ADR 0054).
 *
 * **What can be reported is what can be seen.** Both endpoints resolve their
 * subject through the storefront's own scopes - `Seller::scopePublic()` and
 * then `Product::scopePublic()` - so an unapproved shop's listing, a draft and
 * a review that has already been hidden are all 404 here exactly as they are
 * everywhere else. Nobody can report what is not in front of them, and that
 * rule is carried by the query rather than checked afterwards (ADR 0008).
 *
 * There is no policy: anybody signed in may report anything public, and a
 * `create` method nothing could refuse would be a rule nobody applies.
 *
 * **Nothing happens to the thing when a report is filed.** It stays on sale
 * until a person decides (`Admin\ReportReviewController`), because hiding on
 * report would hand anybody with two accounts the power to close a
 * competitor's shop window for as long as a queue takes.
 */
final class ReportController extends Controller
{
    use ResolvesAuthenticatedUser;

    private const string REFUSED = 'You have already reported this, and we are still looking at it.';

    /**
     * @throws ModelNotFoundException<Product>
     */
    #[Response(status: 409, description: self::REFUSED, type: 'array{message: string}')]
    public function listing(
        ReportContentRequest $request,
        string $shopSlug,
        string $productSlug,
        ReportContent $report,
    ): JsonResponse {
        $filed = $report->handle(
            $this->authenticatedUser($request),
            $this->publicProduct($shopSlug, $productSlug),
            $request->reason(),
            $request->note(),
        );

        return (new ReportResource($filed))->response()->setStatusCode(201);
    }

    /**
     * Reporting one review, by id.
     *
     * **The id is why this is not a singleton like the review endpoints.**
     * Those take none because the only review you can touch is your own; this
     * one is always about somebody else's, so the path has to name which.
     *
     * The review is resolved through its listing, so it inherits the same 404s
     * - and `visible()` means one already hidden cannot be reported again.
     *
     * @throws ModelNotFoundException<Product>
     */
    #[Response(status: 409, description: self::REFUSED, type: 'array{message: string}')]
    public function review(
        ReportContentRequest $request,
        string $shopSlug,
        string $productSlug,
        int $reviewId,
        ReportContent $report,
    ): JsonResponse {
        $product = $this->publicProduct($shopSlug, $productSlug);

        $review = Review::query()
            ->visible()
            ->where('product_id', $product->id)
            ->whereKey($reviewId)
            ->firstOrFail();

        $filed = $report->handle(
            $this->authenticatedUser($request),
            $review,
            $request->reason(),
            $request->note(),
        );

        return (new ReportResource($filed))->response()->setStatusCode(201);
    }

    /**
     * The listing, through the storefront's rules - the same lookup
     * `ProductReviewController` makes, and for the same reason.
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
