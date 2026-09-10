<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    /**
     * @return array<model-property<OrderItem>, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'product_name' => 'A Product',
            'variant_name' => 'Default',
            'unit_price_minor' => 2499,
            'quantity' => 1,
        ];
    }

    /** A line with no catalogue left behind it, as a removed variant leaves. */
    public function orphaned(): static
    {
        return $this->state(fn (): array => ['product_variant_id' => null]);
    }
}
