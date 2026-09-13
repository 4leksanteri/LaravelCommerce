<?php

declare(strict_types=1);

namespace App\Http\Controllers\Orders;

use App\Actions\Orders\OpenDispute;
use App\Http\Controllers\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\OpenDisputeRequest;
use App\Http\Resources\DisputeResource;
use App\Models\Order;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A buyer says something went wrong with an order (ADR 0051).
 *
 * **One endpoint, and deliberately no more.** There is nothing to list - a
 * buyer has at most one dispute per order and reads it on that order - and
 * nothing to withdraw: money is held while it is open, and letting the person
 * who opened it also close it would make "resolved" mean two different things.
 * The platform decides, at `Admin\DisputeReviewController`.
 *
 * The order is resolved through `$user->orders()`, so somebody else's reference
 * is a 404 and being a party to it is the whole permission. That is why there
 * is no policy on this side.
 */
final class DisputeController extends Controller
{
    use ResolvesAuthenticatedUser;

    private const string REFUSED = 'This order cannot be disputed: it has not been sent yet, its money has already been settled, or it has been disputed already.';

    /**
     * @throws ModelNotFoundException<Order>
     */
    #[Response(status: 409, description: self::REFUSED, type: 'array{message: string}')]
    public function store(
        OpenDisputeRequest $request,
        string $reference,
        OpenDispute $open,
    ): JsonResponse {
        $dispute = $open->handle(
            $this->order($request, $reference),
            $request->reason(),
        );

        return (new DisputeResource($dispute))->response()->setStatusCode(201);
    }

    /**
     * @throws ModelNotFoundException<Order>
     */
    private function order(Request $request, string $reference): Order
    {
        return $this->authenticatedUser($request)
            ->orders()

            // The payment decides whether there is anything to dispute, and the
            // shop's owner is who hears about it.
            ->with(['payment', 'seller.user', 'user'])

            ->where('reference', $reference)
            ->firstOrFail();
    }
}
