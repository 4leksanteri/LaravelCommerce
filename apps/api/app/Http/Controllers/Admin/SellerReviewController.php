<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Sellers\ApproveSeller;
use App\Actions\Sellers\ReinstateShop;
use App\Actions\Sellers\RejectSeller;
use App\Actions\Sellers\SuspendShop;
use App\Enums\SellerStatus;
use App\Http\Controllers\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sellers\ListSellersRequest;
use App\Http\Requests\Sellers\RejectSellerRequest;
use App\Http\Requests\Sellers\SuspendShopRequest;
use App\Http\Resources\PaginatedCollection;
use App\Http\Resources\SellerCollection;
use App\Http\Resources\SellerResource;
use App\Models\Seller;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The review queue.
 *
 * Approving and rejecting are separate endpoints rather than a PATCH that sets
 * a status. Each is a different decision with different requirements - a
 * rejection carries a reason and an approval does not - and a status field a
 * client can set is a client that can set it to anything.
 *
 * Every method authorizes through `SellerPolicy`. There is deliberately no
 * `isPlatformStaff()` check in this class: the prefix `admin` is a URL, not an
 * authorization boundary, and a rule written here as well as in the policy is
 * a rule with two places to disagree.
 *
 * Losing a race to another reviewer raises SellerAlreadyReviewedException,
 * which bootstrap/app.php renders as 409. That is why there is no try/catch.
 */
final class SellerReviewController extends Controller
{
    use ResolvesAuthenticatedUser;

    private const string NOT_TRADING = 'Only an open shop can be suspended. `status` is where this one is.';

    private const string NOT_SUSPENDED = 'This shop is not suspended. `status` is where it is.';

    #[QueryParameter('page', PaginatedCollection::PAGE_PARAMETER, type: 'int', default: 1)]
    public function index(ListSellersRequest $request): SellerCollection
    {
        // The listing has no single shop to check against, which is what
        // `viewAny` is for.
        $this->authorize('viewAny', Seller::class);

        $status = $request->status();

        $sellers = Seller::query()
            ->when(
                $status instanceof SellerStatus,
                fn ($query) => $query->where('status', $status),
            )
            // Oldest application first. A review queue that showed the newest
            // first would leave the person who has waited longest waiting.
            ->orderBy('applied_at')
            ->paginate(25);

        return new SellerCollection($sellers);
    }

    /**
     * One shop, read by staff (ADR 0060).
     *
     * It arrives with the page that needed it: a shop's record has to be able
     * to name the shop it belongs to, and the queue was the only other place
     * staff could read one from - which would mean paging through a list to
     * find a shop whose id is already in the URL.
     *
     * `view` rather than `review`, and the difference is deliberate. Reading is
     * not deciding, so this one does not refuse somebody their own shop.
     */
    public function show(Request $request, Seller $seller): JsonResponse
    {
        $this->authorize('view', $seller);

        return (new SellerResource($seller))->response();
    }

    public function approve(Request $request, Seller $seller, ApproveSeller $approve): JsonResponse
    {
        $this->authorize('review', $seller);

        return (new SellerResource(
            $approve->handle($seller, $this->authenticatedUser($request))
        ))->response();
    }

    public function reject(RejectSellerRequest $request, Seller $seller, RejectSeller $reject): JsonResponse
    {
        $this->authorize('review', $seller);

        return (new SellerResource(
            $reject->handle(
                $seller,
                $this->authenticatedUser($request),
                $request->string('reason')->toString(),
            )
        ))->response();
    }

    /**
     * Stops a trading shop (ADR 0052).
     *
     * A different decision from a review, and a different policy question:
     * reviewing settles an application, and this settles what happens to a
     * business already running. Only an open shop can be stopped, which is the
     * action's rule and reaches HTTP as a 409.
     */
    #[Response(status: 409, description: self::NOT_TRADING, type: 'array{message: string, status: \App\Enums\SellerStatus}')]
    public function suspend(SuspendShopRequest $request, Seller $seller, SuspendShop $suspend): JsonResponse
    {
        $this->authorize('suspend', $seller);

        return (new SellerResource(
            $suspend->handle(
                $seller,
                $this->authenticatedUser($request),
                $request->string('reason')->toString(),
            )
        ))->response();
    }

    /**
     * Lets a suspended shop trade again.
     *
     * No reason is collected: lifting a suspension needs no justification to
     * the shop, which only ever needed to know why it was stopped.
     */
    #[Response(status: 409, description: self::NOT_SUSPENDED, type: 'array{message: string, status: \App\Enums\SellerStatus}')]
    public function reinstate(Request $request, Seller $seller, ReinstateShop $reinstate): JsonResponse
    {
        $this->authorize('suspend', $seller);

        return (new SellerResource(
            $reinstate->handle($seller, $this->authenticatedUser($request))
        ))->response();
    }
}
