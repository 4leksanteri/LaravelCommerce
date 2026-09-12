<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Enums\PaymentStatus;
use App\Exceptions\CheckoutNotPayableException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * Pays for every order in one checkout, with one card.
 *
 * The buyer's browser turns a card into a payment method - card details never
 * reach this application - and hands the id here. The first intent is confirmed
 * with it on-session, which is what saves the card against the buyer's
 * customer; the rest are confirmed against that saved card off-session, so a
 * basket spanning three shops is one card entry rather than three (ADR 0040).
 *
 * **The first one has to land before the others are attempted.** A card that
 * needs 3DS comes back `requires_action`, and nothing can be charged against a
 * card that has not been authenticated yet. So confirmation stops there, the
 * browser authenticates with the client secret it was given, and calls this
 * again - which resumes, because every payment already succeeded is skipped.
 *
 * **A refusal is not an exception here.** Stripe throws for a declined card,
 * and a decline is an answer rather than a failure of this application: the
 * intent is fetched, the row is brought into line with it, and the buyer is
 * shown Stripe's own words. The 500 that an uncaught exception would produce
 * says nothing anybody can act on.
 */
final class PayForCheckout
{
    public function __construct(
        private readonly StripeClient $stripe,
        private readonly OpenPaymentsForCheckout $open,
        private readonly SyncPayment $sync,
    ) {}

    /**
     * @return EloquentCollection<int, Payment> every payment in the checkout,
     *                                          as it now stands
     *
     * @throws CheckoutNotPayableException
     * @throws ModelNotFoundException<Order> when the checkout is not this buyer's
     */
    public function handle(User $buyer, string $checkoutReference, string $paymentMethodId): EloquentCollection
    {
        $orders = $this->ordersIn($buyer, $checkoutReference);

        // An order whose intent was never created - a checkout that failed to
        // reach Stripe after writing its orders - gets one here rather than
        // being unpayable forever.
        $this->open->handle($buyer, $orders);

        $payments = $orders
            ->map(static fn (Order $order): ?Payment => $order->payment)
            ->filter()
            ->values();

        /** @var EloquentCollection<int, Payment> $payments */
        $payments = new EloquentCollection($payments->all());

        $this->refuseWhenSettled($payments);

        $first = true;

        foreach ($payments as $payment) {
            if ($payment->status->isPaid() || $payment->status === PaymentStatus::Processing) {
                continue;
            }

            $confirmed = $this->confirm($payment, $paymentMethodId, onSession: $first);
            $first = false;

            // The card needs authenticating, or was refused. Either way the
            // browser has something to do before anything else can be charged
            // against it.
            if (! $confirmed->status->isPaid()) {
                break;
            }
        }

        return $payments;
    }

    /**
     * @param  EloquentCollection<int, Payment>  $payments
     *
     * @throws CheckoutNotPayableException
     */
    private function refuseWhenSettled(EloquentCollection $payments): void
    {
        if ($payments->isEmpty()) {
            throw CheckoutNotPayableException::nothingToPay();
        }

        $outstanding = $payments->reject(
            static fn (Payment $payment): bool => $payment->status->isPaid(),
        );

        if ($outstanding->isEmpty()) {
            throw CheckoutNotPayableException::alreadyPaid();
        }
    }

    private function confirm(Payment $payment, string $paymentMethodId, bool $onSession): Payment
    {
        $parameters = ['payment_method' => $paymentMethodId];

        if (! $onSession) {
            // The buyer is no longer at the keyboard for these: the card was
            // entered once, for the first order.
            $parameters['off_session'] = true;
        }

        try {
            $intent = $this->stripe->paymentIntents->confirm(
                $payment->stripe_payment_intent_id,
                $parameters,
            );

            return $this->sync->handle($payment, $intent);
        } catch (ApiErrorException) {
            /*
             * A decline, an authentication Stripe wants, or an intent that
             * moved underneath this request. The intent itself carries which,
             * so it is read back rather than guessed at from the exception.
             */
            return $this->sync->handle($payment);
        }
    }

    /**
     * @return EloquentCollection<int, Order>
     *
     * @throws ModelNotFoundException<Order>
     */
    private function ordersIn(User $buyer, string $checkoutReference): EloquentCollection
    {
        /** @var EloquentCollection<int, Order> $orders */
        $orders = $buyer->orders()
            ->where('checkout_reference', $checkoutReference)
            ->with('payment')
            ->orderBy('id')
            ->get();

        if ($orders->isEmpty()) {
            // Somebody else's checkout and one that never existed answer the
            // same way, which is the point (ADR 0008).
            throw (new ModelNotFoundException)->setModel(Order::class);
        }

        return $orders;
    }
}
