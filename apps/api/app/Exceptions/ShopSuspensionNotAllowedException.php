<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\SellerStatus;

/**
 * A shop that cannot be stopped, or one that was not stopped to begin with.
 *
 * **409 rather than 403 in both cases.** A member of staff is entitled to
 * suspend a shop; what is in the way is where the shop has got to. Suspending
 * one that never opened stops nothing, and reinstating one that is trading
 * restores nothing.
 *
 * Not `SellerAlreadyReviewedException`, which means something else: a decision
 * was taken by another reviewer while this one was deciding. A suspension is
 * not a review, and a shop can be suspended long after it was reviewed.
 */
final class ShopSuspensionNotAllowedException extends DomainRefusal
{
    public function __construct(string $message, public readonly SellerStatus $status)
    {
        parent::__construct($message);
    }

    /** Only a trading shop can be stopped from trading. */
    public static function notTrading(SellerStatus $status): self
    {
        return new self(
            'Only an open shop can be suspended, and this one is not open.',
            $status,
        );
    }

    public static function notSuspended(SellerStatus $status): self
    {
        return new self('This shop is not suspended.', $status);
    }
}
