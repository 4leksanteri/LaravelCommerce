<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Payments\SettleOutstandingPayments;
use App\Console\Commands\Concerns\RunsExclusively;
use Illuminate\Console\Command;

/**
 * Sends money that should have moved when an order finished and did not.
 *
 * Completing an order transfers it to the shop and cancelling one refunds the
 * buyer, and both are allowed to fail quietly so that a Stripe outage cannot
 * turn a confirmation into an error (ADR 0041). This is the other half of that
 * bargain, and the only way a shop that verified with Stripe *after* making a
 * sale ever gets paid for it.
 *
 * **This command knows nothing about what triggers it** (ADR 0013). It runs
 * once, does a bounded amount of work and exits with a status code. Nothing in
 * this repository schedules it, exactly as with `orders:expire`.
 */
final class SettlePayments extends Command
{
    use RunsExclusively;

    protected $signature = 'payments:settle {--limit=200 : How many orders to settle in one run}';

    protected $description = 'Transfer or refund money for orders that finished without it moving';

    public function handle(SettleOutstandingPayments $settle): int
    {
        return $this->exclusively('payments:settle', function () use ($settle): int {
            $limit = (int) $this->option('limit');

            $report = $settle->handle($limit);

            $this->components->info(sprintf(
                'Transferred %d, refunded %d, waiting %d, failed %d.',
                $report['transferred'],
                $report['refunded'],
                $report['waiting'],
                $report['failed'],
            ));

            // Waiting is not failure: a shop that has not finished verifying
            // cannot be paid yet, and saying so red every run would make the
            // one that matters invisible.
            return $report['failed'] > 0 ? self::FAILURE : self::SUCCESS;
        });
    }
}
