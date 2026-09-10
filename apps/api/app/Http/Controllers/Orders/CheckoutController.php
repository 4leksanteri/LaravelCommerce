<?php

declare(strict_types=1);

namespace App\Http\Controllers\Orders;

use App\Actions\Orders\PlaceOrders;
use App\Http\Controllers\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderCollection;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Turning a cart into orders.
 *
 * There is no request body, and that is the point. The cart is on the server,
 * the prices are on the server, and the totals are summed from what the
 * catalogue says under lock - nothing a client sends contributes a figure to
 * what somebody will be charged.
 *
 * The response is the orders that were created, so a client needs no second
 * request to show what it just did.
 */
final class CheckoutController extends Controller
{
    use ResolvesAuthenticatedUser;

    private const string CONFLICT = 'The cart is empty, or some of it can no longer be bought. Nothing was ordered.';

    /**
     * `items` names the lines that blocked it, so they can be marked in place.
     * Empty when the cart itself was.
     */
    private const string CONFLICT_BODY = 'array{message: string, items: list<array{id: int, product_name: string, variant_name: string, availability: \App\Enums\CartItemAvailability, available: int|null}>}';

    #[Response(status: 409, description: self::CONFLICT, type: self::CONFLICT_BODY)]
    public function __invoke(Request $request, PlaceOrders $placeOrders): JsonResponse
    {
        $orders = $placeOrders->handle($this->authenticatedUser($request));

        return (new OrderCollection($orders))->response()->setStatusCode(201);
    }
}
