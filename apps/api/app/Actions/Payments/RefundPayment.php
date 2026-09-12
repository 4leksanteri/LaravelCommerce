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
 * money - that is a dispute, and there are none (ADR 0041).
 *
 * **It refuses quietly rather than throwing.** An unpaid order has nothing to
 * refund and an already-refunded one needs nothing more, and neither is a
 * failure of the cancellation that called it.
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

        // `isHeld` is the whole condition: paid, not already refunded, and not
        // transferred. Money that has reached a shop cannot be pulled back from
        // here - that would be a reversal, and nothing here does one.
        if (! $payment instanceof Payment || ! $payment->isHeld()) {
            return null;
        }

        $refund = $this->stripe->refunds->create([
            'payment_intent' => $payment->stripe_payment_intent_id,

            // Stripe's own vocabulary for why. `requested_by_customer` would be
            // a claim about who asked, and the platform cannot tell from here.
            'metadata' => [
                'order_reference' => $order->reference,
                // Null on an order cancelled before this application recorded
                // who did it (ADR 0035). `??` handles that; the nullsafe
                // operator in front of it would be the redundant half.
                'cancelled_by' => $order->cancelled_by->value ?? 'unknown',
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
