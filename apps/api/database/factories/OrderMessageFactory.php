<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrderParty;
use App\Models\Order;
use App\Models\OrderMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderMessage>
 */
class OrderMessageFactory extends Factory
{
    protected $model = OrderMessage::class;

    /**
     * Something the buyer said, and nobody has read yet.
     *
     * The buyer is the default because they are the side that usually opens a
     * conversation: a question about a parcel is asked before it is answered.
     *
     * @return array<model-property<OrderMessage>, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'sender' => OrderParty::Buyer,
            'body' => fake()->sentence(),
            'read_at' => null,
        ];
    }

    public function fromShop(): static
    {
        return $this->state(fn (): array => ['sender' => OrderParty::Seller]);
    }

    /** Read by the side it was sent to, so it counts towards nobody's unread. */
    public function read(): static
    {
        return $this->state(fn (): array => ['read_at' => now()]);
    }
}
