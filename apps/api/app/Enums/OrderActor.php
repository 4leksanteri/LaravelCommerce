<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who moved an order to where it ended: cancelled, or completed.
 *
 * OrderParty is who may act, and it has two cases because only people ask for
 * permission. This has three, because the platform ends orders too: an order
 * no shop accepts expires, and a sent one nobody confirms completes on its own.
 * Both are a deadline passing, and the case says so rather than calling it
 * "system" and leaving the reader to guess which system (ADR 0014, ADR 0035).
 *
 * A shop never completes an order, and the database says so: `completed_by`
 * cannot be `seller`.
 */
enum OrderActor: string
{
    case Buyer = 'buyer';
    case Seller = 'seller';
    case Deadline = 'deadline';

    /** The person who asked, as the actor recorded for it. */
    public static function party(OrderParty $party): self
    {
        return match ($party) {
            OrderParty::Buyer => self::Buyer,
            OrderParty::Seller => self::Seller,
        };
    }
}
