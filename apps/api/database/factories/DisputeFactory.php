<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DisputeResolution;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Dispute>
 */
class DisputeFactory extends Factory
{
    protected $model = Dispute::class;

    /**
     * An open dispute, which is the only kind anything can do anything with.
     *
     * @return array<model-property<Dispute>, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'reason' => 'It never arrived, and tracking has not moved in two weeks.',
            'resolution' => null,
            'resolution_note' => null,
            'resolved_at' => null,
            'resolved_by' => null,
        ];
    }

    /**
     * Decided, one way or the other.
     *
     * All four columns move together because the table refuses anything else -
     * `disputes_resolution_is_whole` is an equivalence in both directions.
     */
    public function resolved(DisputeResolution $resolution, ?User $by = null): static
    {
        return $this->state(fn (): array => [
            'resolution' => $resolution,
            'resolution_note' => 'Tracking shows it was never scanned by the carrier.',
            'resolved_at' => now(),
            // `??` already suppresses the null access, so the nullsafe operator
            // in front of it would be the redundant half - the same note
            // `RefundPayment` makes about `cancelled_by`.
            'resolved_by' => $by->id ?? User::factory()->staff(),
        ]);
    }
}
