<?php

declare(strict_types=1);

namespace App\Actions\Payouts;

use App\Models\PayoutAccount;
use Illuminate\Support\Carbon;
use Stripe\Account;
use Stripe\StripeClient;

/**
 * Copies what Stripe says about a connected account onto the row that mirrors
 * it, and saves the row.
 *
 * Every change this application makes gets the updated account back from
 * Stripe, and that is passed straight in. A webhook passes nothing, and the
 * account is **fetched again** rather than read out of the event: Stripe does
 * not promise to deliver events in order, so an `account.updated` that arrives
 * late carries an account older than the copy already stored. Asking for the
 * current one makes the order they arrive in irrelevant.
 *
 * The requirements are copied raw. `PayoutAccount` is the one place that
 * interprets them, so there is one reading of Stripe's lists rather than two.
 */
final class SyncPayoutAccount
{
    public function __construct(private readonly StripeClient $stripe) {}

    public function handle(PayoutAccount $account, ?Account $current = null): PayoutAccount
    {
        $current ??= $this->stripe->accounts->retrieve($account->stripe_account_id);

        $snapshot = $current->toArray();
        $requirements = is_array($snapshot['requirements'] ?? null) ? $snapshot['requirements'] : [];
        $capabilities = is_array($snapshot['capabilities'] ?? null) ? $snapshot['capabilities'] : [];
        $deadline = $requirements['current_deadline'] ?? null;

        $account->forceFill([
            'transfers_status' => is_string($capabilities['transfers'] ?? null) ? $capabilities['transfers'] : 'inactive',
            'payouts_enabled' => ($snapshot['payouts_enabled'] ?? false) === true,
            'requirements' => [
                'currently_due' => $requirements['currently_due'] ?? [],
                'past_due' => $requirements['past_due'] ?? [],
                'errors' => $requirements['errors'] ?? [],
            ],
            'disabled_reason' => is_string($requirements['disabled_reason'] ?? null) ? $requirements['disabled_reason'] : null,
            'requirements_due_at' => is_int($deadline) ? Carbon::createFromTimestamp($deadline) : null,
            'bank_account_last4' => $this->bankAccountLast4($snapshot),
            'synced_at' => now(),
        ])->save();

        return $account;
    }

    /**
     * The last four digits of where payouts go.
     *
     * The default for its currency when Stripe marks one, and otherwise the
     * first - which, with one IBAN collected, is the only one.
     *
     * @param  array<array-key, mixed>  $snapshot
     */
    private function bankAccountLast4(array $snapshot): ?string
    {
        $externalAccounts = is_array($snapshot['external_accounts'] ?? null) ? $snapshot['external_accounts'] : [];
        $candidates = is_array($externalAccounts['data'] ?? null) ? $externalAccounts['data'] : [];

        $chosen = null;

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            if (($candidate['default_for_currency'] ?? false) === true) {
                $chosen = $candidate;

                break;
            }

            $chosen ??= $candidate;
        }

        $last4 = $chosen['last4'] ?? null;

        return is_string($last4) ? $last4 : null;
    }
}
