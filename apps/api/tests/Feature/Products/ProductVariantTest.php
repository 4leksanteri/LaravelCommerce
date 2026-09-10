<?php

declare(strict_types=1);

namespace Tests\Feature\Products;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProductVariantTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seller = User::factory()->create();
        $shop = Seller::factory()->for($this->seller)->approved()->create();
        $this->product = Product::factory()->for($shop, 'seller')->withVariant()->create();
    }

    public function test_a_seller_can_add_a_variant(): void
    {
        $this->actingAs($this->seller)
            ->fromFrontend()
            ->postJson("/api/v1/seller/products/{$this->product->id}/variants", [
                'name' => 'Large',
                'price_minor' => 950,
                'stock' => 3,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Large')
            ->assertJsonPath('data.price_minor', 950)
            ->assertJsonPath('data.in_stock', true);
    }

    public function test_a_variant_name_is_unique_within_the_product(): void
    {
        $this->actingAs($this->seller)
            ->fromFrontend()
            ->postJson("/api/v1/seller/products/{$this->product->id}/variants", [
                'name' => 'Default',
                'price_minor' => 950,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_a_seller_can_change_a_price_and_stock(): void
    {
        $variant = $this->product->variants()->first();
        $this->assertInstanceOf(ProductVariant::class, $variant);

        $this->actingAs($this->seller)
            ->fromFrontend()
            ->patchJson("/api/v1/seller/products/{$this->product->id}/variants/{$variant->id}", [
                'price_minor' => 1200,
                'stock' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('data.price_minor', 1200)
            ->assertJsonPath('data.in_stock', false);
    }

    /**
     * Every product keeps at least one variant. Without that, a listing would
     * have no price and could not be bought - a state nothing downstream is
     * written to handle.
     */
    public function test_the_last_variant_cannot_be_removed(): void
    {
        $variant = $this->product->variants()->first();
        $this->assertInstanceOf(ProductVariant::class, $variant);

        $this->actingAs($this->seller)
            ->fromFrontend()
            ->deleteJson("/api/v1/seller/products/{$this->product->id}/variants/{$variant->id}")
            ->assertStatus(409);

        $this->assertDatabaseCount('product_variants', 1);
    }

    public function test_a_variant_can_be_removed_when_another_remains(): void
    {
        $this->product->variants()->create([
            'name' => 'Large',
            'price_minor' => 950,
            'stock' => 2,
            'position' => 1,
        ]);

        $first = $this->product->variants()->first();
        $this->assertInstanceOf(ProductVariant::class, $first);

        $this->actingAs($this->seller)
            ->fromFrontend()
            ->deleteJson("/api/v1/seller/products/{$this->product->id}/variants/{$first->id}")
            ->assertNoContent();

        $this->assertDatabaseCount('product_variants', 1);
    }

    public function test_a_seller_cannot_touch_another_shops_variant(): void
    {
        $other = Product::factory()->withVariant()->create();
        $otherVariant = $other->variants()->first();
        $this->assertInstanceOf(ProductVariant::class, $otherVariant);

        $this->actingAs($this->seller)
            ->fromFrontend()
            ->patchJson("/api/v1/seller/products/{$other->id}/variants/{$otherVariant->id}", [
                'price_minor' => 1,
            ])
            ->assertForbidden();

        $this->assertSame(2499, $otherVariant->refresh()->price_minor);
    }

    /**
     * scopeBindings() on the nested routes is what stops this: without it,
     * `{variant}` resolves globally and another shop's variant could be
     * reached by putting its id after a product the caller does own.
     */
    public function test_a_variant_of_another_product_cannot_be_reached_through_your_own(): void
    {
        $other = Product::factory()->withVariant()->create();
        $otherVariant = $other->variants()->first();
        $this->assertInstanceOf(ProductVariant::class, $otherVariant);

        $this->actingAs($this->seller)
            ->fromFrontend()
            ->patchJson("/api/v1/seller/products/{$this->product->id}/variants/{$otherVariant->id}", [
                'price_minor' => 1,
            ])
            ->assertNotFound();

        $this->assertSame(2499, $otherVariant->refresh()->price_minor);
    }
}
