<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Review;
use App\Models\User;

/**
 * Who may change a review.
 *
 * Its author, and nobody else. Not the shop it is about, which is the whole
 * point of it; not platform staff, who have no endpoint that touches one and
 * would need a policy method the day they do (ADR 0008 deletes a method nothing
 * calls).
 *
 * There is no `view`: reviews are public, and a listing's reviews are read
 * through the storefront's own scopes, which already refuse a draft or an
 * unapproved shop.
 */
final class ReviewPolicy
{
    public function update(User $user, Review $review): bool
    {
        return $review->user_id === $user->id;
    }
}
