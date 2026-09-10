<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of a receipt.
 *
 * Every figure and every name here is read from the order, never from the
 * catalogue. That is the difference from `CartItemResource`, which does the
 * opposite - and it is why there is no `availability` and no `price_changed`.
 * A receipt does not change when a shop does.
 *
 * `product_slug` is the single exception, and it is a link rather than a fact:
 * it says where the listing is now, so a buyer can be offered "order this
 * again". It is null once the variant has been removed.
 */
final class OrderItemResource extends JsonResource
{
    public function __construct(private readonly OrderItem $item)
    {
        parent::__construct($item);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $variant = $this->item->variant;

        return [
            'id' => $this->item->id,

            'product_name' => $this->item->product_name,
            'variant_name' => $this->item->variant_name,

            'quantity' => $this->item->quantity,

            // In the order's currency, which is on the order because every line
            // of one order is in it by construction (ADR 0004).
            'unit_price_minor' => $this->item->unit_price_minor,
            'line_total_minor' => $this->item->lineTotalMinor(),

            'product_slug' => $variant?->product->slug,
            'variant_id' => $variant?->id,
        ];
    }
}
