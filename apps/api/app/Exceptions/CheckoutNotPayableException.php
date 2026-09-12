<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Paying for a checkout that has nothing left to pay for.
 *
 * A 409 rather than a 403 or a 422: the buyer is entitled to pay for their own
 * checkout and what they sent was valid. What is in the way is the state of the
 * world - it is already paid, or every order in it has been cancelled.
 */
final class CheckoutNotPayableException extends RuntimeException
{
    public static function alreadyPaid(): self
    {
        return new self('This checkout has already been paid for.');
    }

    public static function nothingToPay(): self
    {
        return new self('There is nothing left to pay for on this checkout.');
    }
}
