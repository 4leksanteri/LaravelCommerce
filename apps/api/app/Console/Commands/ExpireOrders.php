<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Orders\ExpireStaleOrders;
use App\Console\Commands\Concerns\RunsExclusively;
use Illuminate\Console\Command;

/**
 * Cancels pending orders nobody acted on, and gives their stock back.
 *
 * **This command knows nothing about what triggers it**, and that is the point
 * (ADR 0013). It runs once, does a bounded amount of work and exits with a
 * status code. Locally that is somebody typing it; in production it will be
 * Google Cloud Scheduler firing a Cloud Run Job with these arguments. Neither
 * is mentioned here, and changing the trigger changes nothing in this file.
 *
 * The schedule is deliberately **not** declared in `routes/console.php`. When
 * infrastructure owns the timing, a cron expression in the application as well
 * is a second description of one deployment - the same trap as a second `.env`
 * (root `CLAUDE.md` section 12), and the one that gets edited while nothing
 * changes.
 */
final class ExpireOrders extends Command
{
    use RunsExclusively;

    protected $signature = 'orders:expire {--limit=200 : How many to cancel in one run}';

    protected $description = 'Cancel pending orders that have waited too long, and return their stock';

    public function handle(ExpireStaleOrders $expireStaleOrders): int
    {
        return $this->exclusively('orders:expire', function () use ($expireStaleOrders): int {
            $hours = (int) config('orders.pending_expires_after_hours');
            $limit = (int) $this->option('limit');

            $report = $expireStaleOrders->handle(now()->subHours($hours), $limit);

            $this->components->info(sprintf(
                'Expired %d, skipped %d, failed %d (pending for more than %d hours).',
                $report['expired'],
                $report['skipped'],
                $report['failed'],
                $hours,
            ));

            // Non-zero when anything failed, so the scheduler shows it red and
            // retries. The orders that did cancel stay cancelled - the work is
            // idempotent, so a retry costs nothing.
            return $report['failed'] > 0 ? self::FAILURE : self::SUCCESS;
        });
    }
}
