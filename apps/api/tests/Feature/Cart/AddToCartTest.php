<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

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
 * What may go in a cart.
 *
 * The two refusals answer with two different statuses, and the difference is
 * deliberate (ADR 0010):
 *
 *   not for sale       404 - resolved through the storefront's own scope
 *   not enough stock   409 - it is for sale, there are just not that many
 */
final class AddToCartTest extends TestCase
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

    public function test_adding_requires_a_signed_in_shopper(): void
    {
        $variant = $this->publishedVariant();

        $this->fromFrontend()
            ->postJson('/api/v1/cart/items', ['variant_id' => $variant->id])
            ->assertUnauthorized();

        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_a_shopper_can_add_a_published_variant(): void
    {
        $variant = $this->publishedVariant(priceMinor: 650, stock: 4);

        $this->add($variant, 2)
            ->assertOk()
            ->assertJsonPath('data.item_count', 2)
            ->assertJsonPath('data.shops.0.items.0.variant_id', $variant->id)
            ->assertJsonPath('data.shops.0.items.0.quantity', 2)
            ->assertJsonPath('data.shops.0.items.0.unit_price_minor', 650)
            ->assertJsonPath('data.shops.0.items.0.availability', 'available')
            ->assertJsonPath('data.shops.0.subtotal_minor', 1300);
    }

    public function test_the_quantity_defaults_to_one(): void
    {
        $variant = $this->publishedVariant();

        $this->actingAs($this->shopper)
            ->fromFrontend()
            ->postJson('/api/v1/cart/items', ['variant_id' => $variant->id])
            ->assertOk()
            ->assertJsonPath('data.item_count', 1);
    }

    /**
     * What "add" means when it is already in there. The unique index on
     * (cart_id, product_variant_id) is what makes a second line impossible.
     */
    public function test_adding_the_same_variant_again_raises_its_quantity(): void
    {
        $variant = $this->publishedVariant(stock: 10);

        $this->add($variant, 2)->assertOk();
        $this->add($variant, 3)->assertOk()->assertJsonPath('data.shops.0.items.0.quantity', 5);

        $this->assertDatabaseCount('cart_items', 1);
    }

    // --- Not for sale is a 404 ------------------------------------------------

    public function test_a_draft_cannot_be_added(): void
    {
        $product = Product::factory()->for($this->shop, 'seller')->withVariant()->create();

        $this->add($product->variants()->firstOrFail())->assertNotFound();

        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_a_deleted_listing_cannot_be_added(): void
    {
        $variant = $this->publishedVariant();
        $variant->product->delete();

        $this->add($variant)->assertNotFound();
    }

    /**
     * The rule that makes approval mean something, reaching the cart. A product
     * published in a shop nobody reviewed is not for sale, whatever its own
     * status says.
     */
    public function test_a_variant_of_an_unapproved_shop_cannot_be_added(): void
    {
        $pending = Seller::factory()->create(['status' => SellerStatus::Pending]);
        $variant = $this->publishedVariant(shop: $pending);

        $this->add($variant)->assertNotFound();
    }

    /**
     * The same answer as an unpublished one, which is the point. A different
     * status for "no such id" would say which ids are real - which is also why
     * there is no `exists` rule on variant_id.
     */
    public function test_an_unknown_variant_is_a_404_and_not_a_validation_error(): void
    {
        $this->actingAs($this->shopper)
            ->fromFrontend()
            ->postJson('/api/v1/cart/items', ['variant_id' => 999_999])
            ->assertNotFound();
    }

    // --- Not enough stock is a 409 --------------------------------------------

    public function test_more_than_the_stock_is_refused_with_what_is_left(): void
    {
        $variant = $this->publishedVariant(stock: 3);

        $this->add($variant, 5)
            ->assertStatus(409)
            ->assertJsonPath('available', 3);

        $this->assertDatabaseCount('cart_items', 0);
    }

    /**
     * The check is on the total, not on this request. Two additions of two,
     * against a stock of three, is four.
     */
    public function test_the_stock_check_counts_what_is_already_in_the_cart(): void
    {
        $variant = $this->publishedVariant(stock: 3);

        $this->add($variant, 2)->assertOk();

        $this->add($variant, 2)
            ->assertStatus(409)
            ->assertJsonPath('available', 3);

        $this->actingAs($this->shopper)
            ->getJson('/api/v1/cart')
            ->assertJsonPath('data.item_count', 2);
    }

    public function test_a_sold_out_variant_is_refused(): void
    {
        $variant = $this->publishedVariant(stock: 0);

        $this->add($variant)
            ->assertStatus(409)
            ->assertJsonPath('available', 0)
            ->assertJsonPath('message', 'This is sold out.');
    }

    // --- Validation -----------------------------------------------------------

    public function test_the_quantity_must_be_at_least_one(): void
    {
        $variant = $this->publishedVariant();

        $this->add($variant, 0)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity');
    }

    public function test_the_quantity_is_bounded(): void
    {
        $variant = $this->publishedVariant();

        $this->add($variant, 1_000)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity');
    }

    public function test_a_variant_is_required(): void
    {
        $this->actingAs($this->shopper)
            ->fromFrontend()
            ->postJson('/api/v1/cart/items', ['quantity' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('variant_id');
    }

    private function publishedVariant(
        ?Seller $shop = null,
        int $priceMinor = 2499,
        int $stock = 10,
    ): ProductVariant {
        $product = Product::factory()->for($shop ?? $this->shop, 'seller')->published()->create();

        return ProductVariant::factory()->for($product)->create([
            'name' => 'Default',
            'price_minor' => $priceMinor,
            'stock' => $stock,
            'position' => 0,
        ]);
    }

    /**
     * @return TestResponse<Response>
     */
    private function add(ProductVariant $variant, int $quantity = 1): TestResponse
    {
        return $this->actingAs($this->shopper)
            ->fromFrontend()
            ->postJson('/api/v1/cart/items', [
                'variant_id' => $variant->id,
                'quantity' => $quantity,
            ]);
    }
}
