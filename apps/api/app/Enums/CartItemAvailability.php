<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether a line of a cart can actually be bought, and if not, why.
 *
 * A cart is durable and the catalogue underneath it is not: a listing can be
 * unpublished, deleted or sold out between adding something and coming back to
 * it. Refusing to show the cart at all would be absurd, so every line answers
 * this question for itself.
 *
 * This is the **answer**, not the inputs (root CLAUDE.md section 4). The
 * frontend does not receive a status, a stock count and a shop state to
 * re-derive availability from; it receives which of these four cases holds.
 *
 * Three ways of being unbuyable rather than one, because the shopper does
 * something different about each: re-add it somewhere else, wait, or reduce
 * the quantity.
 */
enum CartItemAvailability: string
{
    case Available = 'available';

    /** Unpublished, deleted, the shop suspended, or the variant removed. */
    case NoLongerForSale = 'no_longer_for_sale';

    /** Still listed, none left. */
    case OutOfStock = 'out_of_stock';

    /** Some left, fewer than this line asks for. */
    case InsufficientStock = 'insufficient_stock';

    public function isAvailable(): bool
    {
        return $this === self::Available;
    }
}
