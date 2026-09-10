<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Orders\ExpireStaleOrders;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

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
    protected $signature = 'orders:expire {--limit=200 : How many to cancel in one run}';

    protected $description = 'Cancel pending orders that have waited too long, and return their stock';

    /**
     * Ten minutes, so a run killed mid-flight releases the lock rather than
     * blocking every later run until somebody notices.
     */
    private const int LOCK_SECONDS = 600;

    public function handle(ExpireStaleOrders $expireStaleOrders): int
    {
        $lock = Cache::lock('orders:expire', self::LOCK_SECONDS);

        // The cache store is PostgreSQL, so this holds across replicas and
        // across whatever fires the command. Laravel's own
        // `withoutOverlapping()` would not: it only applies when Laravel's
        // scheduler is the thing running it, and here it will not be.
        if (! $lock->get()) {
            $this->components->info('Another run holds the lock. Nothing to do.');

            // Not a failure. At-least-once delivery means overlapping runs are
            // expected, and a red mark for one would train people to ignore it.
            return self::SUCCESS;
        }

        try {
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
        } finally {
            $lock->release();
        }
    }
}
