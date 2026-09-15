<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Appeal;
use App\Models\Product;
use App\Models\Review;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Appeal>
 */
class AppealFactory extends Factory
{
    protected $model = Appeal::class;

    /**
     * An open appeal about a listing, which is the kind anything can act on.
     *
     * @return array<model-property<Appeal>, mixed>
     */
    public function definition(): array
    {
        return [
            'appealable_type' => Product::class,
            'appealable_id' => Product::factory(),
            'user_id' => User::factory(),
            'reason' => 'The serial number in the photographs is the one on the box, and I still have the receipt.',
            'reviewed_at' => null,
            'upheld' => null,
            'outcome_note' => null,
            'reviewed_by' => null,
        ];
    }

    public function about(Product|Review|Seller $subject): static
    {
        return $this->state(fn (): array => [
            'appealable_type' => $subject::class,
            'appealable_id' => $subject->getKey(),
        ]);
    }

    /**
     * Decided, one way or the other.
     *
     * All four columns move together because `appeals_review_is_whole` refuses
     * anything else.
     */
    public function decided(bool $upheld, ?User $by = null): static
    {
        return $this->state(fn (): array => [
            'reviewed_at' => now(),
            'upheld' => $upheld,
            'outcome_note' => $upheld
                ? 'On a second look this should not have been stopped.'
                : 'The original decision stands.',
            'reviewed_by' => $by->id ?? User::factory()->staff(),
        ]);
    }
}
