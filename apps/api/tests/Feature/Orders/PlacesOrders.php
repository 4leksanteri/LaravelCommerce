<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Enums\Currency;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Seller;
use App\Models\User;

/**
 * Fixtures for the order tests.
 *
 * `placeOrder()` goes through the cart and checkout endpoints rather than
 * building rows, so what these tests assert against is what the application
 * actually writes - including the stock it took, which several of them then
 * check comes back.
 */
trait PlacesOrders
{
    private function approvedShop(User $owner, Currency $currency = Currency::EUR): Seller
    {
        return Seller::factory()->for($owner)->approved()->create(['currency' => $currency]);
    }

    private function publishedVariant(Seller $shop, int $priceMinor = 650, int $stock = 10): ProductVariant
    {
        $product = Product::factory()->for($shop, 'seller')->published()->create();

        return ProductVariant::factory()->for($product)->create([
            'name' => 'Default',
            'price_minor' => $priceMinor,
            'stock' => $stock,
            'position' => 0,
        ]);
    }

    private function placeOrder(User $buyer, ProductVariant $variant, int $quantity = 2): Order
    {
        $this->actingAs($buyer)
            ->fromFrontend()
            ->postJson('/api/v1/cart/items', [
                'variant_id' => $variant->id,
                'quantity' => $quantity,
            ])
            ->assertOk();

        $this->actingAs($buyer)
            ->fromFrontend()
            ->postJson('/api/v1/checkout')
            ->assertCreated();

        return Order::query()
            ->where('user_id', $buyer->id)
            ->where('seller_id', $variant->product->seller_id)
            ->latest('id')
            ->firstOrFail();
    }
}
