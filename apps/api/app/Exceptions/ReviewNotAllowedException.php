<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Why this person cannot review this listing (ADR 0047).
 *
 * Both cases are **409 rather than 403**, and the distinction is the one
 * ADR 0008 draws: the caller is entitled to review things they have bought, and
 * what is in the way is the state of the world - they have not received this
 * one yet, or they have already had their say. Neither is a fact about who they
 * are, and answering 403 would tell somebody they are barred from something
 * that a completed order would let them do tomorrow.
 *
 * A `DomainRefusal`, so neither is written to the log (ADR 0045): being told
 * "you have already reviewed this" is the marketplace working.
 */
final class ReviewNotAllowedException extends DomainRefusal
{
    public static function notBought(): self
    {
        return new self('You can review this once an order for it has arrived and you have confirmed it.');
    }

    public static function alreadyReviewed(): self
    {
        return new self('You have already reviewed this. You can change what you said instead.');
    }
}
