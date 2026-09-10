<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which side of an order is asking.
 *
 * An order has exactly two parties and they may do different things to the same
 * row: a buyer may call off an order nobody has committed to, and a seller may
 * refuse one they have already accepted. "Who is asking" is therefore a domain
 * concept rather than a flag, which is why `canBeCancelledBy(OrderParty)` reads
 * the way it does instead of taking a boolean.
 *
 * Platform staff are deliberately absent. Nothing gives staff a way to move
 * somebody else's order, and the day something does it will be a third case
 * here with its own rules rather than a seller impersonation.
 */
enum OrderParty: string
{
    case Buyer = 'buyer';
    case Seller = 'seller';
}
