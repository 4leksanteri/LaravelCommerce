<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Exceptions\OrderTransitionNotAllowedException;
use App\Models\Order;
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
 * Nothing auto-completes yet. A buyer who never confirms leaves an order
 * shipped forever, which is a real gap and is recorded in ADR 0012.
 */
final class CompleteOrder
{
    /**
     * @throws OrderTransitionNotAllowedException
     */
    public function handle(Order $order): Order
    {
        return DB::transaction(function () use ($order): Order {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->canBeCompleted()) {
                throw OrderTransitionNotAllowedException::cannotComplete($locked->status);
            }

            $locked->forceFill([
                'status' => OrderStatus::Completed,
                'completed_at' => now(),
            ])->save();

            return $locked;
        });
    }
}
