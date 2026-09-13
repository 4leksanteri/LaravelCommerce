<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a shop is in review.
 *
 * ```text
 * Pending ──approve──▶ Approved ──suspend────▶ Suspended
 *    │                     ▲                       │
 *    │                     └─────reinstate─────────┘
 *    └────reject────▶ Rejected ──resubmit──▶ Pending
 * ```
 *
 * Approved is what makes a shop public. Nothing else does: there is no
 * separate `is_public` flag to fall out of step with this one, which is why
 * Suspended removes a shop from the storefront, its listings from search and
 * its photographs from the image route without any of them being told.
 *
 * A rejected applicant may fix what was wrong and apply again, which is why
 * Rejected returns to Pending rather than being final.
 *
 * **Suspended arrived with ADR 0052**, which is the day this docblock said
 * would come: it used to say suspending a trading shop "raises questions about
 * open orders and pending payouts that have no answer until those exist", and
 * those now exist. The answer it was waiting for is that suspension stops new
 * trade and touches neither - a shop still owes what it has already sold, and
 * a buyer whose money is held must still be able to confirm or dispute.
 *
 * **A suspension is not a rejection**, and the difference matters here: a
 * rejected application goes back to Pending when it is sent again, while a
 * suspended shop cannot re-apply at all. It is already reviewed; what it is
 * waiting for is the platform, not the queue.
 */
enum SellerStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Suspended = 'suspended';

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
