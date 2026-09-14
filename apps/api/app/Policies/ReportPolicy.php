<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Report;
use App\Models\User;

/**
 * Who may read and decide reports (ADR 0054).
 *
 * Platform staff, and never one they made themselves. That second half is the
 * same guard `SellerPolicy::review` and `DisputePolicy::resolve` apply, and for
 * the same reason: somebody deciding their own complaint is not deciding
 * anything.
 *
 * There is deliberately no `create`. Anybody signed in may report something
 * they can see, and what they can see is already settled by the storefront's
 * own scopes - a listing in an unapproved shop is a 404 long before a policy
 * would be asked. A method nothing calls is a rule nobody applies (ADR 0008).
 */
final class ReportPolicy
{
    /** Reading the queue, which has no single report to hang a check on. */
    public function viewAny(User $user): bool
    {
        return $user->isPlatformStaff();
    }

    public function decide(User $user, Report $report): bool
    {
        return $user->isPlatformStaff() && $user->id !== $report->user_id;
    }
}
