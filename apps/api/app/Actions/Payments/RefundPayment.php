<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Models\Order;
use App\Models\Payment;
use Stripe\StripeClient;

/**
 * Gives a buyer their money back when an order is called off.
 *
 * **In full, always.** There is no partial cancellation in this application -
 * an order is called off whole or not at all - so there is no partial refund to
 * describe. The amount is the one Stripe charged, and it is not sent: Stripe
 * refunds the intent in full when no amount is given, which is one fewer figure
 * this application can get wrong.
 *
 * **A shipped order is refunded too**, and that is the uncomfortable one. A
 * seller may cancel after shipping, which ADR 0012 added as the escape hatch
 * for a parcel that never arrives, and once money is involved that is a refund
 * for goods that have left the building. The buyer is made whole because the
 * shop chose to call it off; the shop carries the loss it chose. A seller who
 * did that to an order which actually arrived would keep neither goods nor
 * money - which is what a dispute is for (ADR 0051), and what ADR 0061 finally
 * lets one reach.
 *
 * **It refunds money that has already been to a shop, once it has come back.**
 * That is the one thing this could not do before: `isHeld()` was the gate, and
 * a transferred payment was never held again. `canBeRefunded()` is wider by
 * exactly one case - a payment whose transfer has been reversed - so there is
 * still a single refund path rather than a parallel one for the reversed case
 * (ADR 0041: there is no second way to pay anybody).
 *
 * **It refuses quietly rather than throwing.** An unpaid order has nothing to
 * refund and an already-refunded one needs nothing more, and neither is a
 * failure of the cancellation or the decision that called it.
 */
final class RefundPayment
{
    public function __construct(private readonly StripeClient $stripe) {}

    /**
     * @return Payment|null the payment as it now stands, or null when there was
     *                      nothing to give back
     */
    public function handle(Order $order): ?Payment
    {
        $payment = $order->payment;

        /*
         * Paid, not already refunded, and either never transferred or
         * transferred and since reversed (ADR 0061).
         *
         * It was `isHeld()` until a reversal existed, because the two asked the
         * same question then. They are not the same now: a reversed payment is
         * refundable and deliberately not held, since the money is owed to the
         * buyer rather than waiting on anything.
         */
        if (! $payment instanceof Payment || ! $payment->canBeRefunded()) {
            return null;
        }

        $refund = $this->stripe->refunds->create([
            'payment_intent' => $payment->stripe_payment_intent_id,

            // Stripe's own vocabulary for why. `requested_by_customer` would be
            // a claim about who asked, and the platform cannot tell from here.
            'metadata' => [
                'order_reference' => $order->reference,

                /*
                 * Null on an order cancelled before this application recorded
                 * who did it (ADR 0035), and null again on a refund that
                 * follows a reversal - that order stays `completed` and is
                 * never cancelled at all (ADR 0061). The reversal is what says
                 * which of the two this is, so it is sent rather than leaving
                 * every post-completion refund labelled "unknown".
                 */
                'cancelled_by' => $order->cancelled_by->value ?? 'unknown',
                'after_reversal' => $payment->isReversed() ? 'true' : 'false',
            ],
        ], [
            'idempotency_key' => 'order-refund-'.$order->reference,
        ]);

        $payment->forceFill([
            'stripe_refund_id' => $refund->id,
            'refunded_at' => now(),
        ])->save();

        return $payment;
    }
}
