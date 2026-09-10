<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Exceptions\OrderTransitionNotAllowedException;
use App\Models\Order;
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
        return DB::transaction(function () use ($order): Order {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->canBeShipped()) {
                throw OrderTransitionNotAllowedException::cannotShip($locked->status);
            }

            $locked->forceFill([
                'status' => OrderStatus::Shipped,
                'shipped_at' => now(),
            ])->save();

            return $locked;
        });
    }
}
