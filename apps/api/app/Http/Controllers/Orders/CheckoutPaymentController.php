<?php

declare(strict_types=1);

namespace App\Http\Controllers\Orders;

use App\Actions\Payments\OpenPaymentsForCheckout;
use App\Actions\Payments\PayForCheckout;
use App\Http\Controllers\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\PayCheckoutRequest;
use App\Http\Resources\CheckoutPaymentResource;
use App\Models\Order;
use App\Models\User;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

/**
 * Paying for a basket.
 *
 * Addressed by the checkout's reference rather than an order's, because one
 * card pays for all of it: a basket spanning three shops is three orders and
 * three intents (ADR 0015), and asking a buyer to pay three times is the thing
 * this endpoint exists to avoid.
 *
 * **Scoped to the buyer's own orders**, so somebody else's reference and one
 * that never existed answer the same way - a 404, rather than a 403 that would
 * confirm the reference is real (ADR 0008).
 */
final class CheckoutPaymentController extends Controller
{
    use ResolvesAuthenticatedUser;

    private const string CONFLICT = 'The checkout has already been paid for, or has nothing left to pay.';

    /**
     * What is still owed on a checkout, and what the browser needs to pay it.
     *
     * Reading also creates any intent that is missing, so an order whose
     * checkout failed to reach Stripe after writing it is payable on the next
     * page load rather than stuck.
     */
    public function show(Request $request, string $reference, OpenPaymentsForCheckout $open): CheckoutPaymentResource
    {
        $buyer = $this->authenticatedUser($request);
        $orders = $this->ordersIn($buyer, $reference);

        $open->handle($buyer, $orders);

        return new CheckoutPaymentResource($reference, $orders->load('payment'));
    }

    /**
     * Pays for every outstanding order in the checkout with one card.
     *
     * The answer is the whole basket as it now stands, including anything
     * Stripe wants the browser to do next - a card needing authentication comes
     * back with its client secret rather than as an error.
     */
    #[Response(status: 409, description: self::CONFLICT, type: 'array{message: string}')]
    public function pay(PayCheckoutRequest $request, string $reference, PayForCheckout $pay): CheckoutPaymentResource
    {
        $buyer = $this->authenticatedUser($request);

        $pay->handle($buyer, $reference, $request->string('payment_method')->toString());

        return new CheckoutPaymentResource($reference, $this->ordersIn($buyer, $reference)->load('payment'));
    }

    /**
     * @return Collection<int, Order>
     *
     * @throws ModelNotFoundException<Order>
     */
    private function ordersIn(User $buyer, string $reference): Collection
    {
        /** @var Collection<int, Order> $orders */
        $orders = $buyer->orders()
            ->where('checkout_reference', $reference)
            ->with('payment')
            ->orderBy('id')
            ->get();

        if ($orders->isEmpty()) {
            throw (new ModelNotFoundException)->setModel(Order::class);
        }

        return $orders;
    }
}
