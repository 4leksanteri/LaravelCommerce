<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * The half of ADR 0013's contract that every scheduled command owes and none
 * should have to remember.
 *
 * A scheduler delivers **at least** once: a lost response looks exactly like a
 * run that never happened, so it fires again. Two runs must not work the same
 * rows, and this is what stops them.
 *
 * Laravel's `withoutOverlapping()` does not do this job. It applies when
 * Laravel's scheduler is running the command, and in production it will not be
 * - the timing lives in infrastructure.
 *
 * The lock is a cache lock, and the cache store is PostgreSQL, so it holds
 * across replicas and across whatever fired the command.
 */
trait RunsExclusively
{
    /**
     * Ten minutes, so a run killed mid-flight releases the lock rather than
     * blocking every later run until somebody notices.
     */
    private const int LOCK_SECONDS = 600;

    /**
     * @param  Closure(): int  $work
     */
    private function exclusively(string $key, Closure $work): int
    {
        $lock = Cache::lock($key, self::LOCK_SECONDS);

        if (! $lock->get()) {
            $this->components->info('Another run holds the lock. Nothing to do.');

            // **Not a failure.** Overlapping runs are expected under
            // at-least-once delivery, and a red mark for something normal
            // trains people to ignore red marks.
            return Command::SUCCESS;
        }

        try {
            return $work();
        } finally {
            $lock->release();
        }
    }
}
