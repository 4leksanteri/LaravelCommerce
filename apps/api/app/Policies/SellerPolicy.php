<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Seller;
use App\Models\User;

/**
 * Who may do what to a shop.
 *
 * One place, so that the answer the API acts on and the answer it sends the
 * frontend for rendering are the same answer. `SellerResource` calls these
 * rather than restating the rules.
 */
final class SellerPolicy
{
    /**
     * Reading the review queue, which has no single shop to hang a check on.
     *
     * Laravel calls this for `authorize('viewAny', Seller::class)`. Before it
     * existed the admin listing checked `isPlatformStaff()` inline, which is
     * the same rule written in a second place - and a second place is where
     * the two get to disagree.
     */
    public function viewAny(User $user): bool
    {
        return $user->isPlatformStaff();
    }

    /**
     * Reading one shop as staff, rather than a queue of them (ADR 0060).
     *
     * The note that stood here said there was deliberately no `view` method,
     * because nothing called one - an owner reads their own shop through
     * /seller, staff read the queue through `viewAny`, and a shopper reads an
     * approved shop through the public scope - and that it would arrive with
     * the endpoint that needed it. The record of what the platform has decided
     * about a shop is that endpoint.
     *
     * Staff, and deliberately **without** the second condition `review` and
     * `suspend` apply. Those two are decisions about a shop, and nobody should
     * decide their own; reading is not a decision, and an owner can already see
     * everything in their own record through /seller. Refusing it here would be
     * a rule that protects nothing.
     */
    public function view(User $user, Seller $seller): bool
    {
        return $user->isPlatformStaff();
    }

    /**
     * Only the owner edits shop details, and staff deliberately cannot.
     *
     * Staff decide whether a shop may trade; they do not rewrite somebody's
     * shop description. Keeping those apart means a review cannot quietly
     * become an edit, and it means the audit question "who changed this" has
     * one answer.
     */
    public function update(User $user, Seller $seller): bool
    {
        return $user->id === $seller->user_id;
    }

    /** Reviewing is the platform's job, and never the applicant's own. */
    public function review(User $user, Seller $seller): bool
    {
        return $user->isPlatformStaff() && $user->id !== $seller->user_id;
    }

    /**
     * Stopping a shop trading, and letting it start again (ADR 0052).
     *
     * The same two conditions `review` applies, and deliberately its own method
     * rather than a reuse of it. They answer different questions - one is about
     * an application, the other about a business already running - and they
     * will stop agreeing the day staff stop being one undifferentiated group,
     * which ADR 0037 already lists as open.
     */
    public function suspend(User $user, Seller $seller): bool
    {
        return $user->isPlatformStaff() && $user->id !== $seller->user_id;
    }
}
