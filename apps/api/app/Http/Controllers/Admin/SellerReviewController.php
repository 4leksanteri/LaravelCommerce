<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Sellers\ApproveSeller;
use App\Actions\Sellers\RejectSeller;
use App\Enums\SellerStatus;
use App\Http\Controllers\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sellers\ListSellersRequest;
use App\Http\Requests\Sellers\RejectSellerRequest;
use App\Http\Resources\PaginatedCollection;
use App\Http\Resources\SellerCollection;
use App\Http\Resources\SellerResource;
use App\Models\Seller;
use Dedoc\Scramble\Attributes\QueryParameter;
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
}
