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
 * Four ways of being unbuyable rather than one, because the shopper does
 * something different about each: re-add it somewhere else, wait, reduce the
 * quantity, or take it out because it was never theirs to buy.
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

    /**
     * The shopper's own shop sells it (ADR 0056).
     *
     * The odd one out, and deliberately here rather than at the cart's door.
     * Every case above describes the catalogue moving underneath a cart; this
     * one was true from the moment the line was added and will not change. ADR
     * 0010 settled that it is a checkout rule rather than a cart rule, and this
     * is how checkout refuses it - through the same machinery as the rest.
     */
    case YourOwnShop = 'your_own_shop';

    public function isAvailable(): bool
    {
        return $this === self::Available;
    }
}
