<?php

declare(strict_types=1);

namespace Tests\Feature\Products;

use App\Models\Product;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProductManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private Seller $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seller = User::factory()->create();
        $this->shop = Seller::factory()->for($this->seller)->approved()->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Rye Sourdough',
            'description' => 'Baked overnight.',
            'variants' => [
                ['name' => 'Small', 'price_minor' => 650, 'stock' => 12],
                ['name' => 'Large', 'price_minor' => 950, 'stock' => 4],
            ],
        ], $overrides);
    }

    // --- Only sellers create products ----------------------------------------

    public function test_a_seller_can_create_a_product(): void
    {
        $response = $this->actingAs($this->seller)
            ->fromFrontend()
            ->postJson('/api/v1/seller/products', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Rye Sourdough')
            ->assertJsonPath('data.slug', 'rye-sourdough')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonCount(2, 'data.variants')
            ->assertJsonPath('data.variants.0.name', 'Small')
            ->assertJsonPath('data.variants.0.price_minor', 650);
    }

    /**
     * The currency is the shop's, read through the relation. There is no
     * currency column on a product (ADR 0007).
     */
    public function test_a_product_reports_its_shops_currency(): void
    {
        $this->shop->forceFill(['currency' => 'SEK'])->save();

        $this->actingAs($this->seller)
            ->fromFrontend()
            ->postJson('/api/v1/seller/products', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.currency', 'SEK');
    }

    public function test_an_account_without_a_shop_cannot_create_a_product(): void
    {
        $this->actingAs(User::factory()->create())
            ->fromFrontend()
            ->postJson('/api/v1/seller/products', $this->payload())
            ->assertForbidden();

        $this->assertDatabaseCount('products', 0);
    }

    public function test_an_anonymous_caller_cannot_create_a_product(): void
    {
        $this->postJson('/api/v1/seller/products', $this->payload())->assertUnauthorized();
    }

    /**
     * A shop still waiting on review can prepare its catalogue. Only
     * publishing needs approval.
     */
    public function test_a_pending_shop_can_still_create_drafts(): void
    {
        $applicant = User::factory()->create();
        Seller::factory()->for($applicant)->create();

        $this->actingAs($applicant)
            ->fromFrontend()
            ->postJson('/api/v1/seller/products', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft');
    }

    // --- Every product has at least one variant ------------------------------

    public function test_a_product_cannot_be_created_without_a_variant(): void
    {
        $this->actingAs($this->seller)
            ->fromFrontend()
            ->postJson('/api/v1/seller/products', $this->payload(['variants' => []]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('variants');
    }

    public function test_two_variants_cannot_share_a_name(): void
    {
        $this->actingAs($this->seller)
            ->fromFrontend()
            ->postJson('/api/v1/seller/products', $this->payload([
                'variants' => [
                    ['name' => 'Small', 'price_minor' => 650],
                    ['name' => 'small', 'price_minor' => 950],
                ],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('variants');
    }

    /**
     * A price is an integer number of minor units (ADR 0004). 24.99 is a
     * caller sending major units, and accepting it would list the product at
     * 24 minor units.
     */
    public function test_a_decimal_price_is_refused(): void
    {
        $this->actingAs($this->seller)
            ->fromFrontend()
            ->postJson('/api/v1/seller/products', $this->payload([
                'variants' => [['name' => 'Small', 'price_minor' => 24.99]],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('variants.0.price_minor');
    }

    public function test_a_negative_price_is_refused(): void
    {
        $this->actingAs($this->seller)
            ->fromFrontend()
            ->postJson('/api/v1/seller/products', $this->payload([
                'variants' => [['name' => 'Small', 'price_minor' => -1]],
            ]))
            ->assertUnprocessable();
    }

    // --- Sellers edit and delete only their own ------------------------------

    public function test_a_seller_can_edit_their_own_product(): void
    {
        $product = Product::factory()->for($this->shop, 'seller')->withVariant()->create();

        $this->actingAs($this->seller)
            ->fromFrontend()
            ->patchJson("/api/v1/seller/products/{$product->id}", ['name' => 'Rye and Caraway'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Rye and Caraway');
    }

    public function test_a_seller_cannot_edit_somebody_elses_product(): void
    {
        $other = Product::factory()->withVariant()->create();

        $this->actingAs($this->seller)
            ->fromFrontend()
            ->patchJson("/api/v1/seller/products/{$other->id}", ['name' => 'Not mine.'])
            ->assertForbidden();

        $this->assertNotSame('Not mine.', $other->refresh()->name);
    }

    public function test_a_seller_cannot_delete_somebody_elses_product(): void
    {
        $other = Product::factory()->withVariant()->create();

        $this->actingAs($this->seller)
            ->fromFrontend()
            ->deleteJson("/api/v1/seller/products/{$other->id}")
            ->assertForbidden();

        $this->assertNotSoftDeleted($other);
    }

    /**
     * A soft delete. Order history will eventually point at this row, and
     * "delete" from a seller's point of view means "take it out of my shop".
     */
    public function test_deleting_a_product_keeps_the_row(): void
    {
        $product = Product::factory()->for($this->shop, 'seller')->withVariant()->create();

        $this->actingAs($this->seller)
            ->fromFrontend()
            ->deleteJson("/api/v1/seller/products/{$product->id}")
            ->assertNoContent();

        $this->assertSoftDeleted($product);
    }

    public function test_the_catalogue_lists_only_the_sellers_own_products(): void
    {
        Product::factory()->for($this->shop, 'seller')->withVariant()->count(2)->create();
        Product::factory()->withVariant()->create();

        $this->actingAs($this->seller)
            ->getJson('/api/v1/seller/products')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    /**
     * The slug is the product's address within the shop. Editing the name does
     * not move it, because somebody may have saved the link.
     */
    public function test_renaming_a_product_does_not_move_its_address(): void
    {
        $product = Product::factory()->for($this->shop, 'seller')->withVariant()
            ->create(['slug' => 'rye-sourdough']);

        $this->actingAs($this->seller)
            ->fromFrontend()
            ->patchJson("/api/v1/seller/products/{$product->id}", ['name' => 'Something Else Entirely'])
            ->assertOk()
            ->assertJsonPath('data.slug', 'rye-sourdough');
    }

    /**
     * Two shops may both sell a rye sourdough. Neither should have to rename
     * theirs, so the slug is unique per shop rather than globally.
     */
    public function test_two_shops_can_use_the_same_product_slug(): void
    {
        Product::factory()->withVariant()->create(['slug' => 'rye-sourdough']);

        $this->actingAs($this->seller)
            ->fromFrontend()
            ->postJson('/api/v1/seller/products', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.slug', 'rye-sourdough');
    }

    public function test_a_clashing_slug_within_one_shop_is_suffixed(): void
    {
        Product::factory()->for($this->shop, 'seller')->withVariant()
            ->create(['slug' => 'rye-sourdough']);

        $this->actingAs($this->seller)
            ->fromFrontend()
            ->postJson('/api/v1/seller/products', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.slug', 'rye-sourdough-2');
    }
}
