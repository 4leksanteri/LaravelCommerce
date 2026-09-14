<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why somebody reported something (ADR 0054).
 *
 * A short list rather than free text, because the reason is what sorts a queue:
 * "this is counterfeit" and "this is abusive" go to different kinds of
 * attention, and a paragraph nobody can group by is a paragraph staff read one
 * at a time. The reporter's own words go in `note` beside it.
 *
 * Deliberately not exhaustive of every bad thing. Each case here is one the
 * platform can actually act on with what it has: take the listing down, hide
 * the review. "Other" carries the rest rather than pretending the list is
 * complete - and unlike `Carrier`, where an Other case would have meant
 * nothing, here it means "read the note".
 */
enum ReportReason: string
{
    /** Not what it claims to be: a fake, or not the item described. */
    case Counterfeit = 'counterfeit';

    /** Something this marketplace will not carry. */
    case Prohibited = 'prohibited';

    /** Abusive, or aimed at a person rather than at what was bought. */
    case Abusive = 'abusive';

    /** Advertising, repetition, or a review somebody was paid for. */
    case Spam = 'spam';

    /** Anything else. The note is the report. */
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Counterfeit => 'Counterfeit or not as described',
            self::Prohibited => 'Prohibited item',
            self::Abusive => 'Abusive',
            self::Spam => 'Spam',
            self::Other => 'Something else',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
