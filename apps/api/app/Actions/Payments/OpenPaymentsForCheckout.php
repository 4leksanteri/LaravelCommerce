<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Collection;
use Stripe\StripeClient;

/**
 * Makes sure every order in a checkout has an intent to be paid through.
 *
 * **One PaymentIntent per order** (ADR 0015): a basket spanning three shops is
 * three orders in three currencies, and a PaymentIntent has exactly one
 * currency. The amount is the order's own total, read from the row that
 * snapshotted it at checkout - nothing a client sends contributes a figure.
 *
 * **Idempotent, and twice over.** An order that already has a payment is left
 * alone, and each creation carries an idempotency key derived from the order's
 * reference, so a retry after a network failure gets the same intent back
 * rather than making a second one. That matters more here than anywhere else
 * in this application: a duplicate intent is a second way to charge somebody.
 *
 * **The buyer gets a Stripe customer the first time they pay.** A payment
 * method cannot be reused without one, and reusing it is what lets a basket be
 * paid for with one card entry. An account that never buys anything never
 * appears in Stripe.
 *
 * Called after checkout has written its orders, and again by the payment
 * endpoint, so an intent that failed to be created at checkout is made on the
 * next read rather than leaving an order that can never be paid.
 */
final class OpenPaymentsForCheckout
{
    public function __construct(private readonly StripeClient $stripe) {}

    /**
     * Takes a plain collection rather than an Eloquent one, because checkout
     * hands over what `PlaceOrders` returned and the payment endpoint hands
     * over what it queried. Both are collections of orders in one checkout.
     *
     * @param  Collection<int, Order>  $orders  every order in one checkout
     * @return Collection<int, Payment>
     */
    public function handle(User $buyer, Collection $orders): Collection
    {
        /** @var Collection<int, Payment> $payments */
        $payments = new Collection;

        $missing = $orders->filter(static fn (Order $order): bool => $order->payment === null);

        // Nothing to open, so nothing is asked of Stripe. Reading a basket that
        // is already paid for should not create a customer, and a buyer who
        // never gets that far should not exist at Stripe at all.
        if ($missing->isEmpty()) {
            foreach ($orders as $order) {
                if ($order->payment instanceof Payment) {
                    $payments->push($order->payment);
                }
            }

            return $payments;
        }

        $customerId = $this->customerFor($buyer);

        foreach ($orders as $order) {
            $payments->push($order->payment ?? $this->open($order, $customerId));
        }

        return $payments;
    }

    private function open(Order $order, string $customerId): Payment
    {
        $intent = $this->stripe->paymentIntents->create([
            'amount' => $order->total_minor,
            'currency' => strtolower($order->currency->value),
            'customer' => $customerId,

            /*
             * Cards only, and deliberately.
             *
             * Stripe's other methods are mostly redirect flows, and a redirect
             * away from the site in the middle of paying for three orders is a
             * return path with three states to rebuild. Cards authenticate in
             * place, which is what makes one card entry for a whole basket
             * workable (ADR 0040).
             */
            'payment_method_types' => ['card'],

            /*
             * The card is kept against the customer, so the other orders in
             * the same basket can be charged without asking for it again.
             * Without this, "one card entry" would be one per shop.
             */
            'setup_future_usage' => 'off_session',

            // The money stays on the platform until the buyer confirms the
            // parcel arrived, and is transferred then (ADR 0015). There is no
            // destination or transfer_data here on purpose.
            'metadata' => [
                'order_reference' => $order->reference,
                'checkout_reference' => $order->checkout_reference,
                'seller_id' => (string) $order->seller_id,
            ],
        ], [
            // Derived from the order rather than random, so a retry of this
            // exact creation returns the first intent instead of a second one.
            'idempotency_key' => 'order-payment-'.$order->reference,
        ]);

        $payment = new Payment;

        $payment->forceFill([
            'order_id' => $order->id,
            'stripe_payment_intent_id' => $intent->id,
            'stripe_client_secret' => $intent->client_secret,
            'status' => PaymentStatus::forIntent((string) $intent->status),
            'amount_minor' => $order->total_minor,
            'currency' => $order->currency,
        ])->save();

        // So a caller reading `$order->payment` straight afterwards gets this
        // rather than the null it cached a moment ago.
        $order->setRelation('payment', $payment);

        return $payment;
    }

    /**
     * The buyer's customer at Stripe, made once and remembered.
     *
     * Only an email and an id: a name, an address and a card belong to Stripe,
     * and nothing about the person is copied back here (ADR 0031).
     */
    private function customerFor(User $buyer): string
    {
        if (is_string($buyer->stripe_customer_id) && $buyer->stripe_customer_id !== '') {
            return $buyer->stripe_customer_id;
        }

        $customer = $this->stripe->customers->create([
            'email' => $buyer->email,
            'metadata' => ['user_id' => (string) $buyer->id],
        ], [
            'idempotency_key' => 'buyer-customer-'.$buyer->id,
        ]);

        $buyer->forceFill(['stripe_customer_id' => $customer->id])->save();

        return $customer->id;
    }
}
