<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Enums\DisputeResolution;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Payment;
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
        private readonly ReverseTransfer $reverse,
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
            /*
             * **Decided from the payment and the dispute, never from the
             * order's status** (ADR 0061). A dispute decided for the buyer
             * after completion leaves the order `completed` with its money at
             * the shop - and the old reading of that state was "transfer it",
             * which would have sent a second payment to a shop that was being
             * asked to give the first one back.
             */
            $owedBack = $this->owesTheBuyer($order);

            try {
                if ($owedBack) {
                    // Both are idempotent and both refuse quietly, so this
                    // finishes whichever half of the pair did not happen.
                    $this->reverse->handle($order);
                    $moved = $this->refund->handle($order);
                } else {
                    $moved = $order->status === OrderStatus::Completed
                        ? $this->transfer->handle($order)
                        : $this->refund->handle($order);
                }

                if ($moved === null) {
                    // Nothing was wrong: the shop cannot receive money yet, or
                    // the reversal has not cleared. The next run tries again.
                    $waiting++;

                    continue;
                }

                if (! $owedBack && $order->status === OrderStatus::Completed) {
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
     * Whether this order's money is owed back to its buyer (ADR 0061).
     *
     * A dispute decided `refunded` whose payment has not been refunded, however
     * far the pair got: the reversal may have failed, or the reversal may have
     * succeeded and the refund failed after it. Both look the same from here,
     * and both are finished by running the pair again.
     */
    private function owesTheBuyer(Order $order): bool
    {
        $payment = $order->payment;

        if (! $payment instanceof Payment || $payment->isRefunded() || ! $payment->isPaid()) {
            return false;
        }

        return $order->dispute?->resolution === DisputeResolution::Refunded;
    }

    /**
     * Orders whose money has not gone where it was decided it should.
     *
     * Two kinds, and the second is what ADR 0061 added:
     *
     * - finished one way or the other, and the money never moved at all;
     * - decided for the buyer in a dispute, and not yet refunded - which now
     *   includes orders whose money reached the shop, because it can be pulled
     *   back.
     *
     * **The second cannot be found by the first's query.** A reversal leaves
     * `transferred_at` set deliberately, so a reversed-but-unrefunded payment
     * fails `whereNull('transferred_at')` and would sit on the platform
     * forever, which is the one outcome worse than not reversing at all.
     *
     * Oldest first, so a backlog drains in the order it accumulated rather than
     * whichever rows the planner happened to return.
     *
     * @return Collection<int, Order>
     */
    private function outstanding(int $limit): Collection
    {
        return Order::query()
            ->where(function ($query): void {
                $query
                    ->where(function ($neverMoved): void {
                        $neverMoved
                            ->whereIn('status', [OrderStatus::Completed, OrderStatus::Cancelled])
                            ->whereHas('payment', function ($payment): void {
                                $payment->whereNotNull('paid_at')
                                    ->whereNull('transferred_at')
                                    ->whereNull('refunded_at');
                            });
                    })
                    ->orWhere(function ($owedBack): void {
                        $owedBack
                            ->whereHas('payment', function ($payment): void {
                                $payment->whereNotNull('paid_at')->whereNull('refunded_at');
                            })
                            /*
                             * `whereRelation` rather than a `whereHas` closure,
                             * and not for brevity.
                             *
                             * Inside a closure the analyser is handed a
                             * `Builder<Model>` and cannot check a column name
                             * against it - the trap `LeaveReview` and
                             * `Order::scopeWithUnreadMessagesFor` both record.
                             * A `@param Builder<Dispute>` does not rescue it
                             * either: written on an argument of a chained call
                             * the docblock binds to nothing, which is how this
                             * line failed twice before it was rewritten.
                             *
                             * Passing the column as an argument gives it a real
                             * model to be checked against. The `whereNull`
                             * calls above never tripped any of this, because
                             * any column name satisfies those.
                             */
                            ->whereRelation('dispute', 'resolution', DisputeResolution::Refunded->value);
                    });
            })
            ->with(['payment', 'dispute', 'seller.payoutAccount'])
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }
}
