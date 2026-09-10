<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Exceptions\OrderTransitionNotAllowedException;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * The buyer says their parcel has not arrived yet.
 *
 * **Auto-completion is not safe without this.** A shipped order completes on a
 * deadline whether or not anything turned up, and once payments exist that
 * releases money for a parcel nobody received. A courier that is a week late is
 * ordinary; a marketplace that declares delivery because of it is not.
 *
 * It is deliberately not a dispute. The buyer is not claiming anything went
 * wrong - only that it has not gone right yet - and the cheapest honest answer
 * is more time.
 *
 * Capped, because otherwise it defers forever. What a buyer needs past the cap
 * is a dispute, and disputes are not built.
 */
final class ExtendCompletionDeadline
{
    /**
     * @throws OrderTransitionNotAllowedException
     */
    public function handle(Order $order): Order
    {
        return DB::transaction(function () use ($order): Order {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->canHaveDeadlineExtended()) {
                throw OrderTransitionNotAllowedException::cannotExtend($locked->status);
            }

            if (! $locked->canExtendCompletion()) {
                throw OrderTransitionNotAllowedException::noExtensionsLeft($locked->status);
            }

            $days = (int) config('orders.completion_extension_days');

            // Pushed from the deadline rather than from today, so that asking
            // early does not buy less time than asking late. The alternative
            // rewards leaving it until the last moment.
            $locked->forceFill([
                'auto_complete_at' => $locked->auto_complete_at?->copy()->addDays($days),
                'completion_extensions' => $locked->completion_extensions + 1,
            ])->save();

            return $locked;
        });
    }
}
