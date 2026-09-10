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

    /*
     * There is deliberately no `view` method.
     *
     * Nothing calls one: an owner reads their own shop through /seller, staff
     * read the queue through `viewAny`, and a shopper reads an approved shop
     * through the public scope. A policy method nothing asks is a rule nobody
     * is applying, and it reads as though somebody is.
     *
     * It arrives with the endpoint that needs it.
     */

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
}
