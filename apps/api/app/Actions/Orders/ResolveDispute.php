<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Actions\Platform\RecordDecision;
use App\Enums\DecisionKind;
use App\Enums\DisputeResolution;
use App\Enums\OrderActor;
use App\Enums\OrderParty;
use App\Exceptions\DisputeNotAllowedException;
use App\Models\Dispute;
use App\Models\User;
use App\Notifications\Orders\DisputeResolved;
use Illuminate\Support\Facades\DB;

/**
 * The platform decides where the held money goes (ADR 0051).
 *
 * **Two outcomes, because there are two places the money can be.** Refunded
 * cancels the order and gives it back; released completes the order, which
 * transfers it to the shop less the fee. Both reuse the actions that already
 * do exactly that, so a dispute moves money by the same code path as an
 * ordinary cancellation or confirmation - there is no second way to pay
 * anybody.
 *
 * **The order ends either way.** That is what makes a second dispute
 * impossible, and why `disputes.order_id` is unique rather than merely indexed.
 *
 * The decision is recorded before the money moves. Both are committed
 * separately and on purpose: the money calls reach Stripe, and a network
 * failure there must not roll back a decision somebody made - `payments:settle`
 * picks up whatever did not go (ADR 0041).
 */
final class ResolveDispute
{
    public function __construct(
        private readonly CompleteOrder $completeOrder,
        private readonly CancelOrder $cancelOrder,
        private readonly RecordDecision $record,
    ) {}

    /**
     * @param  User  $by  the member of staff deciding it
     *
     * @throws DisputeNotAllowedException when somebody has already decided it
     */
    public function handle(
        Dispute $dispute,
        DisputeResolution $resolution,
        string $note,
        User $by,
    ): Dispute {
        $resolved = DB::transaction(function () use ($dispute, $resolution, $note, $by): Dispute {
            $locked = Dispute::query()->whereKey($dispute->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw DisputeNotAllowedException::alreadyResolved();
            }

            // All four move together, because the table refuses anything else.
            $locked->forceFill([
                'resolution' => $resolution,
                'resolution_note' => $note,
                'resolved_at' => now(),
                'resolved_by' => $by->id,
            ])->save();

            /*
             * On the shop's record, beside the decision rather than beside the
             * money (ADR 0060). The dispute row survives either way, unlike a
             * suspension - what it does not do is answer "what has this shop
             * been decided against before", because a dispute reaches a shop
             * only through its order.
             */
            $this->record->handle(
                $resolution === DisputeResolution::Refunded
                    ? DecisionKind::DisputeRefunded
                    : DecisionKind::DisputeReleased,
                $locked->order->seller,
                $locked,
                $note,
                $by,
            );

            return $locked;
        });

        $order = $resolved->order;

        if ($resolution === DisputeResolution::Refunded) {
            $this->cancelOrder->cancelForDispute($order);
        } else {
            // Completion is what releases money to a shop, and `Staff` is what
            // records that the platform did it rather than the buyer or a
            // clock.
            $this->completeOrder->handle($order, OrderActor::Staff);
        }

        /*
         * Both sides hear, and hear the same reasoning. One of them is worse
         * off than they hoped, and learning that from a bank statement instead
         * of from the marketplace is how a dispute becomes a complaint about
         * the marketplace.
         */
        $order->user->notify(new DisputeResolved($resolved, OrderParty::Buyer));
        $order->seller->notify(new DisputeResolved($resolved, OrderParty::Seller));

        return $resolved;
    }
}
