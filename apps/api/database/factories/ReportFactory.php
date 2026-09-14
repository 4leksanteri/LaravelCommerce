<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ReportReason;
use App\Models\Product;
use App\Models\Report;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    protected $model = Report::class;

    /**
     * An open report about a listing, which is the kind anything can act on.
     *
     * @return array<model-property<Report>, mixed>
     */
    public function definition(): array
    {
        return [
            'reportable_type' => Product::class,
            'reportable_id' => Product::factory(),
            'user_id' => User::factory(),
            'reason' => ReportReason::Counterfeit,
            'note' => 'The serial in the photographs belongs to a different model.',
            'reviewed_at' => null,
            'upheld' => null,
            'outcome_note' => null,
            'reviewed_by' => null,
        ];
    }

    public function about(Product|Review $subject): static
    {
        return $this->state(fn (): array => [
            'reportable_type' => $subject::class,
            'reportable_id' => $subject->getKey(),
        ]);
    }

    /**
     * Decided, one way or the other.
     *
     * All four columns move together because `reports_review_is_whole` refuses
     * anything else.
     */
    public function decided(bool $upheld, ?User $by = null): static
    {
        return $this->state(fn (): array => [
            'reviewed_at' => now(),
            'upheld' => $upheld,
            'outcome_note' => $upheld
                ? 'The listing is not what it claims to be.'
                : 'Nothing here breaks the rules.',
            'reviewed_by' => $by->id ?? User::factory()->staff(),
        ]);
    }
}
