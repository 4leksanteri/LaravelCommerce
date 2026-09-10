<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Enums\Currency;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reading orders back.
 *
 * There is no `OrderPolicy`, and these are why one is not needed yet: every
 * query starts from `$user->orders()`, so somebody else's order is never in it
 * (ADR 0008). The seller's view of the same rows is a different audience and is
 * not built - that is the change that will bring a policy with it.
 */
final class OrderTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buyer = User::factory()->create();
    }

    public function test_orders_require_a_signed_in_shopper(): void
    {
        $this->getJson('/api/v1/orders')->assertUnauthorized();
    }

    public function test_a_shopper_sees_only_their_own_orders(): void
    {
        Order::factory()->for($this->buyer, 'user')->count(2)->create();
        Order::factory()->count(3)->create();

        $this->actingAs($this->buyer)
            ->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    /**
     * 404 and not 403. A 403 would confirm that the reference names a real
     * order, which is the thing the reference is meant to keep quiet about.
     */
    public function test_another_shoppers_order_is_a_404(): void
    {
        $stranger = Order::factory()->create(['reference' => 'ABCDEFGHJK']);

        $this->actingAs($this->buyer)
            ->getJson("/api/v1/orders/{$stranger->reference}")
            ->assertNotFound();
    }

    public function test_an_unknown_reference_is_a_404(): void
    {
        $this->actingAs($this->buyer)
            ->getJson('/api/v1/orders/NOSUCHREF9')
            ->assertNotFound();
    }

    public function test_an_order_publishes_a_fixed_shape(): void
    {
        $order = $this->placedOrder();

        $response = $this->actingAs($this->buyer)
            ->getJson("/api/v1/orders/{$order->reference}")
            ->assertOk();

        $this->assertSame(
            [
                'reference',
                'checkout_reference',
                'status',
                'shop_slug',
                'shop_name',
                'currency',
                'total_minor',
                'item_count',
                'items',
                'placed_at',
            ],
            array_keys((array) $response->json('data')),
        );

        $this->assertSame(
            [
                'id',
                'product_name',
                'variant_name',
                'quantity',
                'unit_price_minor',
                'line_total_minor',
                'product_slug',
                'variant_id',
            ],
            array_keys((array) $response->json('data.items.0')),
            'A receipt has no availability and no price_changed. It does not move.',
        );
    }

    /**
     * The link goes; the receipt does not. Removing a variant nulls the
     * reference and leaves every figure and name on the order exactly as it
     * was - the same reasoning as a cart line, with a stronger consequence.
     */
    public function test_an_order_survives_the_seller_removing_the_variant(): void
    {
        $order = $this->placedOrder();
        $variant = ProductVariant::query()->firstOrFail();

        $variant->delete();

        $this->actingAs($this->buyer)
            ->getJson("/api/v1/orders/{$order->reference}")
            ->assertOk()
            ->assertJsonPath('data.total_minor', 1300)
            ->assertJsonPath('data.items.0.product_name', 'Rye Sourdough')
            ->assertJsonPath('data.items.0.variant_name', 'Small')
            ->assertJsonPath('data.items.0.unit_price_minor', 650)
            ->assertJsonPath('data.items.0.product_slug', null)
            ->assertJsonPath('data.items.0.variant_id', null);

        $this->assertSame(1, OrderItem::query()->count());
    }

    public function test_the_listing_is_newest_first(): void
    {
        $older = Order::factory()->for($this->buyer, 'user')->create(['reference' => 'AAAAAAAAAA']);
        $newer = Order::factory()->for($this->buyer, 'user')->create(['reference' => 'BBBBBBBBBB']);

        $this->actingAs($this->buyer)
            ->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonPath('data.0.reference', $newer->reference)
            ->assertJsonPath('data.1.reference', $older->reference);
    }

    /** Places a real order through checkout, so what is read back is what is written. */
    private function placedOrder(): Order
    {
        $shop = Seller::factory()->approved()->create([
            'shop_name' => 'Aalto Bakery',
            'slug' => 'aalto-bakery',
            'currency' => Currency::EUR,
        ]);

        $product = Product::factory()->for($shop, 'seller')->published()
            ->create(['name' => 'Rye Sourdough', 'slug' => 'rye-sourdough']);

        $variant = ProductVariant::factory()->for($product)->create([
            'name' => 'Small',
            'price_minor' => 650,
            'stock' => 10,
            'position' => 0,
        ]);

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => 2])
            ->assertOk();

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson('/api/v1/checkout')
            ->assertCreated();

        return Order::query()->firstOrFail();
    }
}
