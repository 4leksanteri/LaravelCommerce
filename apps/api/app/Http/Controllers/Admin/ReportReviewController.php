<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Moderation\DecideReport;
use App\Http\Controllers\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Moderation\DecideReportRequest;
use App\Http\Resources\PaginatedCollection;
use App\Http\Resources\ReportCollection;
use App\Http\Resources\ReportResource;
use App\Models\Product;
use App\Models\Report;
use App\Models\Review;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The platform's moderation queue, and the decision (ADR 0054).
 *
 * Every method authorizes through `ReportPolicy`. There is deliberately no
 * `isPlatformStaff()` check in this class: the `admin` prefix is a URL rather
 * than an authorization boundary, and a rule written here as well as in the
 * policy is a rule with two places to disagree.
 *
 * **Upholding a report is the takedown.** There is no separate endpoint for
 * removing a listing, so every removal answers a report and carries the reason
 * somebody gave - which is what keeps moderation accountable rather than
 * merely possible.
 */
final class ReportReviewController extends Controller
{
    use ResolvesAuthenticatedUser;

    private const string REFUSED = 'This report has already been decided, or what it was about no longer exists.';

    /**
     * What is waiting, oldest first.
     *
     * **Open ones only**, as the dispute queue is: a queue is a list of things
     * to do, and a decided report is not one. Oldest first, because something
     * flagged as counterfeit has been on sale for every hour it waits.
     *
     * The subject is eager-loaded through the morph so the page can summarise
     * it, and a subject that has gone is a null the resource reports rather
     * than a blank row.
     */
    #[QueryParameter('page', PaginatedCollection::PAGE_PARAMETER, type: 'int', default: 1)]
    public function index(Request $request): ReportCollection
    {
        $this->authorize('viewAny', Report::class);

        $reports = Report::query()
            ->whereNull('reviewed_at')
            ->oldest('id')
            ->paginate(25);

        /*
         * `ReportResource` summarises each subject and reaches the shop to
         * build its link - a listing's seller, and a review's listing and that
         * listing's seller. Left to lazy loading those fire per row, which on a
         * queue is the whole page.
         *
         * A MorphTo cannot be loaded through in a single query, so the nested
         * relations are named per type; the two here are the two things that
         * are reportable today.
         *
         * Done after the page rather than as a closure inside `with()`, which
         * has to narrow its parameter to MorphTo and so cannot satisfy a
         * signature promising any Relation. `loadMorph` takes a plain array and
         * is the API built for exactly this. It is reached through
         * `getCollection()` because the paginator only forwards it by `__call`
         * - the same forwarding Scramble cannot follow.
         */
        $reports->getCollection()->loadMorph('reportable', [
            Product::class => ['seller'],
            Review::class => ['product.seller'],
        ]);

        return new ReportCollection($reports);
    }

    /**
     * Upheld, or dismissed.
     *
     * A POST to the decision rather than a PATCH setting a field, for the same
     * reason approving a shop is: a status a client can set is one it can set
     * to anything, and this one takes somebody's listing off sale.
     */
    #[Response(status: 409, description: self::REFUSED, type: 'array{message: string}')]
    public function decide(
        DecideReportRequest $request,
        Report $report,
        DecideReport $decide,
    ): JsonResponse {
        $this->authorize('decide', $report);

        $decided = $decide->handle(
            $report,
            $request->upheld(),
            $request->note(),
            $this->authenticatedUser($request),
        );

        return (new ReportResource($decided->load('reportable')))->response();
    }
}
