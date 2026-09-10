<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\OrderParty;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An order as the shop that received it sees it.
 *
 * A different allowlist from `OrderResource`, and the differences are the
 * reason this class exists rather than a flag on that one:
 *
 * **`buyer_name` is here.** A seller has to know who they are sending to.
 *
 * **`checkout_reference` is not.** It would tell a seller that this purchase
 * had other parts, and by implication that their buyer was shopping elsewhere
 * at that moment. It serves nothing on this side and says something that is not
 * this shop's business (ADR 0011).
 *
 * The `can_*` fields answer for **the seller**, which is not the same answer the
 * buyer gets from the same order: once accepted, only the seller may cancel.
 */
final class SellerOrderResource extends JsonResource
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
            'status' => $this->order->status,

            'buyer_name' => $this->order->user->name,

            'currency' => $this->order->currency,
            'total_minor' => $this->order->total_minor,

            'item_count' => $this->order->itemCount(),
            'items' => OrderItemResource::collection($this->order->items),

            'placed_at' => $this->order->created_at?->toIso8601String(),
            'accepted_at' => $this->order->accepted_at?->toIso8601String(),
            'shipped_at' => $this->order->shipped_at?->toIso8601String(),
            'completed_at' => $this->order->completed_at?->toIso8601String(),
            'cancelled_at' => $this->order->cancelled_at?->toIso8601String(),

            // Declared `: bool` rather than computed inline. The generator reads
            // declared return types, and inline these were published to the
            // frontend as strings - see ProductResource.
            'can_accept' => $this->canAccept(),
            'can_ship' => $this->canShip(),
            'can_cancel' => $this->canCancel(),
        ];
    }

    private function canAccept(): bool
    {
        return $this->order->status->canBeAccepted();
    }

    private function canShip(): bool
    {
        return $this->order->status->canBeShipped();
    }

    private function canCancel(): bool
    {
        return $this->order->status->canBeCancelledBy(OrderParty::Seller);
    }
}
