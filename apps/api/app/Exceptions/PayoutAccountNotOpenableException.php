<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A payout account that cannot be opened, because of the state of the shop
 * rather than because of who is asking.
 *
 * 409, for the reason ADR 0008 gives: the owner is entitled to set up how
 * their own shop gets paid, and what is in the way is a fact about the shop.
 */
final class PayoutAccountNotOpenableException extends RuntimeException
{
    public static function shopNotApproved(): self
    {
        return new self(
            'This shop has not been approved yet. A payout account can be opened once it has.',
        );
    }

    public static function alreadyOpen(): self
    {
        return new self('This shop already has a payout account.');
    }
}
