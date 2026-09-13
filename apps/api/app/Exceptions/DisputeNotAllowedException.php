<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * A dispute that cannot be opened, or one that has already been decided.
 *
 * **409 rather than 403 in every case.** The buyer is a party to the order and
 * entitled to complain about it; a member of staff is entitled to decide one.
 * What is in the way is where the order or the dispute has got to, which is the
 * distinction ADR 0008 draws and the reason these are refusals rather than
 * authorization failures.
 *
 * Not reported, like every `DomainRefusal` (ADR 0045). "You have already
 * disputed this" is the marketplace working.
 */
final class DisputeNotAllowedException extends DomainRefusal
{
    /**
     * The money is not held, so there is nothing a decision could move.
     *
     * A dispute exists only in the window between a shop posting something and
     * the money leaving the platform (ADR 0051). Before it there is nothing to
     * argue about; after it, sending the money back would be a Stripe reversal,
     * and nothing here does one.
     */
    public static function nothingToDispute(): self
    {
        return new self(
            'This order cannot be disputed: it has not been sent yet, or its money has already been settled.',
        );
    }

    public static function alreadyDisputed(): self
    {
        return new self('This order has already been disputed.');
    }

    /** Two members of staff reached the same dispute, and one of them lost. */
    public static function alreadyResolved(): self
    {
        return new self('This dispute has already been decided.');
    }
}
