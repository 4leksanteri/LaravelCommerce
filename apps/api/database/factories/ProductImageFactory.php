<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductImage>
 */
class ProductImageFactory extends Factory
{
    protected $model = ProductImage::class;

    /**
     * A row without a file behind it, which is not a state the application can
     * produce - `StoreProductImage` writes both together.
     *
     * Useful for testing what a resource publishes. Anything asserting on the
     * bytes should go through the upload endpoint instead.
     *
     * @return array<model-property<ProductImage>, mixed>
     */
    public function definition(): array
    {
        $uuid = (string) Str::uuid();

        return [
            'uuid' => $uuid,
            'product_id' => Product::factory(),
            'disk' => 'products',
            'path' => "1/{$uuid}.webp",
            'width' => 1600,
            'height' => 1200,
            'byte_size' => 84_213,
            'alt_text' => null,
            'position' => 0,
        ];
    }
}
