<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Models\Order;
use App\Models\Payment;
use Stripe\StripeClient;

/**
 * Takes back money a shop has already been sent (ADR 0061).
 *
 * **The thing ADR 0041 deliberately did not build.** It wrote down why: "a
 * reversal... is a different Stripe object with its own failure modes, and
 * nothing here does one". The failure modes are real - a connected account can
 * be short of the amount, and Stripe refuses a reversal it cannot fund - which
 * is exactly why this refuses quietly and leaves `payments:settle` to try
 * again, like every other money action here.
 *
 * **In full, always.** No amount is sent, so Stripe reverses the whole
 * transfer. There is no partial refund in this application to need a partial
 * reversal, and the amount is one fewer figure this can get wrong - the same
 * reasoning `RefundPayment` gives.
 *
 * **It does not undo the transfer, it records a second event.** `transferred_at`,
 * `stripe_transfer_id` and `platform_fee_minor` are untouched: the money did go
 * to the shop, the marketplace did keep its fee, and both are facts about
 * something that happened. What comes back is written beside them, never over
 * them (ADR 0060).
 *
 * **This does not give the buyer anything.** A reversal moves money from the
 * connected account back to the platform, and it is the platform the buyer is
 * refunded from. Reversing without refunding would leave the money here, which
 * is the worst of the three places it could be - so callers pair the two, and
 * `payments:settle` finishes a pair that was interrupted.
 */
final class ReverseTransfer
{
    public function __construct(private readonly StripeClient $stripe) {}

    /**
     * @return Payment|null the payment as it now stands, or null when there was
     *                      nothing to pull back
     */
    public function handle(Order $order): ?Payment
    {
        $payment = $order->payment;

        if (! $payment instanceof Payment || ! $payment->canBeReversed()) {
            return null;
        }

        /*
         * `canBeReversed()` has established there was a transfer, and
         * `payments_transfer_is_whole` ties the id to the timestamp - so this
         * cannot be null. The analyser cannot follow either of those through a
         * model property, and a cast to quiet it would be a promise rather than
         * a proof (`apps/api/CLAUDE.md` section 11).
         *
         * A real branch instead: it fails closed if a row ever did reach that
         * state, rather than sending Stripe an empty path.
         */
        $transferId = $payment->stripe_transfer_id;

        if ($transferId === null) {
            return null;
        }

        $reversal = $this->stripe->transfers->createReversal(
            $transferId,
            [
                'metadata' => [
                    'order_reference' => $order->reference,
                    'seller_id' => (string) $order->seller_id,
                ],
            ],
            [
                // Derived from the order, so a retry pulls back the same money
                // rather than a second helping of it. Reversing twice would
                // take from a shop money it never received.
                'idempotency_key' => 'order-reversal-'.$order->reference,
            ],
        );

        $payment->forceFill([
            'stripe_transfer_reversal_id' => $reversal->id,
            'reversed_at' => now(),
        ])->save();

        return $payment;
    }
}
