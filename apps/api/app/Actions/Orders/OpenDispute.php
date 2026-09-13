<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Exceptions\DisputeNotAllowedException;
use App\Models\Dispute;
use App\Models\Order;
use App\Notifications\Orders\DisputeOpened;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The buyer says their order did not arrive, or did not arrive as described
 * (ADR 0051).
 *
 * **The window is exactly as wide as the money is held.** An order has to have
 * shipped - before that there is nothing to have gone wrong with, and the buyer
 * can simply cancel - and the payment has to be held, which is
 * `Payment::isHeld()`: paid, not refunded, not yet transferred. Once the money
 * has reached the shop, sending it back is a Stripe reversal, and ADR 0041
 * deliberately does not build one.
 *
 * **Opening one stops the clock.** `AutoCompleteShippedOrders` skips an order
 * with an open dispute, so the deadline cannot release the money for the very
 * thing being argued about. That is the whole mechanism; the deadline itself is
 * left alone.
 *
 * It is deliberately the successor to `ExtendCompletionDeadline`, which says so
 * itself: "What a buyer needs past the cap is a dispute." Asking for more time
 * is not a complaint, and this is.
 */
final class OpenDispute
{
    /**
     * @throws DisputeNotAllowedException when the money is not held, or the
     *                                    order has been disputed already
     */
    public function handle(Order $order, string $reason): Dispute
    {
        $dispute = DB::transaction(function () use ($order, $reason): Dispute {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            /*
             * Asked in this order so each refusal says the true thing. A
             * second dispute and an order whose money has settled are both
             * "no", and telling somebody the wrong one sends them looking for
             * a problem they do not have.
             */
            if ($locked->dispute()->exists()) {
                throw DisputeNotAllowedException::alreadyDisputed();
            }

            if (! $locked->canBeDisputed()) {
                throw DisputeNotAllowedException::nothingToDispute();
            }

            $raised = new Dispute;

            $raised->forceFill([
                'order_id' => $locked->id,
                'reason' => $reason,
            ]);

            try {
                $raised->save();
            } catch (UniqueConstraintViolationException) {
                /*
                 * The unique index is the guard rather than a read before the
                 * write, for the reason `LeaveReview` gives: two requests
                 * arriving together both find nothing and both insert, and the
                 * second is refused by the only party that can see both.
                 */
                throw DisputeNotAllowedException::alreadyDisputed();
            }

            // The notification reads the order, and this is the copy that was
            // just locked and checked.
            $raised->setRelation('order', $locked);

            return $raised;
        });

        // The shop is told at once: this holds money that was about to be
        // theirs, and taking it quietly would be worse than the dispute.
        $order->seller->notify(new DisputeOpened($dispute));

        return $dispute;
    }
}
