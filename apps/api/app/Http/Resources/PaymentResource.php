<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What was charged for one order, as its buyer sees it.
 *
 * **The client secret is here only while it is needed.** It is what the
 * browser confirms an intent with, and a payment that has already succeeded
 * has nothing left to confirm - sending it then would be handing out a handle
 * for no reason. It is not a credential of this application's: it authorises
 * one intent, belongs to the buyer whose order it is, and is useless without
 * the publishable key.
 *
 * There is no card here, and never will be. What Stripe holds stays at Stripe
 * (ADR 0031); `failure_reason` is Stripe's own sentence about a refusal, shown
 * as sent because it is the only thing that tells a buyer what to do next.
 */
final class PaymentResource extends JsonResource
{
    /**
     * The order's reference is passed in rather than read through the payment's
     * relation. A `belongsTo` is nullable as far as anything static can tell,
     * and every caller here is already holding the order - reaching back
     * through the relation would be a query per payment and a null check that
     * can never fire.
     */
    public function __construct(
        private readonly Payment $payment,
        private readonly string $orderReference,
    ) {
        parent::__construct($payment);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'order_reference' => $this->orderReference,

            // The enum itself, so the generated contract is a union of the
            // actual cases rather than a bare string.
            'status' => $this->payment->status,

            'amount_minor' => $this->payment->amount_minor,
            'currency' => $this->payment->currency,

            'client_secret' => $this->payment->status->isPaid()
                ? null
                : $this->payment->stripe_client_secret,

            'failure_reason' => $this->payment->failure_reason,
            'paid_at' => $this->payment->paid_at?->toIso8601String(),
        ];
    }
}
