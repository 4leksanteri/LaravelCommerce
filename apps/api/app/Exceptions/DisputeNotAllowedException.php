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
     * There is nothing a decision could still move.
     *
     * A dispute used to exist only while the money was held (ADR 0051), because
     * that was as far as a decision could reach. ADR 0061 built the reversal
     * that bound was waiting on, so the window now runs from dispatch until
     * `orders.dispute_after_completion_days` after the order completed.
     *
     * What is left outside it: an order nobody has sent, one whose money has
     * already gone back, and one completed long enough ago that the shop is
     * entitled to treat its takings as its own.
     */
    public static function nothingToDispute(): self
    {
        return new self(
            'This order cannot be disputed: it has not been sent yet, or the time to argue about it has passed.',
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
