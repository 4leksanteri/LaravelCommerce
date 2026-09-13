<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Dispute;
use App\Models\User;

/**
 * Who may decide a dispute.
 *
 * Platform staff, and never on an order they are a party to. That second half
 * is the same guard `SellerPolicy::review` applies to an application: a person
 * deciding where their own money goes is not a decision, and the prohibition
 * belongs in the rule rather than in a reviewer's judgement.
 *
 * There is deliberately no `view` here. Each party reads their own dispute
 * through their own order - the buyer through `$user->orders()`, the shop
 * through `$seller->orders()` - so the query already carries the rule, and a
 * policy method nothing asks is a rule nobody is applying (ADR 0008).
 */
final class DisputePolicy
{
    /** Reading the queue, which has no single dispute to hang a check on. */
    public function viewAny(User $user): bool
    {
        return $user->isPlatformStaff();
    }

    public function resolve(User $user, Dispute $dispute): bool
    {
        if (! $user->isPlatformStaff()) {
            return false;
        }

        $order = $dispute->order;

        return $user->id !== $order->user_id && $user->id !== $order->seller->user_id;
    }
}
