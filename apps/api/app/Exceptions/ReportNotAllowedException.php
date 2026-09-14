<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * A report that cannot be made, or one that has already been decided
 * (ADR 0054).
 *
 * **409 in every case.** Somebody looking at a listing is entitled to report
 * it; a member of staff is entitled to decide one. What is in the way is that
 * they have already reported this, or that somebody else decided it first -
 * facts about the world rather than about who is asking (ADR 0008).
 *
 * Not reported to the log, like every `DomainRefusal` (ADR 0045). "You have
 * already reported this" is the marketplace working.
 */
final class ReportNotAllowedException extends DomainRefusal
{
    public static function alreadyReported(): self
    {
        return new self('You have already reported this, and we are still looking at it.');
    }

    /** Two members of staff reached the same report, and one of them lost. */
    public static function alreadyDecided(): self
    {
        return new self('This report has already been decided.');
    }

    /**
     * The thing reported has gone since.
     *
     * A morph carries no foreign key, so a seller deleting a flagged listing
     * between the report and the decision is an ordinary race rather than a
     * defensive hypothetical.
     */
    public static function subjectIsGone(): self
    {
        return new self('What this report is about no longer exists.');
    }
}
