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
    /** A shop owner sees their own shop at any status; staff see any shop. */
    public function view(User $user, Seller $seller): bool
    {
        return $user->id === $seller->user_id || $user->isPlatformStaff();
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
}
