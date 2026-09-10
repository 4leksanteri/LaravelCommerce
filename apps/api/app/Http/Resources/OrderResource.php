<?php

declare(strict_types=1);

namespace App\Http\Resources;

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

            'item_count' => $this->itemCount(),
            'items' => OrderItemResource::collection($this->order->items),

            'placed_at' => $this->order->created_at?->toIso8601String(),
        ];
    }

    private function itemCount(): int
    {
        $count = 0;

        foreach ($this->order->items as $item) {
            $count += $item->quantity;
        }

        return $count;
    }
}
