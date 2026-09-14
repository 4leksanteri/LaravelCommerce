<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    protected $model = Review::class;

    /**
     * A review somebody left, with words.
     *
     * The three relations are made rather than assumed: a review with no
     * product, buyer or order is not a thing this table allows, and a factory
     * that left them out would only ever be used with all three overridden.
     *
     * @return array<model-property<Review>, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'user_id' => User::factory(),
            'order_id' => Order::factory(),
            'rating' => fake()->numberBetween(1, 5),
            'body' => fake()->sentence(),
        ];
    }

    /** A rating on its own, which is a review too. */
    public function withoutWords(): static
    {
        return $this->state(fn (): array => ['body' => null]);
    }

    public function rated(int $rating): static
    {
        return $this->state(fn (): array => ['rating' => $rating]);
    }

    /**
     * Taken out of sight by the platform (ADR 0054).
     *
     * All three columns move together, because `reviews_hiding_is_whole`
     * refuses anything else - and the row stays, which is the whole difference
     * between hiding and the deletion ADR 0047 refused.
     */
    public function hidden(?User $by = null): static
    {
        return $this->state(fn (): array => [
            'hidden_at' => now(),
            'hidden_reason' => 'Aimed at the seller rather than at what was bought.',
            'hidden_by' => $by->id ?? User::factory()->staff(),
        ]);
    }
}
