<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Exceptions\OrderTransitionNotAllowedException;
use App\Models\Order;
use App\Notifications\Orders\OrderAccepted;
use Illuminate\Support\Facades\DB;

/**
 * The seller commits to fulfilling an order.
 *
 * This is the moment the buyer stops being able to call it off on their own -
 * see `OrderStatus::canBeCancelledBy()` - so it is a decision being recorded
 * rather than a field being set, which is why the endpoint is a POST to an
 * `/acceptance` rather than a PATCH of `status`.
 *
 * Nothing about stock happens here. It was taken at checkout (ADR 0011);
 * accepting does not take it again.
 */
final class AcceptOrder
{
    /**
     * @throws OrderTransitionNotAllowedException
     */
    public function handle(Order $order): Order
    {
        $accepted = DB::transaction(function () use ($order): Order {
            // Locked so two clicks a moment apart cannot both pass the check
            // below and both write. Every transition takes this lock.
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->canBeAccepted()) {
                throw OrderTransitionNotAllowedException::cannotAccept($locked->status);
            }

            /*
             * And nobody accepts an order that has not been paid for
             * (ADR 0042).
             *
             * The shop's queue never shows one, so this is the belt to that
             * brace: a page left open while a card was refused, or a client
             * calling the endpoint directly. Read under the same lock as the
             * status, because a payment landing a moment later is exactly the
             * race this is here for.
             */
            if (! $locked->isPaid()) {
                throw OrderTransitionNotAllowedException::notPaid($locked->status);
            }

            $locked->forceFill([
                'status' => OrderStatus::Accepted,
                'accepted_at' => now(),
            ])->save();

            return $locked;
        });

        // The buyer learns the shop has committed (ADR 0035).
        $accepted->user->notify(new OrderAccepted($accepted));

        return $accepted;
    }
}
