<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Enums\ProductStatus;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Changing and removing lines, and who may.
 *
 * There is no `CartPolicy`, and these tests are why one is not needed: every
 * lookup starts from the authenticated user's cart, so another account's line
 * is never in the query to begin with (ADR 0008).
 */
final class CartItemTest extends TestCase
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

    public function test_a_shopper_can_change_a_lines_quantity(): void
    {
        $variant = $this->publishedVariant(priceMinor: 650, stock: 10);
        $line = $this->addAndReturnLineId($variant, 2);

        $this->actingAs($this->shopper)
            ->fromFrontend()
            ->patchJson("/api/v1/cart/items/{$line}", ['quantity' => 4])
            ->assertOk()
            ->assertJsonPath('data.shops.0.items.0.quantity', 4)
            ->assertJsonPath('data.shops.0.subtotal_minor', 2600);
    }

    public function test_a_quantity_above_the_stock_is_refused_with_what_is_left(): void
    {
        $variant = $this->publishedVariant(stock: 3);
        $line = $this->addAndReturnLineId($variant, 1);

        $this->actingAs($this->shopper)
            ->fromFrontend()
            ->patchJson("/api/v1/cart/items/{$line}", ['quantity' => 9])
            ->assertStatus(409)
            ->assertJsonPath('available', 3);

        $this->assertDatabaseHas('cart_items', ['id' => $line, 'quantity' => 1]);
    }

    /**
     * Zero is not a way to delete a line. `DELETE` removes lines, and two ways
     * to do one thing is how they end up behaving differently.
     */
    public function test_zero_is_a_validation_error_rather_than_a_quiet_delete(): void
    {
        $line = $this->addAndReturnLineId($this->publishedVariant(), 2);

        $this->actingAs($this->shopper)
            ->fromFrontend()
            ->patchJson("/api/v1/cart/items/{$line}", ['quantity' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity');

        $this->assertDatabaseHas('cart_items', ['id' => $line, 'quantity' => 2]);
    }

    /**
     * 409 and not 404: the line is theirs and the number they sent is valid,
     * and what is in the way is the state of the catalogue.
     */
    public function test_changing_a_line_that_is_no_longer_for_sale_is_refused(): void
    {
        $variant = $this->publishedVariant(stock: 10);
        $line = $this->addAndReturnLineId($variant, 1);

        $variant->product->forceFill([
            'status' => ProductStatus::Draft,
            'published_at' => null,
        ])->save();

        $this->actingAs($this->shopper)
            ->fromFrontend()
            ->patchJson("/api/v1/cart/items/{$line}", ['quantity' => 2])
            ->assertStatus(409)
            ->assertJsonPath('available', null)
            ->assertJsonPath('message', 'This is no longer for sale.');
    }

    public function test_a_shopper_can_remove_a_line(): void
    {
        $shop = Seller::factory()->approved()->create();

        $kept = $this->addAndReturnLineId($this->publishedVariant(), 1);
        $removed = $this->addAndReturnLineId($this->publishedVariant(shop: $shop), 1);

        $this->actingAs($this->shopper)
            ->fromFrontend()
            ->deleteJson("/api/v1/cart/items/{$removed}")
            ->assertOk()
            ->assertJsonPath('data.item_count', 1)
            ->assertJsonCount(1, 'data.shops');

        $this->assertDatabaseMissing('cart_items', ['id' => $removed]);
        $this->assertDatabaseHas('cart_items', ['id' => $kept]);
    }

    /**
     * 200 with the emptied cart rather than 204. Every cart mutation answers
     * with the whole cart, because every one of them changes its totals.
     */
    public function test_a_shopper_can_empty_the_cart_and_keep_it(): void
    {
        $this->addAndReturnLineId($this->publishedVariant(), 2);

        $this->actingAs($this->shopper)
            ->fromFrontend()
            ->deleteJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.item_count', 0)
            ->assertJsonCount(0, 'data.shops');

        $this->assertDatabaseCount('cart_items', 0);
        $this->assertDatabaseCount('carts', 1);
    }

    public function test_emptying_a_cart_that_was_never_started_is_not_an_error(): void
    {
        $this->actingAs($this->shopper)
            ->fromFrontend()
            ->deleteJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.item_count', 0);

        $this->assertDatabaseCount('carts', 0);
    }

    // --- Somebody else's cart -------------------------------------------------

    /**
     * 404 rather than 403. A 403 would confirm that the id names a real line,
     * and the caller has no business knowing that either way.
     */
    public function test_another_accounts_line_cannot_be_changed(): void
    {
        $line = $this->strangersLine();

        $this->actingAs($this->shopper)
            ->fromFrontend()
            ->patchJson("/api/v1/cart/items/{$line}", ['quantity' => 5])
            ->assertNotFound();

        $this->assertDatabaseHas('cart_items', ['id' => $line, 'quantity' => 1]);
    }

    public function test_another_accounts_line_cannot_be_removed(): void
    {
        $line = $this->strangersLine();

        $this->actingAs($this->shopper)
            ->fromFrontend()
            ->deleteJson("/api/v1/cart/items/{$line}")
            ->assertNotFound();

        $this->assertDatabaseHas('cart_items', ['id' => $line]);
    }

    public function test_a_line_id_from_an_account_with_no_cart_at_all_is_a_404(): void
    {
        $line = $this->strangersLine();

        $this->assertDatabaseCount('carts', 1);

        $this->actingAs($this->shopper)
            ->fromFrontend()
            ->deleteJson("/api/v1/cart/items/{$line}")
            ->assertNotFound();
    }

    private function strangersLine(): int
    {
        $stranger = User::factory()->create();
        $variant = $this->publishedVariant(stock: 10);

        $response = $this->actingAs($stranger)
            ->fromFrontend()
            ->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => 1])
            ->assertOk();

        return (int) $response->json('data.shops.0.items.0.id');
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

    private function addAndReturnLineId(ProductVariant $variant, int $quantity): int
    {
        $this->actingAs($this->shopper)
            ->fromFrontend()
            ->postJson('/api/v1/cart/items', [
                'variant_id' => $variant->id,
                'quantity' => $quantity,
            ])
            ->assertOk();

        return CartItem::query()
            ->where('product_variant_id', $variant->id)
            ->whereRelation('cart', 'user_id', $this->shopper->id)
            ->firstOrFail()
            ->id;
    }
}
