<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where an order is.
 *
 * One case today, which needs justifying, because ADR 0010 argued the cart
 * should **not** have a status column for exactly that reason.
 *
 * The difference is what the column records. A cart's status would have been
 * derived - "converted" is a restatement of "an order exists" - and a column
 * that restates a fact recorded elsewhere is a column that can disagree with
 * it. `pending` is not derived from anything. It is the only record in the
 * system that an order has not been paid for, and leaving it out would mean
 * every order silently claiming to be settled.
 *
 * It is also read rather than stored and forgotten: `OrderResource` publishes
 * it, so a buyer is told their order is awaiting payment instead of being shown
 * a list of purchases that may or may not have gone through.
 *
 * `paid`, `shipped` and `cancelled` arrive with payments, and each will bring
 * the timestamp and the transition rules that make it mean something. None of
 * them is written down here in advance.
 */
enum OrderStatus: string
{
    /** Placed, and not yet paid for. There is no payment system yet. */
    case Pending = 'pending';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
