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
}
