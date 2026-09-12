<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One payment that reached the shop, as the shop sees it.
 *
 * A payment row rather than a `transfers` table of our own: the transfer is
 * one of the things that happened to a charge, and Stripe owns whether it
 * happened (ADR 0031). What is published here is the three figures a seller
 * needs to reconcile a payout - what the buyer was charged, what the
 * marketplace kept, and what arrived - and the order they belong to.
 *
 * No Stripe ids. `stripe_transfer_id` names an object in the platform's own
 * account, and a seller has no use for a handle they cannot look up.
 */
final class TransferResource extends JsonResource
{
    public function __construct(private readonly Payment $payment)
    {
        parent::__construct($payment);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'order_reference' => $this->payment->order->reference,

            // One shop, one currency, fixed when the shop applied (ADR 0004),
            // so a page of these never spans two and never needs to.
            'currency' => $this->payment->currency,

            'charged_minor' => $this->payment->amount_minor,
            'platform_fee_minor' => $this->payment->platformFeeMinor(),
            'amount_minor' => $this->payment->shopReceivesMinor(),

            'transferred_at' => $this->payment->transferred_at?->toIso8601String(),
        ];
    }
}
