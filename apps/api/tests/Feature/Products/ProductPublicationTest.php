<?php

declare(strict_types=1);

namespace Tests\Feature\Products;

use App\Models\Product;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProductPublicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_seller_can_publish_their_own_product(): void
    {
        $user = User::factory()->create();
        $shop = Seller::factory()->for($user)->approved()->create();
        $product = Product::factory()->for($shop, 'seller')->withVariant()->create();

        $this->actingAs($user)
            ->fromFrontend()
            ->postJson("/api/v1/seller/products/{$product->id}/publication")
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.is_public', true);

        $this->assertNotNull($product->refresh()->published_at);
    }

    public function test_unpublishing_keeps_the_product_and_clears_the_date(): void
    {
        $user = User::factory()->create();
        $shop = Seller::factory()->for($user)->approved()->create();
        $product = Product::factory()->for($shop, 'seller')->withVariant()->published()->create();

        $this->actingAs($user)
            ->fromFrontend()
            ->deleteJson("/api/v1/seller/products/{$product->id}/publication")
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.published_at', null);

        $this->assertNotSoftDeleted($product);
    }

    /**
     * The rule this whole endpoint exists to enforce. Without it, applying to
     * sell and publishing immediately would put a shop's products on the
     * marketplace with nobody having reviewed it - which is what approval is
     * for.
     *
     * 409 rather than 403: the seller is entitled to publish their own
     * products, and what is in the way is a fact about their shop (ADR 0008).
     */
    public function test_a_pending_shop_cannot_publish(): void
    {
        $user = User::factory()->create();
        $shop = Seller::factory()->for($user)->create();
        $product = Product::factory()->for($shop, 'seller')->withVariant()->create();

        $this->actingAs($user)
            ->fromFrontend()
            ->postJson("/api/v1/seller/products/{$product->id}/publication")
            ->assertStatus(409);

        $this->assertSame('draft', $product->refresh()->status->value);
    }

    public function test_a_rejected_shop_cannot_publish(): void
    {
        $user = User::factory()->create();
        $shop = Seller::factory()->for($user)->rejected()->create();
        $product = Product::factory()->for($shop, 'seller')->withVariant()->create();

        $this->actingAs($user)
            ->fromFrontend()
            ->postJson("/api/v1/seller/products/{$product->id}/publication")
            ->assertStatus(409);
    }

    public function test_a_seller_cannot_publish_somebody_elses_product(): void
    {
        $user = User::factory()->create();
        Seller::factory()->for($user)->approved()->create();
        $other = Product::factory()->withVariant()->create();

        $this->actingAs($user)
            ->fromFrontend()
            ->postJson("/api/v1/seller/products/{$other->id}/publication")
            ->assertForbidden();
    }

    /**
     * Publishing something already on sale is not an error, and must not move
     * published_at - the column means "on sale since".
     */
    public function test_publishing_twice_is_idempotent(): void
    {
        $user = User::factory()->create();
        $shop = Seller::factory()->for($user)->approved()->create();
        $product = Product::factory()->for($shop, 'seller')->withVariant()->create();

        $this->actingAs($user)->fromFrontend()
            ->postJson("/api/v1/seller/products/{$product->id}/publication")->assertOk();

        $first = $product->refresh()->published_at;

        $this->travel(5)->minutes();

        $this->actingAs($user)->fromFrontend()
            ->postJson("/api/v1/seller/products/{$product->id}/publication")->assertOk();

        $this->assertEquals($first, $product->refresh()->published_at);
    }
}
