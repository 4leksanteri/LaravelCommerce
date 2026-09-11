<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a shop stands with getting paid.
 *
 * ```text
 * NotStarted ──open──▶ ActionRequired ◀─────────────────┐
 *                          │ everything sent            │ Stripe asks again
 *                          ▼                            │
 *                       InReview ──verified──▶ Active ──┘
 *                          │
 *                          └──▶ Rejected
 * ```
 *
 * Derived, never stored. `PayoutAccount::status()` reads it off the copy of
 * what Stripe last said, and NotStarted is simply the absence of an account.
 * A stored status would be a second copy of Stripe's answer, and the one that
 * goes stale when a webhook is missed.
 *
 * Active is not permanent. Stripe's rules change, and an account that was
 * verified can be asked for something new - which is why `account.updated`
 * is handled at all (ADR 0015).
 */
enum PayoutStatus: string
{
    case NotStarted = 'not_started';
    case ActionRequired = 'action_required';
    case InReview = 'in_review';
    case Active = 'active';
    case Rejected = 'rejected';
}
