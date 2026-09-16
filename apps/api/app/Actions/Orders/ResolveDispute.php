<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Actions\Payments\RefundPayment;
use App\Actions\Payments\ReverseTransfer;
use App\Actions\Platform\RecordDecision;
use App\Enums\DecisionKind;
use App\Enums\DisputeResolution;
use App\Enums\OrderActor;
use App\Enums\OrderParty;
use App\Exceptions\DisputeNotAllowedException;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\User;
use App\Notifications\Orders\DisputeResolved;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The platform decides where the held money goes (ADR 0051).
 *
 * **Two outcomes, and now two places the money can already be** (ADR 0061). It
 * used to be one: a dispute existed only while the money was held, so refunded
 * meant cancel-and-refund and released meant complete-and-transfer. A dispute
 * can now be raised after completion, when the shop has already been paid, and
 * the same two words mean something different there:
 *
 * ```text
 *              money still held            money already at the shop
 * refunded     cancel, then refund         reverse, then refund - the order stays completed
 * released     complete, then transfer     nothing to do; it is already so
 * ```
 *
 * **A post-completion dispute does not cancel the order**, and that is not a
 * shortcut. `orders_timeline_check` refuses `cancelled` while `completed_at` is
 * set, and the way round it would be to clear `completed_at` - erasing that the
 * buyer confirmed, which is the erasure ADR 0060 exists to stop. The order did
 * complete; a later decision moved the money back. Both are true and both are
 * recorded.
 *
 * **Released on an order already paid for is a no-op, deliberately.** The
 * decision is still recorded and both sides are still told; there is simply no
 * money to move, and calling `CompleteOrder` on a completed order would throw.
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
        private readonly ReverseTransfer $reverseTransfer,
        private readonly RefundPayment $refund,
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

        $this->moveTheMoney($order, $resolution);

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

    /**
     * Where the money goes, which depends on where it already is (ADR 0061).
     *
     * Four cases rather than two, and the pair that is new are the ones where
     * the shop has already been paid: a refund has to come back off the
     * connected account first, and a release has nothing left to do.
     */
    private function moveTheMoney(Order $order, DisputeResolution $resolution): void
    {
        $alreadyPaidOut = $order->payment?->isTransferred() === true;

        if ($resolution === DisputeResolution::Released) {
            if ($alreadyPaidOut) {
                // The shop has it and the order is complete. Nothing to move,
                // and `CompleteOrder` would throw on an order in that state.
                return;
            }

            // Completion is what releases money to a shop, and `Staff` is what
            // records that the platform did it rather than the buyer or a
            // clock.
            $this->completeOrder->handle($order, OrderActor::Staff);

            return;
        }

        if (! $alreadyPaidOut) {
            $this->cancelOrder->cancelForDispute($order);

            return;
        }

        $this->returnWhatTheShopWasPaid($order);
    }

    /**
     * Pulls the transfer back, then refunds the buyer from the platform.
     *
     * **Two calls rather than one action**, because both already exist, both
     * are idempotent and both refuse quietly - which is what lets
     * `payments:settle` finish a pair that was interrupted between them
     * (ADR 0061). Wrapping them in something new would be the second way to pay
     * somebody that ADR 0041 refuses to have.
     *
     * Guarded, for the reason every other money call here is guarded: the
     * decision is recorded and committed, and a Stripe outage must not turn it
     * into an error the member of staff sees. The money is chased afterwards.
     */
    private function returnWhatTheShopWasPaid(Order $order): void
    {
        try {
            $this->reverseTransfer->handle($order);
            $this->refund->handle($order);
        } catch (Throwable $failure) {
            report($failure);
        }
    }
}
