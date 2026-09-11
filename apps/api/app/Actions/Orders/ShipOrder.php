<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Exceptions\OrderTransitionNotAllowedException;
use App\Models\Order;
use App\Notifications\Orders\OrderShipped;
use Illuminate\Support\Facades\DB;

/**
 * The seller sends it.
 *
 * Only from `accepted`, and deliberately not straight from `pending`: shipping
 * something nobody has committed to skips the point in the timeline where the
 * buyer could still have called it off. A seller in a hurry accepts and ships
 * in two requests, which costs them nothing and keeps the record honest.
 *
 * There is no tracking number and no carrier, because there is no delivery
 * address either. Those arrive together.
 */
final class ShipOrder
{
    /**
     * @throws OrderTransitionNotAllowedException
     */
    public function handle(Order $order): Order
    {
        $shipped = DB::transaction(function () use ($order): Order {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->canBeShipped()) {
                throw OrderTransitionNotAllowedException::cannotShip($locked->status);
            }

            $shippedAt = now();

            $locked->forceFill([
                'status' => OrderStatus::Shipped,
                'shipped_at' => $shippedAt,

                // The clock the buyer is now on. Stored rather than computed,
                // because it moves when they say their parcel is late and
                // because both sides should be able to see the date rather
                // than a window in a config file (ADR 0014).
                'auto_complete_at' => $shippedAt->copy()
                    ->addDays((int) config('orders.auto_complete_after_days')),
            ])->save();

            return $locked;
        });

        // The buyer learns it is on its way, and the date it completes on its
        // own if they say nothing (ADR 0035).
        $shipped->user->notify(new OrderShipped($shipped));

        return $shipped;
    }
}
