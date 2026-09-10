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

            // Where it went, frozen onto the order rather than read from the
            // buyer's address book (ADR 0021). Null only for orders placed
            // before addresses existed, of which there are none.
            'shipping_address' => $this->order->shipping_line1 === null
                ? null
                : new ShippingAddressResource($this->order),

            'item_count' => $this->order->itemCount(),
            'items' => OrderItemResource::collection($this->order->items),

            'placed_at' => $this->order->created_at?->toIso8601String(),
            'accepted_at' => $this->order->accepted_at?->toIso8601String(),
            'shipped_at' => $this->order->shipped_at?->toIso8601String(),
            'completed_at' => $this->order->completed_at?->toIso8601String(),
            'cancelled_at' => $this->order->cancelled_at?->toIso8601String(),

            // When this completes on its own if the buyer never confirms. A
            // date rather than a window, so it can be shown to the person it
            // applies to - and so an extension is visible as it moving.
            'auto_complete_at' => $this->order->auto_complete_at?->toIso8601String(),
            'completion_extensions_left' => $this->extensionsLeft(),

            // The answer for **the buyer**, which is not the same answer the
            // seller gets from the same order: once accepted, only the seller
            // may cancel. Declared `: bool` so the generator types it as one.
            'can_cancel' => $this->canCancel(),
            'can_complete' => $this->canComplete(),
            'can_extend_completion' => $this->canExtendCompletion(),
        ];
    }

    /**
     * Whether the buyer may say their parcel has not arrived yet.
     *
     * The answer, not the inputs: a browser deriving this from the status and
     * an extension count would need a copy of the cap, and the copy would go
     * stale the day it changes.
     */
    private function canExtendCompletion(): bool
    {
        return $this->order->canExtendCompletion();
    }

    private function extensionsLeft(): int
    {
        $maximum = (int) config('orders.max_completion_extensions');

        return max(0, $maximum - $this->order->completion_extensions);
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
