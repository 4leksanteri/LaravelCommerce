<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sellers;

use App\Actions\Orders\MarkOrderMessagesRead;
use App\Actions\Orders\SendOrderMessage;
use App\Enums\OrderParty;
use App\Http\Controllers\Concerns\ResolvesCurrentSeller;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\SendMessageRequest;
use App\Http\Resources\OrderMessageCollection;
use App\Http\Resources\OrderMessageResource;
use App\Http\Resources\PaginatedCollection;
use App\Models\Order;
use App\Models\OrderMessage;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The shop's side of one order's conversation (ADR 0050).
 *
 * Behind the `seller` middleware, and every query starts from
 * `$seller->orders()->paid()` - so another shop's order answers 404, and so
 * does an unpaid one, which to this shop does not exist yet (ADR 0042). A shop
 * cannot be written to about an order it has never been shown.
 *
 * The sender is `OrderParty::Seller` because this is the shop's route, and is
 * never read from the payload.
 *
 * The buyer's side of the same rows is `Orders\OrderMessageController`.
 */
final class SellerOrderMessageController extends Controller
{
    use ResolvesCurrentSeller;

    /**
     * @throws ModelNotFoundException<Order>
     */
    #[QueryParameter('page', PaginatedCollection::PAGE_PARAMETER, type: 'int', default: 1)]
    public function index(Request $request, string $reference): OrderMessageCollection
    {
        $order = $this->order($request, $reference);

        return new OrderMessageCollection(
            OrderMessage::query()
                ->where('order_id', $order->id)
                ->oldest('id')
                ->paginate(50),
        );
    }

    /**
     * @throws ModelNotFoundException<Order>
     */
    public function store(
        SendMessageRequest $request,
        string $reference,
        SendOrderMessage $send,
    ): JsonResponse {
        $message = $send->handle(
            $this->order($request, $reference),
            OrderParty::Seller,
            $request->body(),
        );

        return (new OrderMessageResource($message))->response()->setStatusCode(201);
    }

    /**
     * @throws ModelNotFoundException<Order>
     */
    public function read(
        Request $request,
        string $reference,
        MarkOrderMessagesRead $markRead,
    ): JsonResponse {
        $markRead->handle($this->order($request, $reference), OrderParty::Seller);

        return response()->json(null, 204);
    }

    /**
     * @throws ModelNotFoundException<Order>
     */
    private function order(Request $request, string $reference): Order
    {
        return $this->currentSeller($request)
            ->orders()

            // The same narrowing the queue uses, so an unpaid order answers 404
            // here too (ADR 0042).
            ->paid()

            ->with(['seller.user', 'user'])
            ->where('reference', $reference)
            ->firstOrFail();
    }
}
