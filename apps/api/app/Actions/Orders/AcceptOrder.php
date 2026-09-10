<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Exceptions\OrderTransitionNotAllowedException;
use App\Models\Order;
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
        return DB::transaction(function () use ($order): Order {
            // Locked so two clicks a moment apart cannot both pass the check
            // below and both write. Every transition takes this lock.
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->canBeAccepted()) {
                throw OrderTransitionNotAllowedException::cannotAccept($locked->status);
            }

            $locked->forceFill([
                'status' => OrderStatus::Accepted,
                'accepted_at' => now(),
            ])->save();

            return $locked;
        });
    }
}
