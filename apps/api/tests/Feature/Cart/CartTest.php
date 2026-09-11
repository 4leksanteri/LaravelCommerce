<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Enums\Currency;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reading a cart: what is in it, and how it is grouped.
 */
final class CartTest extends TestCase
{
    use RefreshDatabase;

    private User $shopper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shopper = User::factory()->create();
    }

    public function test_a_cart_belongs_to_a_signed_in_shopper(): void
    {
        $this->getJson('/api/v1/cart')->assertUnauthorized();
    }

    /**
     * A shopper who has never added anything still has a cart. It is empty,
     * which is a perfectly good cart, and reading it does not create a row -
     * otherwise every visit would insert one.
     */
    public function test_an_account_that_has_added_nothing_has_an_empty_cart(): void
    {
        $this->actingAs($this->shopper)
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.item_count', 0)
            ->assertJsonPath('data.has_unavailable_items', false)
            ->assertJsonCount(0, 'data.shops');

        $this->assertDatabaseCount('carts', 0);
    }

    /**
     * The money rule made structural.
     *
     * Sellers price in their own currency, so a basket spanning two shops has
     * two subtotals and no total (ADR 0004). The assertion on the key set is
     * the point of the test: there is nowhere in this response to put a figure
     * that spans currencies.
     */
    public function test_a_cart_spanning_two_shops_is_grouped_with_a_subtotal_each(): void
    {
        $bakery = Seller::factory()->approved()->create([
            'shop_name' => 'Aalto Bakery',
            'slug' => 'aalto-bakery',
            'currency' => Currency::EUR,
        ]);

        $roastery = Seller::factory()->approved()->create([
            'shop_name' => 'Bergman Coffee',
            'slug' => 'bergman-coffee',
            'currency' => Currency::SEK,
        ]);

        $this->addToCart($this->publishedVariant($bakery, priceMinor: 650), 2);
        $this->addToCart($this->publishedVariant($roastery, priceMinor: 12900), 1);

        $response = $this->actingAs($this->shopper)->getJson('/api/v1/cart')->assertOk();

        $response
            ->assertJsonCount(2, 'data.shops')
            ->assertJsonPath('data.shops.0.shop_slug', 'aalto-bakery')
            ->assertJsonPath('data.shops.0.shop_name', 'Aalto Bakery')
            ->assertJsonPath('data.shops.0.currency', 'EUR')
            ->assertJsonPath('data.shops.0.subtotal_minor', 1300)
            ->assertJsonPath('data.shops.1.shop_slug', 'bergman-coffee')
            ->assertJsonPath('data.shops.1.currency', 'SEK')
            ->assertJsonPath('data.shops.1.subtotal_minor', 12900);

        $this->assertSame(
            ['item_count', 'has_unavailable_items', 'checkout_blocker', 'shops'],
            array_keys((array) $response->json('data')),
            'A grand total across currencies must have nowhere to live.',
        );
    }

    /**
     * Units rather than lines, because that is what the number beside a cart
     * icon means to the person reading it.
     */
    public function test_the_item_count_is_units_and_not_lines(): void
    {
        $shop = Seller::factory()->approved()->create();

        $this->addToCart($this->publishedVariant($shop, name: 'Small'), 2);
        $this->addToCart($this->publishedVariant($shop, name: 'Large'), 3);

        $this->actingAs($this->shopper)
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.item_count', 5)
            ->assertJsonCount(2, 'data.shops.0.items');
    }

    /**
     * An allowlist, asserted rather than described. A line publishes two prices
     * and the difference between them is load-bearing: `unit_price_minor` is
     * what checkout will charge, `added_price_minor` is only there so a change
     * can be pointed out.
     */
    public function test_a_line_publishes_a_fixed_shape(): void
    {
        $shop = Seller::factory()->approved()->create();
        $this->addToCart($this->publishedVariant($shop));

        $response = $this->actingAs($this->shopper)->getJson('/api/v1/cart')->assertOk();

        $this->assertSame(
            [
                'id',
                'quantity',
                'product_name',
                'variant_name',
                'product_slug',
                'variant_id',
                'unit_price_minor',
                'added_price_minor',
                'price_changed',
                'line_total_minor',
                'availability',
                'available_quantity',
            ],
            array_keys((array) $response->json('data.shops.0.items.0')),
        );
    }

    public function test_a_cart_holds_nothing_from_anybody_elses(): void
    {
        $shop = Seller::factory()->approved()->create();
        $variant = $this->publishedVariant($shop);

        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->fromFrontend()
            ->postJson('/api/v1/cart/items', ['variant_id' => $variant->id])
            ->assertOk();

        $this->actingAs($this->shopper)
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.item_count', 0);
    }

    private function publishedVariant(
        Seller $shop,
        string $name = 'Default',
        int $priceMinor = 2499,
        int $stock = 10,
    ): ProductVariant {
        $product = Product::factory()->for($shop, 'seller')->published()->create();

        return ProductVariant::factory()->for($product)->create([
            'name' => $name,
            'price_minor' => $priceMinor,
            'stock' => $stock,
            'position' => 0,
        ]);
    }

    private function addToCart(ProductVariant $variant, int $quantity = 1): void
    {
        $this->actingAs($this->shopper)
            ->fromFrontend()
            ->postJson('/api/v1/cart/items', [
                'variant_id' => $variant->id,
                'quantity' => $quantity,
            ])
            ->assertOk();
    }
}
