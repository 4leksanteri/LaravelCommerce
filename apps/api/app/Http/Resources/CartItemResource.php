<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CartItem;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of a cart.
 *
 * Two prices are published and the difference between them is the point:
 *
 *   unit_price_minor    what it costs now, and what checkout will charge
 *   added_price_minor   what it cost when it went in the cart
 *
 * The first is read from the variant every time this is rendered. The second is
 * a snapshot, and it is here only so `price_changed` can be answered - a
 * shopper finding out at the payment screen that something went up is the
 * failure this avoids. Neither the frontend nor anything else should treat the
 * snapshot as the price. See ADR 0010.
 */
final class CartItemResource extends JsonResource
{
    public function __construct(private readonly CartItem $line)
    {
        parent::__construct($line);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $variant = $this->line->purchasableVariant;

        return [
            'id' => $this->line->id,
            'quantity' => $this->line->quantity,

            // From the catalogue while there is one to read, falling back to
            // the snapshot. The catalogue is the source of truth until checkout
            // (ADR 0010), and that includes what a thing is called - a seller
            // who corrects a typo should not have the old name follow the
            // shopper to the order. The snapshot is what keeps a line whose
            // variant has been deleted readable rather than blank.
            'product_name' => $variant instanceof ProductVariant
                ? $variant->product->name
                : $this->line->product_name,

            'variant_name' => $variant instanceof ProductVariant
                ? $variant->name
                : $this->line->variant_name,

            // Null when there is no longer anything to link to. That is the
            // honest answer: a frontend that linked to an unpublished listing
            // would be linking to a 404.
            'product_slug' => $variant?->product->slug,
            'variant_id' => $variant?->id,

            'unit_price_minor' => $this->line->unitPriceMinor(),
            'added_price_minor' => $this->line->added_price_minor,
            'price_changed' => $this->line->priceChanged(),

            // This line's own arithmetic, reported whatever its availability.
            // A shop's subtotal is the narrower figure and counts only what can
            // actually be bought.
            'line_total_minor' => $this->line->lineTotalMinor(),

            // The answer, not the inputs. There is no status, stock count or
            // shop state here for the browser to re-derive this from.
            'availability' => $this->line->availability(),

            // Only when fewer are available than this line asks for, which
            // keeps ADR 0009's refusal to publish inventory intact: the number
            // appears when the shopper has to be told it to fix their cart.
            'available_quantity' => $this->line->availableQuantity(),
        ];
    }
}
