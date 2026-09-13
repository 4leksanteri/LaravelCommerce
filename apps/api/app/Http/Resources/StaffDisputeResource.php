<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\Currency;
use App\Enums\DisputeResolution;
use App\Models\Dispute;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A dispute in the platform's queue, where there is no order around it.
 *
 * A different allowlist from `DisputeResource`, and that is why this class
 * exists rather than a flag on that one. Staff are deciding between two people
 * they cannot see, so they need who, from which shop, and how much is being
 * argued over - all of which the parties' own pages already show them.
 *
 * **The amount is the order's total**, in the order's own currency, because
 * that is what is being decided. It is sent as minor units like every other
 * figure here, and the frontend formats it (ADR 0004).
 */
final class StaffDisputeResource extends JsonResource
{
    public function __construct(private readonly Dispute $dispute)
    {
        parent::__construct($dispute);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $order = $this->dispute->order;

        return [
            'id' => $this->dispute->id,
            'reason' => $this->dispute->reason,
            'is_open' => $this->dispute->isOpen(),

            /** @var DisputeResolution|null */
            'resolution' => $this->dispute->resolution,
            /** @var string|null */
            'resolution_note' => $this->dispute->resolution_note,

            'opened_at' => $this->dispute->created_at?->toIso8601String(),
            /** @var string|null */
            'resolved_at' => $this->dispute->resolved_at?->toIso8601String(),

            // The order it is about. Enough to decide with, and no more: staff
            // do not need the delivery address to answer "did it arrive".
            'order_reference' => $order->reference,
            'buyer_name' => $order->user->name,
            'shop_name' => $order->seller->shop_name,
            'shop_slug' => $order->seller->slug,

            /** @var Currency */
            'currency' => $order->currency,
            'total_minor' => $order->total_minor,
            'shipped_at' => $order->shipped_at?->toIso8601String(),
        ];
    }
}
