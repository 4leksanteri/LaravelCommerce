<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Appeals\DecideAppeal;
use App\Http\Controllers\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Appeals\DecideAppealRequest;
use App\Http\Resources\AppealCollection;
use App\Http\Resources\AppealResource;
use App\Http\Resources\PaginatedCollection;
use App\Models\Appeal;
use App\Models\Product;
use App\Models\Review;
use App\Models\Seller;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The platform's appeals queue, and the second look (ADR 0059).
 *
 * Every method authorizes through `AppealPolicy`. There is deliberately no
 * `isPlatformStaff()` check here: the `admin` prefix is a URL rather than an
 * authorization boundary, and a rule written here as well as in the policy is a
 * rule with two places to disagree.
 *
 * **Upholding one is the only undo there is.** No endpoint anywhere else puts a
 * listing back or unhides a review, so every reversal answers somebody's
 * argument and carries a decision somebody recorded - which is what ADR 0054
 * left open when it made taking things down one-way.
 */
final class AppealReviewController extends Controller
{
    use ResolvesAuthenticatedUser;

    private const string REFUSED = 'This appeal has already been decided, or what it was about no longer exists.';

    /**
     * What is waiting, oldest first.
     *
     * **Open ones only**, as the other two queues are: a queue is a list of
     * things to do, and a decided appeal is not one. Oldest first, because
     * somebody whose shop is stopped is losing money for every hour it waits.
     */
    #[QueryParameter('page', PaginatedCollection::PAGE_PARAMETER, type: 'int', default: 1)]
    public function index(Request $request): AppealCollection
    {
        $this->authorize('viewAny', Appeal::class);

        $appeals = Appeal::query()
            ->whereNull('reviewed_at')
            ->oldest('id')
            ->paginate(25);

        /*
         * `AppealResource` summarises each subject and reaches for the shop to
         * build its link - a listing's seller, and a review's listing and that
         * listing's seller. Left to lazy loading those fire per row.
         *
         * A MorphTo cannot be loaded through in one query, so the nested
         * relations are named per type; all three appealable things are here.
         * Reached through `getCollection()` because the paginator only forwards
         * `loadMorph` by `__call`, which the analyser cannot follow.
         */
        $appeals->getCollection()->loadMorph('appealable', [
            Seller::class => [],
            Product::class => ['seller'],
            Review::class => ['product.seller'],
        ]);

        return new AppealCollection($appeals);
    }

    /**
     * Upheld, or dismissed.
     *
     * A POST to the decision rather than a PATCH setting a field, for the same
     * reason every other decision here is: a status a client can set is one it
     * can set to anything, and this one puts somebody's shop back on the
     * marketplace.
     */
    #[Response(status: 409, description: self::REFUSED, type: 'array{message: string}')]
    public function decide(
        DecideAppealRequest $request,
        Appeal $appeal,
        DecideAppeal $decide,
    ): JsonResponse {
        $this->authorize('decide', $appeal);

        $decided = $decide->handle(
            $appeal,
            $request->upheld(),
            $request->note(),
            $this->authenticatedUser($request),
        );

        return (new AppealResource($decided->load('appealable')))->response();
    }
}
