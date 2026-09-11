<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What stands between a basket and a checkout.
 *
 * This is the **answer**, not the inputs (root CLAUDE.md section 4). The page
 * that draws a checkout needs to know whether it can, and a browser handed
 * `email_verified_at` and the cart's lines would be re-deriving rules this
 * application owns: `verified` on the checkout route, and the revalidation
 * inside PlaceOrders.
 *
 * One reason rather than a list, because a person deals with them one at a
 * time, and the page draws the next step for whichever comes first. A basket
 * with nothing in the way has no blocker at all.
 */
enum CheckoutBlocker: string
{
    /** Nothing in the basket, so there is nothing to check out. */
    case Empty = 'empty';

    /** Checkout needs a confirmed address, because the receipt goes to it (ADR 0011). */
    case UnverifiedEmail = 'unverified_email';

    /** At least one line can no longer be bought as it is. */
    case UnavailableItems = 'unavailable_items';
}
