<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Currency;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * An order with no lines and a total of zero, which is not a state checkout
     * can produce - `PlaceOrders` writes both together.
     *
     * Most tests should place an order through the endpoint instead, so that
     * what they assert against is what the application actually writes. This is
     * for reads.
     *
     * @return array<model-property<Order>, mixed>
     */
    public function definition(): array
    {
        return [
            'reference' => Str::upper(Str::random(10)),

            // Its own checkout. A factory order was not placed alongside
            // anything, and saying otherwise would make grouping tests pass for
            // the wrong reason.
            'checkout_reference' => Str::upper(Str::random(10)),

            'user_id' => User::factory(),
            'seller_id' => Seller::factory()->approved(),
            'status' => OrderStatus::Pending,
            'currency' => Currency::EUR,
            'total_minor' => 0,
        ];
    }

    /**
     * The states below each set the timestamps their status requires, because
     * the `orders_timeline_check` constraint rejects any that disagree - a
     * shipped order without a shipping date is not a row the database accepts.
     */
    public function accepted(): static
    {
        return $this->state(fn (): array => [
            'status' => OrderStatus::Accepted,
            'accepted_at' => now(),
        ]);
    }

    /**
     * `auto_complete_at` comes with `shipped_at` and is not optional: the
     * `orders_auto_complete_at_check` constraint requires one exactly when the
     * other is set.
     */
    public function shipped(): static
    {
        return $this->state(fn (): array => [
            'status' => OrderStatus::Shipped,
            'accepted_at' => now(),
            'shipped_at' => now(),
            'auto_complete_at' => now()->addDays((int) config('orders.auto_complete_after_days')),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => OrderStatus::Completed,
            'accepted_at' => now(),
            'shipped_at' => now(),
            'auto_complete_at' => now()->addDays((int) config('orders.auto_complete_after_days')),
            'completed_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => OrderStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }
}
