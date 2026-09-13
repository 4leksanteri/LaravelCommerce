<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who moved an order to where it ended: cancelled, or completed.
 *
 * OrderParty is who may act, and it has two cases because only people ask for
 * permission. This has four, because the platform ends orders too: an order
 * no shop accepts expires, and a sent one nobody confirms completes on its own.
 * Both are a deadline passing, and the case says so rather than calling it
 * "system" and leaving the reader to guess which system (ADR 0014, ADR 0035).
 *
 * **`Staff` arrived with disputes** (ADR 0051). Deciding one ends the order,
 * and the platform is neither of its parties nor a clock - so it is a case of
 * its own rather than a reuse of `Deadline`, which would make "who ended this"
 * unanswerable on exactly the orders somebody complained about.
 *
 * A shop never completes an order, and the database says so: `completed_by`
 * cannot be `seller`. It can be `staff`, because a dispute decided for the shop
 * is the platform releasing the money rather than the shop releasing its own.
 */
enum OrderActor: string
{
    case Buyer = 'buyer';
    case Seller = 'seller';
    case Deadline = 'deadline';
    case Staff = 'staff';

    /** The person who asked, as the actor recorded for it. */
    public static function party(OrderParty $party): self
    {
        return match ($party) {
            OrderParty::Buyer => self::Buyer,
            OrderParty::Seller => self::Seller,
        };
    }
}
