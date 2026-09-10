<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\Seller;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * A draft, because that is where every listing starts.
     *
     * Note that this creates a product with **no variants**, which is not a
     * state the application can produce - CreateProduct always makes at least
     * one. Use `withVariant()` or `->has(ProductVariant::factory())` unless the
     * test is specifically about a product without them.
     *
     * @return array<model-property<Product>, mixed>
     */
    public function definition(): array
    {
        $name = implode(' ', (array) fake()->unique()->words(3));

        return [
            'seller_id' => Seller::factory()->approved(),
            'name' => Str::title($name),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'description' => fake()->paragraph(),
            'status' => ProductStatus::Draft,
            'published_at' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => ProductStatus::Published,
            'published_at' => now(),
        ]);
    }

    /** One variant, which is the smallest valid product. */
    public function withVariant(int $priceMinor = 2499, int $stock = 5): static
    {
        return $this->has(
            ProductVariantFactory::new()->state([
                'name' => 'Default',
                'price_minor' => $priceMinor,
                'stock' => $stock,
                'position' => 0,
            ]),
            'variants',
        );
    }
}
