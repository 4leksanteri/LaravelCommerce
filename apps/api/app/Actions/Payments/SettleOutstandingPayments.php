<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Money that should have moved and did not.
 *
 * Completion transfers and cancellation refunds, and both are deliberately
 * allowed to fail quietly: the order has already changed hands by then, and a
 * Stripe outage must not turn a buyer's confirmation into an error (ADR 0041).
 * The cost of that choice is money left sitting on the platform, and this is
 * what collects it.
 *
 * It is also the only path for a shop that finished its Stripe verification
 * after selling something. The transfer could not run at the time; nothing
 * about the order needs to change for it to run now.
 *
 * The three properties a scheduled action needs (ADR 0013):
 *
 * - **Idempotent.** Only a payment that is held is touched, and moving one
 *   makes it no longer held. A second run over the same window finds nothing.
 * - **Bounded.** A batch is capped, so a backlog cannot outrun a job timeout.
 * - **Independent.** One order failing does not abandon the rest, and the
 *   caller is told how many failed so it can exit non-zero.
 */
final class SettleOutstandingPayments
{
    public function __construct(
        private readonly TransferToShop $transfer,
        private readonly RefundPayment $refund,
    ) {}

    /**
     * @return array{transferred: int, refunded: int, waiting: int, failed: int}
     */
    public function handle(int $limit): array
    {
        $transferred = 0;
        $refunded = 0;
        $waiting = 0;
        $failed = 0;

        foreach ($this->outstanding($limit) as $order) {
            $completed = $order->status === OrderStatus::Completed;

            try {
                $moved = $completed
                    ? $this->transfer->handle($order)
                    : $this->refund->handle($order);

                if ($moved === null) {
                    // Nothing was wrong: the shop cannot receive money yet, and
                    // the next run will try again.
                    $waiting++;

                    continue;
                }

                if ($completed) {
                    $transferred++;
                } else {
                    $refunded++;
                }
            } catch (Throwable $failure) {
                report($failure);
                $failed++;
            }
        }

        return [
            'transferred' => $transferred,
            'refunded' => $refunded,
            'waiting' => $waiting,
            'failed' => $failed,
        ];
    }

    /**
     * Orders that have finished one way or the other and whose money has not
     * followed.
     *
     * Oldest first, so a backlog drains in the order it accumulated rather than
     * whichever rows the planner happened to return.
     *
     * @return Collection<int, Order>
     */
    private function outstanding(int $limit): Collection
    {
        return Order::query()
            ->whereIn('status', [OrderStatus::Completed, OrderStatus::Cancelled])
            ->whereHas('payment', function ($query): void {
                $query->whereNotNull('paid_at')
                    ->whereNull('transferred_at')
                    ->whereNull('refunded_at');
            })
            ->with(['payment', 'seller.payoutAccount'])
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }
}
