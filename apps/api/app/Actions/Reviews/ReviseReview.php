<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Models\Review;

/**
 * Somebody changes their mind, or fixes a typo (ADR 0047).
 *
 * **Rewriting is allowed and deleting is not.** A verdict that cannot be
 * corrected is one people hesitate to leave, and a rating given in the first
 * week of owning something is often not the one they would give in the third.
 *
 * Removing it outright is a different question with a different answer: a shop
 * whose worst review can be argued away is a shop whose ratings mean nothing,
 * and that argument is between two parties this action cannot hear. ADR 0047
 * leaves it open rather than guessing.
 *
 * Nothing is stamped to say it changed. `updated_at` moving past `created_at`
 * is what says so, and `Review::wasEdited()` is where that is read.
 */
final class ReviseReview
{
    public function handle(Review $review, int $rating, ?string $body): Review
    {
        $review->forceFill([
            'rating' => $rating,
            'body' => $body,
        ])->save();

        return $review;
    }
}
