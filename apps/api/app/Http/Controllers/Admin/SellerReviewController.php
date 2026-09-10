<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Sellers\ApproveSeller;
use App\Actions\Sellers\RejectSeller;
use App\Enums\SellerStatus;
use App\Exceptions\SellerAlreadyReviewedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sellers\RejectSellerRequest;
use App\Http\Resources\SellerResource;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The review queue.
 *
 * Approving and rejecting are separate endpoints rather than a PATCH that sets
 * a status. Each is a different decision with different requirements - a
 * rejection carries a reason and an approval does not - and a status field a
 * client can set is a client that can set it to anything.
 */
final class SellerReviewController extends Controller
{
    /**
     * @return AnonymousResourceCollection<LengthAwarePaginator<int, Seller>>
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeStaff($request);

        $status = $request->query('status');

        $sellers = Seller::query()
            ->when(
                is_string($status) && in_array($status, SellerStatus::values(), true),
                fn ($query) => $query->where('status', $status),
            )
            // Oldest application first. A review queue that showed the newest
            // first would leave the person who has waited longest waiting.
            ->orderBy('applied_at')
            ->paginate(25);

        return SellerResource::collection($sellers);
    }

    public function approve(Request $request, Seller $seller, ApproveSeller $approve): JsonResponse
    {
        $this->authorize('review', $seller);

        try {
            $reviewed = $approve->handle($seller, $this->reviewer($request));
        } catch (SellerAlreadyReviewedException $exception) {
            throw new HttpException(409, $exception->getMessage(), $exception);
        }

        return (new SellerResource($reviewed))->response();
    }

    public function reject(RejectSellerRequest $request, Seller $seller, RejectSeller $reject): JsonResponse
    {
        $this->authorize('review', $seller);

        try {
            $reviewed = $reject->handle(
                $seller,
                $this->reviewer($request),
                $request->string('reason')->toString(),
            );
        } catch (SellerAlreadyReviewedException $exception) {
            // 409, not 422. The reviewer was allowed and sent something valid;
            // somebody else simply got there first.
            throw new HttpException(409, $exception->getMessage(), $exception);
        }

        return (new SellerResource($reviewed))->response();
    }

    /**
     * The listing has no single model to hang a policy on, so the check is
     * explicit. Every other method here goes through SellerPolicy::review.
     */
    private function authorizeStaff(Request $request): void
    {
        if (! $this->reviewer($request)->isPlatformStaff()) {
            throw new HttpException(403, 'Only platform staff may review shop applications.');
        }
    }

    private function reviewer(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new RuntimeException('The guard authenticated a request without a user.');
        }

        return $user;
    }
}
