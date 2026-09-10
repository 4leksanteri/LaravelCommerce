<?php

declare(strict_types=1);

namespace App\Http\Controllers\Orders;

use App\Actions\Orders\CancelOrder;
use App\Actions\Orders\CompleteOrder;
use App\Enums\OrderParty;
use App\Http\Controllers\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderCollection;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A buyer's own orders, and the two things a buyer can do to one.
 *
 * Every query starts from `$user->orders()`, so somebody else's order is never
 * in it and there is no id here to substitute. That is ADR 0008's "let the
 * query carry it", and it is why there is still no `OrderPolicy`: with the two
 * audiences on separate routes, each scoped to its own relation, no permission
 * question is left for one to answer. ADR 0012 says what would bring one back.
 *
 * The seller's side of the same rows is `Sellers\SellerOrderController`, with a
 * different allowlist and a different set of transitions.
 */
final class OrderController extends Controller
{
    use ResolvesAuthenticatedUser;

    private const string CONFLICT = 'The order has moved on, and no longer allows this. `status` is where it is now.';

    private const string CONFLICT_BODY = 'array{message: string, status: \App\Enums\OrderStatus}';

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
        return (new OrderResource($this->order($request, $reference)))->response();
    }

    /**
     * Calls off an order nobody has committed to, and gives the stock back.
     *
     * Only while pending. Once the seller has accepted they may have set stock
     * aside or started work, and it becomes theirs alone to cancel - a 409
     * saying so, because the buyer is a party to this order and is simply late
     * (ADR 0008).
     *
     * @throws ModelNotFoundException<Order>
     */
    #[Response(status: 409, description: self::CONFLICT, type: self::CONFLICT_BODY)]
    public function cancel(Request $request, string $reference, CancelOrder $cancel): JsonResponse
    {
        $order = $cancel->handle($this->order($request, $reference), OrderParty::Buyer);

        return (new OrderResource($order->load(['items.variant.product', 'seller'])))->response();
    }

    /**
     * The buyer confirms they received it.
     *
     * Deliberately has no seller counterpart. Completion is what will release a
     * payout, and a seller who could complete their own order could declare
     * their own money releasable.
     *
     * @throws ModelNotFoundException<Order>
     */
    #[Response(status: 409, description: self::CONFLICT, type: self::CONFLICT_BODY)]
    public function complete(Request $request, string $reference, CompleteOrder $complete): JsonResponse
    {
        $order = $complete->handle($this->order($request, $reference));

        return (new OrderResource($order->load(['items.variant.product', 'seller'])))->response();
    }

    /**
     * @throws ModelNotFoundException<Order>
     */
    private function order(Request $request, string $reference): Order
    {
        return $this->authenticatedUser($request)
            ->orders()
            ->with(['items.variant.product', 'seller'])
            ->where('reference', $reference)
            ->firstOrFail();
    }
}
