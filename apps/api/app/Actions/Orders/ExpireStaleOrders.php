<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Cancels orders that have waited too long for their seller, and gives the
 * stock back.
 *
 * The decision, separated from the command that triggers it. A console command
 * is the same layer as a controller (root `CLAUDE.md` section 5) - it
 * coordinates and reports, and the rule lives here where it can be tested
 * without a scheduler.
 *
 * Two properties this has to have, because whatever triggers it in production
 * will do so **at least** once and possibly twice:
 *
 * - **Idempotent.** Only pending orders are touched, and cancelling one makes
 *   it no longer pending. A second run over the same window finds nothing.
 * - **Bounded.** A batch is capped, so a backlog cannot produce a run that
 *   exceeds a job timeout and gets killed halfway through.
 *
 * One order per transaction, and a failure on one does not abandon the rest -
 * a single order that will not cancel should not stop the other hundred, and
 * the caller is told how many failed so it can exit non-zero.
 */
final class ExpireStaleOrders
{
    public function __construct(private readonly CancelOrder $cancelOrder) {}

    /**
     * @param  CarbonInterface  $placedBefore  orders older than this are stale
     * @return array{expired: int, skipped: int, failed: int}
     */
    public function handle(CarbonInterface $placedBefore, int $limit): array
    {
        $expired = 0;
        $skipped = 0;
        $failed = 0;

        // Oldest first, so a backlog drains in the order it accumulated rather
        // than whichever rows the planner happened to return.
        $stale = Order::query()
            ->where('status', OrderStatus::Pending)
            ->where('created_at', '<', $placedBefore)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($stale as $order) {
            try {
                // False means a seller accepted it between the query above and
                // the lock inside. An ordinary race, not a failure.
                if ($this->cancelOrder->expire($order)) {
                    $expired++;
                } else {
                    $skipped++;
                }
            } catch (Throwable $exception) {
                $failed++;
                report($exception);
            }
        }

        return ['expired' => $expired, 'skipped' => $skipped, 'failed' => $failed];
    }
}
