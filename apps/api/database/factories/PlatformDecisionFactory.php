<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DecisionKind;
use App\Models\PlatformDecision;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<PlatformDecision>
 */
class PlatformDecisionFactory extends Factory
{
    protected $model = PlatformDecision::class;

    /**
     * A suspension, which is the decision this table was most written for: it
     * is the one that leaves no trace anywhere else once it is lifted.
     *
     * `subject_id` reads the seller that `seller_id` has already resolved,
     * rather than calling the factory a second time - two `Seller::factory()`
     * calls are two shops, and the subject of a suspension is the shop it
     * happened to.
     *
     * @return array<model-property<PlatformDecision>, mixed>
     */
    public function definition(): array
    {
        return [
            'seller_id' => Seller::factory(),
            'kind' => DecisionKind::ShopSuspended,
            'subject_type' => Seller::class,
            'subject_id' => fn (array $attributes): mixed => $attributes['seller_id'],
            'reason' => 'Three disputes decided against it this month.',
            'decided_by' => User::factory()->staff(),
            'created_at' => now(),
        ];
    }

    /** What it was about, when that is not the shop itself. */
    public function about(Model $subject): static
    {
        return $this->state(fn (): array => [
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
        ]);
    }

    public function of(DecisionKind $kind): static
    {
        return $this->state(fn (): array => ['kind' => $kind]);
    }

    /** Whose record it belongs to. */
    public function for_(Seller $seller): static
    {
        return $this->state(fn (): array => ['seller_id' => $seller->getKey()]);
    }
}
