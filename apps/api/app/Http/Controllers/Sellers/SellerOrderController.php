<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sellers;

use App\Actions\Orders\AcceptOrder;
use App\Actions\Orders\CancelOrder;
use App\Actions\Orders\ShipOrder;
use App\Enums\OrderParty;
use App\Http\Controllers\Concerns\ResolvesCurrentSeller;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaginatedCollection;
use App\Http\Resources\SellerOrderCollection;
use App\Http\Resources\SellerOrderResource;
use App\Models\Order;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What has been bought from this shop, and what the shop can do about it.
 *
 * Behind the `seller` middleware, and every query starts from
 * `$seller->orders()` - so another shop's orders are never in it, and a
 * reference that names one answers 404 rather than 403.
 *
 * **Three transitions here and deliberately not a fourth.** A seller accepts,
 * ships and cancels. A seller does not complete: confirming receipt is the
 * buyer's, because completion is what will release a payout (see
 * `CompleteOrder`). The absence is the rule.
 *
 * The buyer's side of the same rows is `Orders\OrderController`, with a
 * different allowlist - `checkout_reference` is on that one and not this one.
 */
final class SellerOrderController extends Controller
{
    use ResolvesCurrentSeller;

    private const string CONFLICT = 'The order has moved on, and no longer allows this. `status` is where it is now.';

    private const string CONFLICT_BODY = 'array{message: string, status: \App\Enums\OrderStatus}';

    #[QueryParameter('page', PaginatedCollection::PAGE_PARAMETER, type: 'int', default: 1)]
    public function index(Request $request): SellerOrderCollection
    {
        $orders = $this->currentSeller($request)
            ->orders()
            ->with(['items.variant.product', 'user'])
            ->latest('id')
            ->paginate(20);

        return new SellerOrderCollection($orders);
    }

    /**
     * @throws ModelNotFoundException<Order>
     */
    public function show(Request $request, string $reference): JsonResponse
    {
        return (new SellerOrderResource($this->order($request, $reference)))->response();
    }

    /**
     * The shop commits to fulfilling it.
     *
     * After this the buyer can no longer call it off on their own, which is why
     * it is a decision recorded at its own endpoint rather than a status field
     * a client sets.
     *
     * @throws ModelNotFoundException<Order>
     */
    #[Response(status: 409, description: self::CONFLICT, type: self::CONFLICT_BODY)]
    public function accept(Request $request, string $reference, AcceptOrder $accept): JsonResponse
    {
        return $this->respond($accept->handle($this->order($request, $reference)));
    }

    /**
     * @throws ModelNotFoundException<Order>
     */
    #[Response(status: 409, description: self::CONFLICT, type: self::CONFLICT_BODY)]
    public function ship(Request $request, string $reference, ShipOrder $ship): JsonResponse
    {
        return $this->respond($ship->handle($this->order($request, $reference)));
    }

    /**
     * The shop calls it off, and the stock goes back.
     *
     * A seller may do this later than a buyer may - right up until it ships -
     * because after acceptance they are the party who would be let down by it.
     *
     * @throws ModelNotFoundException<Order>
     */
    #[Response(status: 409, description: self::CONFLICT, type: self::CONFLICT_BODY)]
    public function cancel(Request $request, string $reference, CancelOrder $cancel): JsonResponse
    {
        return $this->respond(
            $cancel->handle($this->order($request, $reference), OrderParty::Seller),
        );
    }

    private function respond(Order $order): JsonResponse
    {
        return (new SellerOrderResource($order->load(['items.variant.product', 'user'])))->response();
    }

    /**
     * @throws ModelNotFoundException<Order>
     */
    private function order(Request $request, string $reference): Order
    {
        return $this->currentSeller($request)
            ->orders()
            ->with(['items.variant.product', 'user'])
            ->where('reference', $reference)
            ->firstOrFail();
    }
}
