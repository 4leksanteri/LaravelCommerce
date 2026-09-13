<?php

declare(strict_types=1);

namespace App\Actions\Sellers;

use App\Enums\SellerStatus;
use App\Exceptions\ShopSuspensionNotAllowedException;
use App\Models\Seller;
use App\Models\User;
use App\Notifications\Sellers\ShopSuspended;
use Illuminate\Support\Facades\DB;

/**
 * Stops a trading shop, and says why (ADR 0052).
 *
 * **Only an open shop can be stopped.** Suspending a pending application would
 * be a rejection wearing another name, and suspending a rejected one stops
 * nothing - neither is trading.
 *
 * **It stops new trade and touches nothing already agreed.** The shop keeps its
 * orders, its payouts and its access to both: it still owes what it has already
 * sold, and a buyer whose money is held must still be able to confirm the
 * parcel arrived or dispute it. Cancelling its open orders would punish the
 * buyers rather than the shop.
 *
 * What it does stop falls out of one case. `Seller::scopePublic()` asks for
 * `status = approved`, so the shop leaves the storefront, its listings leave
 * search and browse, its photographs stop being served, and `PublishProduct`
 * refuses - none of which is written here, and none of which can be forgotten.
 */
final class SuspendShop
{
    /**
     * @throws ShopSuspensionNotAllowedException when the shop is not trading
     */
    public function handle(Seller $seller, User $staff, string $reason): Seller
    {
        $suspended = DB::transaction(function () use ($seller, $staff, $reason): Seller {
            // Locked and re-checked inside the transaction, for the reason
            // ApproveSeller gives: the status read before it started is a fact
            // about the past.
            $locked = Seller::whereKey($seller->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isPublic()) {
                throw ShopSuspensionNotAllowedException::notTrading($locked->status);
            }

            // `reviewed_at` and `reviewed_by` are left alone. They record that
            // this shop was approved, which stays true - a suspension is a
            // later event, not a rewriting of the first decision.
            $locked->forceFill([
                'status' => SellerStatus::Suspended,
                'suspended_at' => now(),
                'suspension_reason' => $reason,
                'suspended_by' => $staff->id,
            ])->save();

            return $locked;
        });

        // With the reason, which is the only thing the shop has to go on.
        $suspended->notify(new ShopSuspended($suspended));

        return $suspended;
    }
}
