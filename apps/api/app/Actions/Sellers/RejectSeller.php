<?php

declare(strict_types=1);

namespace App\Actions\Sellers;

use App\Enums\SellerStatus;
use App\Exceptions\SellerAlreadyReviewedException;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Records a decision not to let a shop trade, and why.
 *
 * The reason is required and is shown to the applicant. A rejection without
 * one tells somebody no without telling them what to fix, and they either give
 * up or resubmit the same application. The table refuses it too.
 */
final class RejectSeller
{
    /**
     * @throws SellerAlreadyReviewedException
     */
    public function handle(Seller $seller, User $reviewer, string $reason): Seller
    {
        return DB::transaction(function () use ($seller, $reviewer, $reason): Seller {
            // Locked and re-checked inside the transaction, for the reason
            // ApproveSeller gives.
            $locked = Seller::whereKey($seller->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status->isReviewed()) {
                throw new SellerAlreadyReviewedException($locked->status);
            }

            $locked->forceFill([
                'status' => SellerStatus::Rejected,
                'rejection_reason' => $reason,
                'reviewed_at' => now(),
                'reviewed_by' => $reviewer->id,
            ])->save();

            return $locked;
        });
    }
}
