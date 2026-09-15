<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Appeal;
use App\Models\User;

/**
 * Who may read and decide appeals (ADR 0059).
 *
 * Platform staff, and never one they raised themselves. That second half is the
 * same guard `ReportPolicy::decide` and `DisputePolicy::resolve` apply, and for
 * the same reason: somebody deciding their own case is not deciding anything.
 *
 * There is deliberately no `create`. Who may appeal is settled by the lookup -
 * each endpoint resolves the subject through the caller's own shop, listing or
 * review - so a policy method here could never refuse anything, and a rule
 * nobody applies reads as though it applies (ADR 0008).
 */
final class AppealPolicy
{
    /** Reading the queue, which has no single appeal to hang a check on. */
    public function viewAny(User $user): bool
    {
        return $user->isPlatformStaff();
    }

    public function decide(User $user, Appeal $appeal): bool
    {
        return $user->isPlatformStaff() && $user->id !== $appeal->user_id;
    }
}
