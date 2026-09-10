<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\OrderParty;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An order, as its buyer sees it.
 *
 * One shop, one currency, one total. There is no order that spans two shops and
 * so no total here that spans two currencies - the same rule the cart makes
 * structural, arriving at the place it was always heading (ADR 0004).
 *
 * `reference` rather than `id` is what a person quotes and what the URL uses.
 */
final class OrderResource extends JsonResource
{
    public function __construct(private readonly Order $order)
    {
        parent::__construct($order);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->order->reference,

            // Shared by every order one checkout produced, so a buyer's history
            // can show "these three were one purchase" - which is how they
            // remember it, whatever the domain had to split it into (ADR 0011).
            'checkout_reference' => $this->order->checkout_reference,

            // The enum, not its value: the generator turns it into a union of
            // the actual cases, so a component switching on it is exhaustive.
            'status' => $this->order->status,

            'shop_slug' => $this->order->seller->slug,
            'shop_name' => $this->order->seller->shop_name,

            'currency' => $this->order->currency,
            'total_minor' => $this->order->total_minor,

            'item_count' => $this->order->itemCount(),
            'items' => OrderItemResource::collection($this->order->items),

            'placed_at' => $this->order->created_at?->toIso8601String(),
            'accepted_at' => $this->order->accepted_at?->toIso8601String(),
            'shipped_at' => $this->order->shipped_at?->toIso8601String(),
            'completed_at' => $this->order->completed_at?->toIso8601String(),
            'cancelled_at' => $this->order->cancelled_at?->toIso8601String(),

            // The answer for **the buyer**, which is not the same answer the
            // seller gets from the same order: once accepted, only the seller
            // may cancel. Declared `: bool` so the generator types it as one.
            'can_cancel' => $this->canCancel(),
            'can_complete' => $this->canComplete(),
        ];
    }

    private function canCancel(): bool
    {
        return $this->order->status->canBeCancelledBy(OrderParty::Buyer);
    }

    /** Confirming receipt is the buyer's alone. See CompleteOrder. */
    private function canComplete(): bool
    {
        return $this->order->status->canBeCompleted();
    }
}
