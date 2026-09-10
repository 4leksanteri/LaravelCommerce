<?php

declare(strict_types=1);

namespace App\Http\Controllers\Orders;

use App\Http\Controllers\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderCollection;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A buyer's own orders.
 *
 * Every query starts from `$user->orders()`, so somebody else's order is never
 * in it and there is no id here to substitute. That is ADR 0008's "let the
 * query carry it", and it is why there is no `OrderPolicy` yet: a policy method
 * would have no decision left to make.
 *
 * The seller's view of the same orders is a different audience with a different
 * allowlist, and it is not built. That is the change that will bring a policy
 * with it.
 */
final class OrderController extends Controller
{
    use ResolvesAuthenticatedUser;

    public function index(Request $request): OrderCollection
    {
        $orders = $this->authenticatedUser($request)
            ->orders()
            ->with(['items.variant.product', 'seller'])
            ->latest('id')
            ->paginate(20);

        return new OrderCollection($orders);
    }

    /**
     * By reference, not by id.
     *
     * A sequential id in a URL publishes how many orders the marketplace has
     * taken. It is also the thing a buyer has in front of them, on the
     * confirmation they were shown.
     *
     * @throws ModelNotFoundException<Order> for an unknown reference, and for
     *                                       somebody else's - 404 rather than
     *                                       403, which would confirm it names
     *                                       a real order
     */
    public function show(Request $request, string $reference): JsonResponse
    {
        $order = $this->authenticatedUser($request)
            ->orders()
            ->with(['items.variant.product', 'seller'])
            ->where('reference', $reference)
            ->firstOrFail();

        return (new OrderResource($order))->response();
    }
}
