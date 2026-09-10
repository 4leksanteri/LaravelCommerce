<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a shop is in review.
 *
 * ```text
 * Pending ──approve──▶ Approved
 *    │
 *    └────reject────▶ Rejected ──resubmit──▶ Pending
 * ```
 *
 * Approved is what makes a shop public. Nothing else does: there is no
 * separate `is_public` flag to fall out of step with this one.
 *
 * A rejected applicant may fix what was wrong and apply again, which is why
 * Rejected returns to Pending rather than being final. There is deliberately
 * no Suspended case yet - suspending a trading shop raises questions about
 * open orders and pending payouts that have no answer until those exist.
 */
enum SellerStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /** Whether a shop in this state is visible to shoppers. */
    public function isPublic(): bool
    {
        return $this === self::Approved;
    }

    /** Whether a decision has been recorded, either way. */
    public function isReviewed(): bool
    {
        return $this !== self::Pending;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
