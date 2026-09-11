<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\OrderActor;
use App\Enums\OrderParty;
use App\Enums\OrderStatus;
use App\Exceptions\OrderTransitionNotAllowedException;
use App\Models\Order;
use App\Notifications\Orders\OrderCompleted;
use Illuminate\Support\Facades\DB;

/**
 * The buyer confirms they received it.
 *
 * **Only the buyer, and this is the rule with the most riding on it.**
 * Completion is what will eventually release a payout, and a seller who could
 * complete their own order could declare their own money releasable - which is
 * the one thing an escrow marketplace exists to prevent. There is no seller
 * endpoint for this and there must not be one.
 *
 * The enforcement is structural rather than a check: completion is reachable
 * only through the buyer's own routes, which resolve orders through
 * `$user->orders()`. A seller asking for their own sale there is asking for
 * something they did not buy, and gets a 404.
 *
 * The one other way an order completes is its deadline passing, which
 * `orders:auto-complete` does through this same action on the buyer's behalf
 * (ADR 0014). Which of the two it was is recorded, and told (ADR 0035): the shop
 * hears either way, and the buyer hears when it was the deadline, because then
 * nobody chose it.
 */
final class CompleteOrder
{
    /**
     * @param  OrderActor  $by  the buyer, or the deadline on their behalf
     *
     * @throws OrderTransitionNotAllowedException
     */
    public function handle(Order $order, OrderActor $by): Order
    {
        $completed = DB::transaction(function () use ($order, $by): Order {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->canBeCompleted()) {
                throw OrderTransitionNotAllowedException::cannotComplete($locked->status);
            }

            $locked->forceFill([
                'status' => OrderStatus::Completed,
                'completed_at' => now(),
                'completed_by' => $by,
            ])->save();

            return $locked;
        });

        $completed->seller->notify(new OrderCompleted($completed, OrderParty::Seller));

        if ($by === OrderActor::Deadline) {
            $completed->user->notify(new OrderCompleted($completed, OrderParty::Buyer));
        }

        return $completed;
    }
}
