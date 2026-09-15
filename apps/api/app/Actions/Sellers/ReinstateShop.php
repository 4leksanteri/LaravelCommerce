<?php

declare(strict_types=1);

namespace App\Actions\Sellers;

use App\Actions\Platform\RecordDecision;
use App\Enums\DecisionKind;
use App\Enums\SellerStatus;
use App\Exceptions\ShopSuspensionNotAllowedException;
use App\Models\Seller;
use App\Models\User;
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
 * it exist exactly while the status says suspended.
 *
 * **That is why this records what it is erasing** (ADR 0060). Clearing those
 * three columns is the only copy of the suspension gone, so a shop stopped
 * three times would otherwise read as one never stopped at all. Both halves go
 * on the record: the suspension when it happens, and this lifting it.
 *
 * It takes the member of staff for the same reason. Until ADR 0060 nothing
 * recorded who let a shop back - `suspended_by` names who stopped it, and then
 * gets nulled.
 */
final class ReinstateShop
{
    public function __construct(private readonly RecordDecision $record) {}

    /**
     * @param  User  $staff  who let it back: a member of staff, or whoever upheld an appeal
     *
     * @throws ShopSuspensionNotAllowedException when the shop is not suspended
     */
    public function handle(Seller $seller, User $staff): Seller
    {
        $reinstated = DB::transaction(function () use ($seller, $staff): Seller {
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

            $this->record->handle(
                DecisionKind::ShopReinstated,
                $locked,
                $locked,
                null,
                $staff,
            );

            return $locked;
        });

        $reinstated->notify(new ShopReinstated($reinstated));

        return $reinstated;
    }
}
