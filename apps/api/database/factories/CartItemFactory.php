<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CartItem>
 */
class CartItemFactory extends Factory
{
    protected $model = CartItem::class;

    /**
     * A line built from nothing in particular, which is not how the application
     * makes one - `AddToCart` derives every column below from a variant that is
     * actually on sale.
     *
     * Use `forVariant()` unless the test is specifically about a line whose
     * snapshot has drifted from the catalogue, which is most of what this
     * factory is useful for.
     *
     * @return array<model-property<CartItem>, mixed>
     */
    public function definition(): array
    {
        return [
            'cart_id' => Cart::factory(),
            'product_variant_id' => ProductVariant::factory(),

            // Resolved after `product_variant_id` has been, so this reads the
            // real variant rather than a pending factory.
            'seller_id' => fn (array $attributes): int => $this->sellerOf($attributes),
            'quantity' => 1,
            'added_price_minor' => 2499,
            'product_name' => 'A Product',
            'variant_name' => 'Default',
        ];
    }

    /**
     * A line for a variant that exists, with the snapshot taken from it - what
     * `AddToCart` would have written.
     */
    public function forVariant(ProductVariant $variant, int $quantity = 1): static
    {
        return $this->state(fn (): array => [
            'product_variant_id' => $variant->id,
            'seller_id' => $variant->product->seller_id,
            'quantity' => $quantity,
            'added_price_minor' => $variant->price_minor,
            'product_name' => $variant->product->name,
            'variant_name' => $variant->name,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function sellerOf(array $attributes): int
    {
        $variant = ProductVariant::query()
            ->with('product')
            ->whereKey($attributes['product_variant_id'])
            ->firstOrFail();

        return $variant->product->seller_id;
    }
}
