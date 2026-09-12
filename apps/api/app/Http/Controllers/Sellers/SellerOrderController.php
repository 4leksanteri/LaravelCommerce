<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sellers;

use App\Actions\Orders\AcceptOrder;
use App\Actions\Orders\CancelOrder;
use App\Actions\Orders\ShipOrder;
use App\Enums\OrderParty;
use App\Enums\OrderStatus;
use App\Http\Controllers\Concerns\ResolvesCurrentSeller;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\CancelSellerOrderRequest;
use App\Http\Requests\Orders\ListSellerOrdersRequest;
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
    public function index(ListSellerOrdersRequest $request): SellerOrderCollection
    {
        /*
         * Started from the model rather than from `$seller->orders()`, and that
         * is not a style choice. A scope called on a relation forwards through
         * Laravel's `__call`, which the OpenAPI generator cannot follow: adding
         * `paid()` to the relation published this endpoint as an unpaginated
         * array - no `meta`, and a frontend that reads `meta.total` - while
         * working perfectly at runtime. `apps/api/CLAUDE.md` section 4 names
         * this trap; filtering by the foreign key types cleanly.
         *
         * An order nobody has paid for is not this shop's work yet, and is not
         * shown here at all (ADR 0042): it holds stock for minutes and then
         * expires, and a shop that accepted one would be committing to
         * something that may never be paid for.
         */
        $orders = Order::query()
            ->where('seller_id', $this->currentSeller($request)->id)
            ->paid()
            ->with(['items.variant.product', 'user', 'payment'])
            ->latest('id');

        // Narrowed to one status when asked: what is waiting to be accepted, or
        // to be sent (ADR 0036). Still inside this shop's own orders.
        $status = $request->status();

        if ($status instanceof OrderStatus) {
            $orders->where('status', $status);
        }

        return new SellerOrderCollection($orders->paginate(20));
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
    public function cancel(CancelSellerOrderRequest $request, string $reference, CancelOrder $cancel): JsonResponse
    {
        return $this->respond(
            $cancel->handle(
                $this->order($request, $reference),
                OrderParty::Seller,
                // Shown to the buyer, on the order and in the mail that tells
                // them it was called off (ADR 0035).
                $request->string('reason')->toString(),
            ),
        );
    }

    private function respond(Order $order): JsonResponse
    {
        return (new SellerOrderResource($order->load(['items.variant.product', 'user', 'payment'])))->response();
    }

    /**
     * @throws ModelNotFoundException<Order>
     */
    private function order(Request $request, string $reference): Order
    {
        return $this->currentSeller($request)
            ->orders()

            // The same narrowing as the queue, so an unpaid order answers 404
            // here too - to this shop it does not exist yet (ADR 0042).
            ->paid()

            ->with(['items.variant.product', 'user', 'payment'])
            ->where('reference', $reference)
            ->firstOrFail();
    }
}
