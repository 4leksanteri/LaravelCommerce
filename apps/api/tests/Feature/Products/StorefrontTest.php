<?php

declare(strict_types=1);

namespace Tests\Feature\Products;

use App\Models\Product;
use App\Models\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a shopper can see, and everything they cannot.
 */
final class StorefrontTest extends TestCase
{
    use RefreshDatabase;

    private Seller $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Seller::factory()->approved()->create(['slug' => 'koskela-bake-house']);
    }

    public function test_a_published_product_of_an_approved_shop_is_public(): void
    {
        $product = Product::factory()->for($this->shop, 'seller')->withVariant()->published()
            ->create(['slug' => 'rye-sourdough']);

        $this->assertGuest();

        $this->getJson('/api/v1/shops/koskela-bake-house/products/rye-sourdough')
            ->assertOk()
            ->assertJsonPath('data.slug', 'rye-sourdough')
            ->assertJsonPath('data.name', $product->name)
            ->assertJsonCount(1, 'data.variants');
    }

    public function test_a_draft_is_not_public(): void
    {
        Product::factory()->for($this->shop, 'seller')->withVariant()
            ->create(['slug' => 'rye-sourdough']);

        $this->getJson('/api/v1/shops/koskela-bake-house/products/rye-sourdough')->assertNotFound();
    }

    public function test_a_deleted_product_is_not_public(): void
    {
        $product = Product::factory()->for($this->shop, 'seller')->withVariant()->published()
            ->create(['slug' => 'rye-sourdough']);

        $product->delete();

        $this->getJson('/api/v1/shops/koskela-bake-house/products/rye-sourdough')->assertNotFound();
    }

    /**
     * The rule that makes approval mean something. A product published in a
     * shop nobody has reviewed must not reach a shopper, whatever its own
     * status says.
     */
    public function test_a_published_product_in_an_unapproved_shop_is_not_public(): void
    {
        $pending = Seller::factory()->create(['slug' => 'not-reviewed-yet']);

        Product::factory()->for($pending, 'seller')->withVariant()->published()
            ->create(['slug' => 'rye-sourdough']);

        $this->getJson('/api/v1/shops/not-reviewed-yet/products/rye-sourdough')->assertNotFound();
        $this->getJson('/api/v1/shops/not-reviewed-yet/products')->assertNotFound();
    }

    public function test_the_listing_shows_only_published_products(): void
    {
        Product::factory()->for($this->shop, 'seller')->withVariant()->published()->count(2)->create();
        Product::factory()->for($this->shop, 'seller')->withVariant()->create();

        $this->getJson('/api/v1/shops/koskela-bake-house/products')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_the_listing_shows_only_that_shops_products(): void
    {
        Product::factory()->for($this->shop, 'seller')->withVariant()->published()->create();
        Product::factory()->withVariant()->published()->create();

        $this->getJson('/api/v1/shops/koskela-bake-house/products')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /**
     * The public view is a much shorter allowlist. No status, no publication
     * date, no `can_*` - and no exact stock count, which is a competitor's
     * inventory report rather than something a shopper needs.
     */
    public function test_the_public_view_publishes_nothing_internal(): void
    {
        Product::factory()->for($this->shop, 'seller')->withVariant()->published()
            ->create(['slug' => 'rye-sourdough']);

        $response = $this->getJson('/api/v1/shops/koskela-bake-house/products/rye-sourdough')->assertOk();

        $this->assertSame(
            ['slug', 'name', 'description', 'currency', 'variants'],
            array_keys($response->json('data')),
        );

        $this->assertSame(
            ['id', 'name', 'price_minor', 'in_stock'],
            array_keys($response->json('data.variants.0')),
            'A shopper is told whether they can buy it, not how many are left.',
        );
    }

    public function test_stock_is_reported_as_availability_only(): void
    {
        Product::factory()->for($this->shop, 'seller')->published()
            ->withVariant(priceMinor: 650, stock: 0)
            ->create(['slug' => 'sold-out']);

        $this->getJson('/api/v1/shops/koskela-bake-house/products/sold-out')
            ->assertOk()
            ->assertJsonPath('data.variants.0.in_stock', false);
    }

    public function test_an_unknown_shop_is_a_404(): void
    {
        $this->getJson('/api/v1/shops/no-such-shop/products')->assertNotFound();
    }
}
