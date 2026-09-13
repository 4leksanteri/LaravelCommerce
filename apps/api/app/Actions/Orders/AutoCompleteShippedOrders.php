<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Models\Dispute;
use App\Models\Order;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Completes shipped orders whose buyer never confirmed, on the deadline they
 * were given.
 *
 * Buyers forget. An order that stays `shipped` forever never releases a payout,
 * so somebody has to close it - and the only honest way to do that without
 * letting sellers complete their own sales is a clock.
 *
 * **The deadline is read from the order, not computed here.** `auto_complete_at`
 * is set when it ships and moves when the buyer says their parcel is late
 * (ADR 0014), so a run that recomputed it from `shipped_at` would ignore every
 * extension ever granted.
 *
 * Same two properties as every scheduled action (ADR 0013): idempotent, because
 * completing an order makes it no longer shipped, and bounded.
 */
final class AutoCompleteShippedOrders
{
    public function __construct(private readonly CompleteOrder $completeOrder) {}

    /**
     * @return array{completed: int, skipped: int, failed: int}
     */
    public function handle(CarbonInterface $asOf, int $limit): array
    {
        $completed = 0;
        $skipped = 0;
        $failed = 0;

        /*
         * **An order being argued about does not complete on a clock**
         * (ADR 0051). Completing it would transfer the money to the shop for
         * the very thing the buyer says never arrived, and a transfer is not
         * pulled back - so the deadline is what a dispute has to stop.
         *
         * The deadline itself is left where it is rather than cleared: the
         * database requires a shipped order to have one, and both parties
         * should still see the date it would otherwise have completed on.
         *
         * A subquery rather than `whereDoesntHave`, for the reason `LeaveReview`
         * gives: inside a relation closure the analyser is handed a
         * `Builder<Model>` and cannot check a column name against it.
         */
        $disputed = Dispute::query()->whereNull('resolved_at')->select('order_id');

        $due = Order::query()
            ->where('status', OrderStatus::Shipped)
            ->where('auto_complete_at', '<=', $asOf)
            ->whereNotIn('id', $disputed)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($due as $order) {
            try {
                $this->completeOrder->handle($order, OrderActor::Deadline);
                $completed++;
            } catch (Throwable $exception) {
                // A seller cancelling a lost shipment, or a buyer confirming,
                // between the query above and the lock inside `CompleteOrder`.
                // Either makes this order no longer due, which is an ordinary
                // race rather than a failure - so it is counted and not
                // reported.
                if ($order->fresh()?->status->isFinal() === true) {
                    $skipped++;

                    continue;
                }

                $failed++;
                report($exception);
            }
        }

        return ['completed' => $completed, 'skipped' => $skipped, 'failed' => $failed];
    }
}
