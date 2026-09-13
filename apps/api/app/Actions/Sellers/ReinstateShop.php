<?php

declare(strict_types=1);

namespace App\Actions\Sellers;

use App\Enums\SellerStatus;
use App\Exceptions\ShopSuspensionNotAllowedException;
use App\Models\Seller;
use App\Notifications\Sellers\ShopReinstated;
use Illuminate\Support\Facades\DB;

/**
 * Lets a suspended shop trade again (ADR 0052).
 *
 * **Its own action rather than `ApproveSeller`**, which refuses anything
 * already reviewed - and a suspended shop is reviewed, because it was approved
 * before it was stopped. Routing a reinstatement through the review queue would
 * also lose that distinction: this shop is not an application being decided for
 * the first time.
 *
 * The suspension is cleared rather than kept, which is what
 * `sellers_suspension_is_whole` requires: the date, the reason and who recorded
 * it exist exactly while the status says suspended. That does mean there is no
 * history of past suspensions, which ADR 0052 writes down rather than solves.
 */
final class ReinstateShop
{
    /**
     * @throws ShopSuspensionNotAllowedException when the shop is not suspended
     */
    public function handle(Seller $seller): Seller
    {
        $reinstated = DB::transaction(function () use ($seller): Seller {
            $locked = Seller::whereKey($seller->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isSuspended()) {
                throw ShopSuspensionNotAllowedException::notSuspended($locked->status);
            }

            $locked->forceFill([
                'status' => SellerStatus::Approved,
                'suspended_at' => null,
                'suspension_reason' => null,
                'suspended_by' => null,
            ])->save();

            return $locked;
        });

        $reinstated->notify(new ShopReinstated($reinstated));

        return $reinstated;
    }
}
