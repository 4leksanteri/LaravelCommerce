<?php

declare(strict_types=1);

namespace App\Http\Controllers\Orders;

use App\Actions\Orders\MarkOrderMessagesRead;
use App\Actions\Orders\SendOrderMessage;
use App\Enums\OrderParty;
use App\Http\Controllers\Concerns\ResolvesAuthenticatedUser;
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
 * The buyer's side of one order's conversation (ADR 0050).
 *
 * Every query starts from `$user->orders()`, so a reference naming somebody
 * else's order answers 404 and there is nothing here for a caller to
 * substitute. That is the same ownership rule `OrderController` states, and it
 * is why messages need no policy: being a party to the order is the whole
 * permission, and the relation already carries it.
 *
 * The sender is `OrderParty::Buyer` because this is the buyer's route. It is
 * never read from the payload - see `SendMessageRequest`.
 *
 * The shop's side of the same rows is `Sellers\SellerOrderMessageController`.
 */
final class OrderMessageController extends Controller
{
    use ResolvesAuthenticatedUser;

    /**
     * Oldest first, which is the order they were said in.
     *
     * A conversation read newest-first is not one, and these are short enough
     * that the first page is almost always the whole of it.
     *
     * Started from the model rather than `$order->messages()`, for the reason
     * `SellerOrderController` gives at length: a relation forwards through
     * `__call`, which the OpenAPI generator cannot follow, and the endpoint gets
     * published without the `meta` a paged list needs.
     *
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
            OrderParty::Buyer,
            $request->body(),
        );

        return (new OrderMessageResource($message))->response()->setStatusCode(201);
    }

    /**
     * The buyer has read what the shop said.
     *
     * Its own endpoint rather than a side effect of `index`, because a GET does
     * not change anything (root `CLAUDE.md` section 9).
     *
     * @throws ModelNotFoundException<Order>
     */
    public function read(
        Request $request,
        string $reference,
        MarkOrderMessagesRead $markRead,
    ): JsonResponse {
        $markRead->handle($this->order($request, $reference), OrderParty::Buyer);

        return response()->json(null, 204);
    }

    /**
     * @throws ModelNotFoundException<Order>
     */
    private function order(Request $request, string $reference): Order
    {
        return $this->authenticatedUser($request)
            ->orders()

            // Both sides of the order, for the mail that tells whoever did not
            // write it (ADR 0035).
            ->with(['seller.user', 'user'])

            ->where('reference', $reference)
            ->firstOrFail();
    }
}
