<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Stripe\StripeClient;

/**
 * Copies what Stripe says about a PaymentIntent onto the row that mirrors it.
 *
 * The payments counterpart of `SyncPayoutAccount`, and for the same reason:
 * Stripe owns whether money moved, and this row exists so a page can be drawn
 * without asking again. Where the two disagree, Stripe is right.
 *
 * Every call this application makes gets the updated intent back, and that is
 * passed straight in. A webhook passes nothing, and the intent is **fetched
 * again** rather than read out of the event - events can arrive out of order,
 * and the current intent cannot be stale.
 *
 * The three nullable columns are written together on every sync, because the
 * table's own constraints relate them to the status: a succeeded payment has
 * its date and nothing else does, and only a failed one carries a reason.
 * Copying one without the others would write a row PostgreSQL refuses.
 */
final class SyncPayment
{
    public function __construct(private readonly StripeClient $stripe) {}

    public function handle(Payment $payment, ?PaymentIntent $current = null): Payment
    {
        $current ??= $this->stripe->paymentIntents->retrieve($payment->stripe_payment_intent_id);

        $refusal = $this->refusal($current);
        $status = PaymentStatus::forIntent((string) $current->status, $refusal !== null);

        $payment->forceFill([
            'status' => $status,
            'stripe_payment_method_id' => $this->paymentMethodId($current) ?? $payment->stripe_payment_method_id,
            'failure_reason' => $status === PaymentStatus::Failed ? $refusal : null,

            // Stamped once. A second succeeded event for the same intent must
            // not move the moment the money arrived.
            'paid_at' => $status->isPaid() ? ($payment->paid_at ?? now()) : null,
        ])->save();

        return $payment;
    }

    /**
     * Stripe's own words for why a card was refused, which the buyer is shown
     * as sent. Null when nothing was refused.
     */
    private function refusal(PaymentIntent $intent): ?string
    {
        $error = $intent->last_payment_error;

        if ($error === null) {
            return null;
        }

        $message = $error->message ?? null;

        return is_string($message) && $message !== '' ? $message : 'The payment was refused.';
    }

    /**
     * The card Stripe kept, which is what the rest of the same basket is
     * charged against. A string on a fresh intent and an object on one that
     * has been expanded, so both shapes are read.
     */
    private function paymentMethodId(PaymentIntent $intent): ?string
    {
        $method = $intent->payment_method;

        if (is_string($method) && $method !== '') {
            return $method;
        }

        return $method instanceof PaymentMethod ? $method->id : null;
    }
}
