<?php

declare(strict_types=1);

namespace Tests\Feature\Sellers;

use App\Models\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What approval is for.
 */
final class PublicShopTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_approved_shop_is_public(): void
    {
        $seller = Seller::factory()->approved()->create(['slug' => 'koskela-bake-house']);

        $this->getJson('/api/v1/shops/koskela-bake-house')
            ->assertOk()
            ->assertJsonPath('data.slug', 'koskela-bake-house')
            ->assertJsonPath('data.shop_name', $seller->shop_name);
    }

    public function test_it_needs_no_authentication(): void
    {
        Seller::factory()->approved()->create(['slug' => 'koskela-bake-house']);

        $this->assertGuest();

        $this->getJson('/api/v1/shops/koskela-bake-house')->assertOk();
    }

    /**
     * 404, not 403. Answering "this shop is awaiting review" would tell
     * anybody who guessed a slug that somebody applied under it, and an
     * application is not public information.
     */
    public function test_a_pending_shop_is_not_public(): void
    {
        Seller::factory()->create(['slug' => 'koskela-bake-house']);

        $this->getJson('/api/v1/shops/koskela-bake-house')->assertNotFound();
    }

    public function test_a_rejected_shop_is_not_public(): void
    {
        Seller::factory()->rejected()->create(['slug' => 'koskela-bake-house']);

        $this->getJson('/api/v1/shops/koskela-bake-house')->assertNotFound();
    }

    /**
     * The public view is a much shorter allowlist than the owner's. Everything
     * about the review is between the applicant and the platform.
     */
    public function test_it_never_publishes_the_review(): void
    {
        Seller::factory()
            ->approved()
            ->create(['slug' => 'koskela-bake-house']);

        $response = $this->getJson('/api/v1/shops/koskela-bake-house')->assertOk();

        $this->assertSame(
            ['slug', 'shop_name', 'description', 'contact_email', 'currency'],
            array_keys($response->json('data')),
            'The public shop view must not carry review state, ids or timestamps.',
        );
    }
}
