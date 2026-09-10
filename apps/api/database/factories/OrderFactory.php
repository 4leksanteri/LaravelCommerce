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
            'user_id' => User::factory(),
            'seller_id' => Seller::factory()->approved(),
            'status' => OrderStatus::Pending,
            'currency' => Currency::EUR,
            'total_minor' => 0,
        ];
    }
}
