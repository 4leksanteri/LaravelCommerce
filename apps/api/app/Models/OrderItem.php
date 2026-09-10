<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of what was agreed.
 *
 * The mirror image of `CartItem`, and the difference between them is the point
 * of both. A cart line stores a snapshot but reads its price from the variant
 * every time it is shown; an order line **is** the snapshot, and never reads
 * the catalogue for anything a receipt states.
 *
 * `variant` is therefore a link and not a source. It is null once a seller has
 * removed the variant, and nothing on this row changes when that happens.
 *
 * @property-read Order $order
 * @property-read ProductVariant|null $variant
 */
class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price_minor' => 'integer',
            'quantity' => 'integer',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The variant this was, if it still exists.
     *
     * Deliberately unconstrained, unlike `CartItem::purchasableVariant`. A cart
     * asks "can this still be bought"; an order asks "is there still a page to
     * link to", and an unpublished listing has one for the seller and will have
     * one again if they publish it. Whether it is currently for sale is not a
     * question about somebody's receipt.
     *
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * In the order's currency, and never anything else.
     *
     * Not stored: it is exactly `unit_price_minor * quantity`, and a stored
     * copy is a third number that can disagree with the two it came from.
     */
    public function lineTotalMinor(): int
    {
        return $this->unit_price_minor * $this->quantity;
    }
}
