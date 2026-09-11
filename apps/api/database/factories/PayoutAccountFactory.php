<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PayoutAccount;
use App\Models\Seller;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A payout account as Stripe might describe it, without asking Stripe.
 *
 * The default is an account straight after opening: Stripe wants the seller's
 * details, and nothing else has happened yet.
 *
 * @extends Factory<PayoutAccount>
 */
final class PayoutAccountFactory extends Factory
{
    /**
     * @return array<model-property<PayoutAccount>, mixed>
     */
    public function definition(): array
    {
        return [
            'seller_id' => Seller::factory()->approved(),
            'stripe_account_id' => 'acct_'.Str::random(16),
            'country' => 'FI',
            'transfers_status' => 'inactive',
            'payouts_enabled' => false,
            'requirements' => [
                'currently_due' => ['individual.first_name', 'individual.last_name', 'external_account'],
                'past_due' => [],
                'errors' => [],
            ],
            'disabled_reason' => 'requirements.past_due',
            'requirements_due_at' => null,
            'bank_account_last4' => null,
            'terms_accepted_at' => now(),
            'terms_accepted_ip' => '127.0.0.1',
            'terms_accepted_user_agent' => 'Symfony',
            'synced_at' => now(),
        ];
    }

    /** Everything sent, and Stripe still checking it. */
    public function inReview(): static
    {
        return $this->state(fn (): array => [
            'transfers_status' => 'pending',
            'requirements' => ['currently_due' => [], 'past_due' => [], 'errors' => []],
            'disabled_reason' => 'requirements.pending_verification',
            'bank_account_last4' => '0785',
        ]);
    }

    /** Verified: Stripe accepts transfers into it and pays them out. */
    public function active(): static
    {
        return $this->state(fn (): array => [
            'transfers_status' => 'active',
            'payouts_enabled' => true,
            'requirements' => ['currently_due' => [], 'past_due' => [], 'errors' => []],
            'disabled_reason' => null,
            'bank_account_last4' => '0785',
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => [
            'transfers_status' => 'inactive',
            'payouts_enabled' => false,
            'requirements' => ['currently_due' => [], 'past_due' => [], 'errors' => []],
            'disabled_reason' => 'rejected.other',
        ]);
    }
}
