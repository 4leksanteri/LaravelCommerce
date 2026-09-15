<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * An appeal that cannot be raised, or one that has already been decided
 * (ADR 0059).
 *
 * **409 in every case.** Somebody whose shop was stopped is entitled to answer
 * back, and a member of staff is entitled to decide; what is in the way is that
 * there is nothing to appeal, or that somebody got there first. Those are facts
 * about the world rather than about who is asking (ADR 0008).
 *
 * Not reported to the log, like every `DomainRefusal` (ADR 0045).
 */
final class AppealNotAllowedException extends DomainRefusal
{
    /**
     * The thing is not stopped, so there is nothing to answer back about.
     *
     * A listing that was never taken down, a shop that is trading, a review
     * nobody hid. Appealing one would be a complaint with no decision behind
     * it, and an upheld one would have nothing to lift.
     */
    public static function nothingToAppeal(): self
    {
        return new self('There is nothing to appeal here: this has not been stopped.');
    }

    /** One open appeal at a time, which the partial unique index enforces. */
    public static function alreadyAppealing(): self
    {
        return new self('You have already appealed this, and we are still looking at it.');
    }

    /** Two members of staff reached the same appeal, and one of them lost. */
    public static function alreadyDecided(): self
    {
        return new self('This appeal has already been decided.');
    }

    /**
     * What was appealed has gone since.
     *
     * A seller deleting the listing they were appealing about is an ordinary
     * thing to do while waiting, and a morph carries no foreign key to stop
     * them. Upholding then has nothing to put back; dismissing is still fine.
     */
    public static function subjectIsGone(): self
    {
        return new self('What this appeal is about no longer exists.');
    }
}
