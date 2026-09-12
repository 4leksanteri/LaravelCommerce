<?php

declare(strict_types=1);

namespace App\Http\Controllers\Orders;

use App\Actions\Orders\PlaceOrders;
use App\Actions\Payments\OpenPaymentsForCheckout;
use App\Http\Controllers\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\CheckoutRequest;
use App\Http\Resources\PlacedOrderCollection;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Turning a cart into orders.
 *
 * The body carries one thing: which of the buyer's own addresses this goes to.
 *
 * ADR 0011 said checkout took no body at all, and the claim that mattered is
 * unchanged - **nothing a client sends contributes a figure to what somebody
 * will be charged.** The cart, the prices and the totals are still read from the
 * server under lock, and an address is not a figure.
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
    public function __invoke(
        CheckoutRequest $request,
        PlaceOrders $placeOrders,
        OpenPaymentsForCheckout $openPayments,
    ): JsonResponse {
        $buyer = $this->authenticatedUser($request);

        $orders = $placeOrders->handle($buyer, $request->integer('address_id'));

        /*
         * An intent per order, immediately, so the confirmation page has
         * something to pay with rather than waiting on a second round trip.
         *
         * Outside the checkout transaction on purpose: a call to Stripe inside
         * it would hold locks on every variant in the basket for the length of
         * a network round trip.
         *
         * **And outside the answer, too.** The orders are written and committed
         * by this point: somebody's basket has been bought, their stock is
         * taken and their cart is empty. Letting a Stripe failure out of here
         * would answer that with a 500, which reads as "nothing happened" and
         * is the one thing that is not true. The payment endpoint opens
         * whatever is missing on the next read, so the cost of this failing is
         * a round trip rather than an order nobody can pay for (ADR 0040).
         */
        try {
            $openPayments->handle($buyer, $orders);
        } catch (Throwable $failure) {
            report($failure);
        }

        return (new PlacedOrderCollection($orders))->response()->setStatusCode(201);
    }
}
