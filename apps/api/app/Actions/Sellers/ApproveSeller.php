<?php

declare(strict_types=1);

namespace App\Actions\Sellers;

use App\Enums\SellerStatus;
use App\Exceptions\SellerAlreadyReviewedException;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Records a decision to let a shop trade.
 *
 * This is the moment the shop becomes public, so it is also the moment worth
 * being careful about: two reviewers opening the queue at the same time must
 * not both record a decision.
 */
final class ApproveSeller
{
    /**
     * @throws SellerAlreadyReviewedException
     */
    public function handle(Seller $seller, User $reviewer): Seller
    {
        return DB::transaction(function () use ($seller, $reviewer): Seller {
            // Re-read the row with a lock held, and check the state again
            // inside it. The status read before the transaction started is a
            // fact about the past: another reviewer may have decided in
            // between, and the person who loses that race must be told, not
            // silently made the author of a decision somebody else took.
            $locked = Seller::whereKey($seller->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status->isReviewed()) {
                throw new SellerAlreadyReviewedException($locked->status);
            }

            $locked->forceFill([
                'status' => SellerStatus::Approved,
                'rejection_reason' => null,
                'reviewed_at' => now(),
                'reviewed_by' => $reviewer->id,
            ])->save();

            return $locked;
        });
    }
}
