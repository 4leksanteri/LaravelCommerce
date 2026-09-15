<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Appeals\RaiseAppeal;
use App\Http\Controllers\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Concerns\ResolvesCurrentSeller;
use App\Http\Requests\Appeals\RaiseAppealRequest;
use App\Http\Resources\AppealResource;
use App\Models\Product;
use App\Models\Review;
use App\Models\Seller;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;

/**
 * Answering back about a decision the platform took (ADR 0059).
 *
 * **Three endpoints rather than one taking a type and an id**, and that is the
 * whole of the authorization. Each resolves its subject through the caller's
 * own relations - the shop the `seller` middleware gave us, a listing the
 * policy says is theirs, the review they wrote - so there is nothing a caller
 * could substitute to appeal on somebody else's behalf, and no policy to ask
 * (ADR 0008).
 *
 * **Raising one changes nothing.** The shop stays stopped and the listing stays
 * down until a person decides. `RaiseAppeal` says why.
 */
final class AppealController extends Controller
{
    use ResolvesAuthenticatedUser;
    use ResolvesCurrentSeller;

    private const string REFUSED = 'There is nothing to appeal, or an appeal is already open.';

    /**
     * A suspended shop answering back.
     *
     * Behind the `seller` middleware, which admits a suspended shop
     * deliberately (ADR 0052): a shop that has been stopped still owes what it
     * sold, and now also needs somewhere to argue from.
     */
    #[Response(status: 409, description: self::REFUSED, type: 'array{message: string}')]
    public function shop(RaiseAppealRequest $request, RaiseAppeal $raise): JsonResponse
    {
        $appeal = $raise->handle(
            $this->authenticatedUser($request),
            $this->currentSeller($request),
            $request->reason(),
        );

        return (new AppealResource($appeal))->response()->setStatusCode(201);
    }

    /**
     * A shop answering back about one of its listings.
     *
     * The policy answers 403 for another shop's listing, exactly as every other
     * seller-side product route does.
     */
    #[Response(status: 409, description: self::REFUSED, type: 'array{message: string}')]
    public function listing(RaiseAppealRequest $request, Product $product, RaiseAppeal $raise): JsonResponse
    {
        $this->authorize('update', $product);

        $appeal = $raise->handle(
            $this->authenticatedUser($request),
            $product,
            $request->reason(),
        );

        return (new AppealResource($appeal))->response()->setStatusCode(201);
    }

    /**
     * Somebody answering back about their own hidden review.
     *
     * Resolved through `$user->reviews()`, which carries no visibility
     * condition - so a hidden review is still its author's to find, which is
     * what ADR 0054 promised when it said the author keeps their words.
     *
     * @throws ModelNotFoundException<Review>
     */
    #[Response(status: 409, description: self::REFUSED, type: 'array{message: string}')]
    public function review(
        RaiseAppealRequest $request,
        string $shopSlug,
        string $productSlug,
        RaiseAppeal $raise,
    ): JsonResponse {
        $author = $this->authenticatedUser($request);

        /*
         * Resolved in typed steps rather than through a `whereHas` closure,
         * where the analyser is handed an ungeneric `Builder` and cannot check
         * a column name - the failure ADR 0050 records `LeaveReview` hitting.
         *
         * **And deliberately not through `scopePublic`.** Every other route
         * that takes these two slugs resolves the listing through the
         * storefront's rules, and doing so here would 404 exactly when somebody
         * needs this most: a review is worth appealing when its listing has been
         * removed or its shop suspended, and neither is public any more.
         */
        $shop = Seller::query()->where('slug', $shopSlug)->firstOrFail();
        $product = $shop->products()->where('slug', $productSlug)->firstOrFail();

        $review = $author->reviews()->where('product_id', $product->id)->firstOrFail();

        $appeal = $raise->handle($author, $review, $request->reason());

        return (new AppealResource($appeal))->response()->setStatusCode(201);
    }
}
