<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Enums\ProductStatus;
use App\Enums\SellerStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * What happens to a cart when the catalogue underneath it moves.
 *
 * This is the half of ADR 0010 that the endpoints cannot enforce. Everything
 * here was perfectly addable at the time, and stopped being buyable afterwards:
 * the seller unpublished it, deleted it, sold the last one or changed the
 * price. The cart has to keep working and say what happened.
 */
final class CartAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private User $shopper;

    private Seller $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shopper = User::factory()->create();
        $this->shop = Seller::factory()->approved()->create();
    }

    /**
     * The line stays, priced from its snapshot, and contributes nothing to the
     * subtotal. Those two numbers differ on purpose: `line_total_minor` is the
     * line's own arithmetic, `subtotal_minor` is what checkout would charge.
     */
    public function test_a_line_whose_listing_was_unpublished_is_no_longer_for_sale(): void
    {
        $variant = $this->publishedVariant(priceMinor: 650, stock: 10);
        $this->add($variant, 2);

        $variant->product->forceFill([
            'status' => ProductStatus::Draft,
            'published_at' => null,
        ])->save();

        $this->cart()
            ->assertJsonPath('data.has_unavailable_items', true)
            ->assertJsonPath('data.shops.0.has_unavailable_items', true)
            ->assertJsonPath('data.shops.0.items.0.availability', 'no_longer_for_sale')
            ->assertJsonPath('data.shops.0.items.0.line_total_minor', 1300)
            ->assertJsonPath('data.shops.0.subtotal_minor', 0);
    }

    public function test_a_line_whose_listing_was_deleted_is_no_longer_for_sale(): void
    {
        $variant = $this->publishedVariant();
        $this->add($variant);

        $variant->product->delete();

        $this->cart()->assertJsonPath('data.shops.0.items.0.availability', 'no_longer_for_sale');
    }

    /**
     * The storefront's second condition, reaching the cart. A shop that stops
     * being approved stops selling, however its listings are set.
     */
    public function test_a_line_whose_shop_stopped_being_approved_is_no_longer_for_sale(): void
    {
        $this->add($this->publishedVariant());

        $this->shop->forceFill([
            'status' => SellerStatus::Rejected,
            'rejection_reason' => 'Trading under a name they do not own.',
            'reviewed_at' => now(),
        ])->save();

        $this->cart()->assertJsonPath('data.shops.0.items.0.availability', 'no_longer_for_sale');
    }

    /**
     * The reason the foreign key is `nullOnDelete` and not `cascade`, and the
     * reason the snapshot columns exist.
     *
     * Cascading would have deleted this line out of the cart silently: the item
     * is simply gone next time the shopper looks, with nothing to explain it.
     */
    public function test_a_line_survives_the_seller_removing_its_variant(): void
    {
        $product = Product::factory()->for($this->shop, 'seller')->published()
            ->create(['name' => 'Rye Sourdough']);

        $small = ProductVariant::factory()->for($product)
            ->create(['name' => 'Small', 'price_minor' => 650, 'stock' => 5, 'position' => 0]);

        ProductVariant::factory()->for($product)
            ->create(['name' => 'Large', 'price_minor' => 950, 'stock' => 5, 'position' => 1]);

        $this->add($small, 2);

        $small->delete();

        $this->assertDatabaseCount('cart_items', 1);

        $this->cart()
            ->assertJsonPath('data.shops.0.items.0.variant_id', null)
            ->assertJsonPath('data.shops.0.items.0.product_slug', null)
            ->assertJsonPath('data.shops.0.items.0.product_name', 'Rye Sourdough')
            ->assertJsonPath('data.shops.0.items.0.variant_name', 'Small')
            ->assertJsonPath('data.shops.0.items.0.unit_price_minor', 650)
            ->assertJsonPath('data.shops.0.items.0.availability', 'no_longer_for_sale');
    }

    // --- Stock ----------------------------------------------------------------

    public function test_a_line_that_sold_out_is_reported_as_out_of_stock(): void
    {
        $variant = $this->publishedVariant(stock: 5);
        $this->add($variant, 2);

        $variant->forceFill(['stock' => 0])->save();

        $this->cart()
            ->assertJsonPath('data.shops.0.items.0.availability', 'out_of_stock')
            ->assertJsonPath('data.shops.0.items.0.available_quantity', null)
            ->assertJsonPath('data.shops.0.subtotal_minor', 0);
    }

    /**
     * The one case where a number is published, and it is published because the
     * shopper has to be told it to fix their cart. ADR 0009 keeps exact stock
     * off the storefront; this does not put it back.
     */
    public function test_a_line_short_of_stock_says_how_many_can_be_had(): void
    {
        $variant = $this->publishedVariant(stock: 5);
        $this->add($variant, 4);

        $variant->forceFill(['stock' => 2])->save();

        $this->cart()
            ->assertJsonPath('data.shops.0.items.0.availability', 'insufficient_stock')
            ->assertJsonPath('data.shops.0.items.0.available_quantity', 2);
    }

    public function test_an_available_line_does_not_publish_a_stock_count(): void
    {
        $this->add($this->publishedVariant(stock: 47), 1);

        $this->cart()
            ->assertJsonPath('data.shops.0.items.0.availability', 'available')
            ->assertJsonPath('data.shops.0.items.0.available_quantity', null);
    }

    // --- Price ----------------------------------------------------------------

    /**
     * The catalogue is the source of truth until checkout writes an order. The
     * cart charges today's price and says the old one so the change can be
     * pointed out - a shopper discovering it at the payment screen is the
     * failure this avoids.
     */
    public function test_a_price_change_is_charged_and_reported(): void
    {
        $variant = $this->publishedVariant(priceMinor: 650, stock: 10);
        $this->add($variant, 2);

        $variant->forceFill(['price_minor' => 720])->save();

        $this->cart()
            ->assertJsonPath('data.shops.0.items.0.unit_price_minor', 720)
            ->assertJsonPath('data.shops.0.items.0.added_price_minor', 650)
            ->assertJsonPath('data.shops.0.items.0.price_changed', true)
            ->assertJsonPath('data.shops.0.items.0.line_total_minor', 1440)
            ->assertJsonPath('data.shops.0.subtotal_minor', 1440);
    }

    /**
     * The snapshot means "what it cost when this first went in the cart".
     * Re-stamping it on a second add would erase the change it exists to
     * detect.
     */
    public function test_adding_more_does_not_restamp_the_snapshot(): void
    {
        $variant = $this->publishedVariant(priceMinor: 650, stock: 10);
        $this->add($variant, 1);

        $variant->forceFill(['price_minor' => 720])->save();
        $this->add($variant, 1);

        $this->cart()
            ->assertJsonPath('data.shops.0.items.0.quantity', 2)
            ->assertJsonPath('data.shops.0.items.0.added_price_minor', 650)
            ->assertJsonPath('data.shops.0.items.0.price_changed', true);
    }

    public function test_an_unchanged_price_is_not_reported_as_changed(): void
    {
        $this->add($this->publishedVariant(priceMinor: 650), 1);

        $this->cart()->assertJsonPath('data.shops.0.items.0.price_changed', false);
    }

    private function publishedVariant(int $priceMinor = 2499, int $stock = 10): ProductVariant
    {
        $product = Product::factory()->for($this->shop, 'seller')->published()->create();

        return ProductVariant::factory()->for($product)->create([
            'name' => 'Default',
            'price_minor' => $priceMinor,
            'stock' => $stock,
            'position' => 0,
        ]);
    }

    private function add(ProductVariant $variant, int $quantity = 1): void
    {
        $this->actingAs($this->shopper)
            ->fromFrontend()
            ->postJson('/api/v1/cart/items', [
                'variant_id' => $variant->id,
                'quantity' => $quantity,
            ])
            ->assertOk();
    }

    /**
     * @return TestResponse<Response>
     */
    private function cart(): TestResponse
    {
        return $this->actingAs($this->shopper)->getJson('/api/v1/cart')->assertOk();
    }
}
