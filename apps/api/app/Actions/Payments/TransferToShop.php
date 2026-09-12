<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Enums\PayoutStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PayoutAccount;
use Stripe\StripeClient;

/**
 * Sends a shop its money, less what the marketplace keeps.
 *
 * **This is what completion is for.** A payment sits on the platform from
 * checkout until the buyer confirms the parcel arrived, and this is the moment
 * it stops being held (ADR 0015). No other event moves it: not acceptance, not
 * shipping, and nothing a seller can do on their own.
 *
 * **It refuses quietly rather than throwing**, and that is the design. Four
 * things stop a transfer - the order was never paid, it has already been sent,
 * the money has been refunded, or the shop's Stripe account cannot receive
 * anything yet - and none of them is a failure of the completion that called
 * it. The order is completed either way; the money follows when it can, and
 * `payments:transfer-due` picks up whatever was left behind (ADR 0041).
 *
 * The fee is taken here and recorded on the row: what the marketplace kept is a
 * fact about that transfer, not something to derive later from a rate that may
 * have changed since. The arithmetic itself is `Payment::platformFeeMinor()`,
 * which is also what a shop is shown before any of this runs - one sum, so the
 * figure a seller was quoted and the figure Stripe is sent cannot disagree.
 */
final class TransferToShop
{
    public function __construct(private readonly StripeClient $stripe) {}

    /**
     * @return Payment|null the payment as it now stands, or null when there was
     *                      nothing to send
     */
    public function handle(Order $order): ?Payment
    {
        $payment = $order->payment;

        if (! $payment instanceof Payment || ! $payment->isHeld()) {
            return null;
        }

        $destination = $this->destinationFor($order);

        if ($destination === null) {
            return null;
        }

        $fee = $payment->platformFeeMinor();

        $transfer = $this->stripe->transfers->create([
            'amount' => $payment->amount_minor - $fee,
            'currency' => strtolower($payment->currency->value),
            'destination' => $destination,

            // Ties the transfer to the charge it came out of, which is what
            // makes a Stripe balance readable months later.
            'transfer_group' => $order->reference,

            'metadata' => [
                'order_reference' => $order->reference,
                'seller_id' => (string) $order->seller_id,
                'platform_fee_minor' => (string) $fee,
            ],
        ], [
            // Derived from the order, so a retry sends the same transfer rather
            // than a second one. Paying a shop twice is the worst thing this
            // action could do.
            'idempotency_key' => 'order-transfer-'.$order->reference,
        ]);

        $payment->forceFill([
            'platform_fee_minor' => $fee,
            'stripe_transfer_id' => $transfer->id,
            'transferred_at' => now(),
        ])->save();

        return $payment;
    }

    /**
     * The connected account to send to, or null when the shop cannot receive.
     *
     * `PayoutStatus::Active` is the API's own answer to that, read off what
     * Stripe last said (ADR 0031), so this asks rather than re-deriving it from
     * capabilities and requirement lists.
     */
    private function destinationFor(Order $order): ?string
    {
        $account = $order->seller->payoutAccount;

        if (! $account instanceof PayoutAccount || $account->status() !== PayoutStatus::Active) {
            return null;
        }

        return $account->stripe_account_id;
    }
}
