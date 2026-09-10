<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Orders\AutoCompleteShippedOrders;
use App\Console\Commands\Concerns\RunsExclusively;
use Illuminate\Console\Command;

/**
 * Completes shipped orders on behalf of buyers who never confirmed.
 *
 * The second scheduled command, and it follows ADR 0013 exactly as the first
 * one does: it knows nothing about what triggers it, holds a lock for the
 * duration, does a bounded amount of work and exits with a status code.
 *
 * The deadline is read from each order rather than computed here, because a
 * buyer may have pushed it back - see `AutoCompleteShippedOrders`.
 */
final class AutoCompleteOrders extends Command
{
    use RunsExclusively;

    protected $signature = 'orders:auto-complete {--limit=200 : How many to complete in one run}';

    protected $description = 'Complete shipped orders whose buyer never confirmed receipt';

    public function handle(AutoCompleteShippedOrders $autoComplete): int
    {
        return $this->exclusively('orders:auto-complete', function () use ($autoComplete): int {
            $report = $autoComplete->handle(now(), (int) $this->option('limit'));

            $this->components->info(sprintf(
                'Completed %d, skipped %d, failed %d.',
                $report['completed'],
                $report['skipped'],
                $report['failed'],
            ));

            return $report['failed'] > 0 ? self::FAILURE : self::SUCCESS;
        });
    }
}
