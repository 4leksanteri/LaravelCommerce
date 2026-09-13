<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Orders\ResolveDispute;
use App\Http\Controllers\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\ResolveDisputeRequest;
use App\Http\Resources\PaginatedCollection;
use App\Http\Resources\StaffDisputeCollection;
use App\Http\Resources\StaffDisputeResource;
use App\Models\Dispute;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The platform's dispute queue, and the decision (ADR 0051).
 *
 * Every method authorizes through `DisputePolicy`. There is deliberately no
 * `isPlatformStaff()` check in this class: the `admin` prefix is a URL, not an
 * authorization boundary, and a rule written here as well as in the policy is a
 * rule with two places to disagree.
 *
 * **Deciding one moves somebody's money**, which is why the policy also refuses
 * a member of staff who is a party to the order. A person deciding where their
 * own money goes is not a decision.
 */
final class DisputeReviewController extends Controller
{
    use ResolvesAuthenticatedUser;

    private const string REFUSED = 'This dispute has already been decided.';

    /**
     * What is waiting, oldest first.
     *
     * **Open ones only.** A queue is a list of things to do, and a decided
     * dispute is not one of them - it is read on the order it belongs to, where
     * both parties see it too. A history of decisions is a different screen and
     * is not built.
     *
     * Oldest first, because somebody has been waiting on their money since the
     * day they opened it.
     */
    #[QueryParameter('page', PaginatedCollection::PAGE_PARAMETER, type: 'int', default: 1)]
    public function index(Request $request): StaffDisputeCollection
    {
        // The listing has no single dispute to check against, which is what
        // `viewAny` is for.
        $this->authorize('viewAny', Dispute::class);

        $disputes = Dispute::query()
            ->whereNull('resolved_at')
            ->with(['order.user', 'order.seller'])
            ->oldest('id')
            ->paginate(25);

        return new StaffDisputeCollection($disputes);
    }

    /**
     * Refunded to the buyer, or released to the shop.
     *
     * A POST to the decision rather than a PATCH that sets a status, for the
     * reason approval and rejection are: these are decisions being recorded,
     * and a status field a client can set is a client that can set it to
     * anything.
     */
    #[Response(status: 409, description: self::REFUSED, type: 'array{message: string}')]
    public function resolve(
        ResolveDisputeRequest $request,
        Dispute $dispute,
        ResolveDispute $resolve,
    ): JsonResponse {
        $this->authorize('resolve', $dispute);

        $resolved = $resolve->handle(
            $dispute,
            $request->resolution(),
            $request->note(),
            $this->authenticatedUser($request),
        );

        return (new StaffDisputeResource($resolved->load(['order.user', 'order.seller'])))->response();
    }
}
