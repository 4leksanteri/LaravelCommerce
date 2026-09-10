<?php

declare(strict_types=1);

namespace Tests\Feature\Sellers;

use App\Enums\SellerStatus;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ShopApplicationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function application(array $overrides = []): array
    {
        return array_merge([
            'shop_name' => 'Koskela Bake House',
            'description' => 'Sourdough, rye and the occasional cardamom bun.',
            'contact_email' => 'hello@bakehouse.example',
            'currency' => 'EUR',
        ], $overrides);
    }

    public function test_a_verified_person_can_apply(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->fromFrontend()
            ->postJson('/api/v1/seller/application', $this->application());

        $response->assertCreated()
            ->assertJsonPath('data.shop_name', 'Koskela Bake House')
            ->assertJsonPath('data.status', SellerStatus::Pending->value)
            ->assertJsonPath('data.currency', 'EUR')
            ->assertJsonPath('data.is_public', false)
            ->assertJsonPath('data.can_edit', true);

        $this->assertDatabaseHas('sellers', [
            'user_id' => $user->id,
            'status' => SellerStatus::Pending->value,
        ]);
    }

    /**
     * The whole review conversation - approved, rejected, here is why -
     * happens by email. Applying with an address nobody has shown they can
     * read is applying into a void.
     */
    public function test_an_unverified_person_cannot_apply(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->fromFrontend()
            ->postJson('/api/v1/seller/application', $this->application())
            ->assertForbidden();

        $this->assertDatabaseCount('sellers', 0);
    }

    public function test_an_anonymous_caller_cannot_apply(): void
    {
        $this->postJson('/api/v1/seller/application', $this->application())
            ->assertUnauthorized();

        $this->assertDatabaseCount('sellers', 0);
    }

    public function test_the_slug_is_derived_from_the_shop_name(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->fromFrontend()
            ->postJson('/api/v1/seller/application', $this->application())
            ->assertCreated()
            ->assertJsonPath('data.slug', 'koskela-bake-house');
    }

    /**
     * Two shops may reasonably want the same name. The address has to differ,
     * and it counts up rather than using a random token because a person reads
     * this in a URL and says it out loud.
     */
    public function test_a_clashing_slug_is_given_a_suffix(): void
    {
        Seller::factory()->create(['slug' => 'koskela-bake-house']);

        $this->actingAs(User::factory()->create())
            ->fromFrontend()
            ->postJson('/api/v1/seller/application', $this->application())
            ->assertCreated()
            ->assertJsonPath('data.slug', 'koskela-bake-house-2');
    }

    /**
     * A name in a script Str::slug cannot transliterate leaves nothing behind.
     * The shop still needs an address.
     */
    public function test_a_name_that_slugs_to_nothing_still_gets_an_address(): void
    {
        $this->actingAs(User::factory()->create())
            ->fromFrontend()
            ->postJson('/api/v1/seller/application', $this->application(['shop_name' => '///']))
            ->assertCreated()
            ->assertJsonPath('data.slug', 'shop');
    }

    public function test_it_refuses_an_unsupported_currency(): void
    {
        $this->actingAs(User::factory()->create())
            ->fromFrontend()
            ->postJson('/api/v1/seller/application', $this->application(['currency' => 'XYZ']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('currency');
    }

    public function test_a_currency_code_is_not_case_sensitive(): void
    {
        $this->actingAs(User::factory()->create())
            ->fromFrontend()
            ->postJson('/api/v1/seller/application', $this->application(['currency' => 'eur']))
            ->assertCreated()
            ->assertJsonPath('data.currency', 'EUR');
    }

    public function test_it_refuses_a_second_application_while_one_is_pending(): void
    {
        $user = User::factory()->create();
        Seller::factory()->for($user)->create();

        $this->actingAs($user)
            ->fromFrontend()
            ->postJson('/api/v1/seller/application', $this->application())
            ->assertStatus(409);

        $this->assertDatabaseCount('sellers', 1);
    }

    public function test_it_refuses_a_second_application_when_a_shop_is_approved(): void
    {
        $user = User::factory()->create();
        Seller::factory()->for($user)->approved()->create();

        $this->actingAs($user)
            ->fromFrontend()
            ->postJson('/api/v1/seller/application', $this->application())
            ->assertStatus(409);
    }

    /**
     * A rejected applicant fixes what was wrong and tries again. The same row
     * is reused, so they keep their address and their history rather than
     * accumulating a row per attempt.
     */
    public function test_a_rejected_applicant_may_apply_again(): void
    {
        $user = User::factory()->create();
        $seller = Seller::factory()->for($user)->rejected()->create(['slug' => 'koskela-bake-house']);

        $this->actingAs($user)
            ->fromFrontend()
            ->postJson('/api/v1/seller/application', $this->application(['shop_name' => 'Koskela Bakery']))
            ->assertCreated()
            ->assertJsonPath('data.status', SellerStatus::Pending->value)
            ->assertJsonPath('data.shop_name', 'Koskela Bakery')
            // The address does not move. Somebody may already have it saved.
            ->assertJsonPath('data.slug', 'koskela-bake-house')
            ->assertJsonPath('data.rejection_reason', null);

        $this->assertDatabaseCount('sellers', 1);

        $seller->refresh();
        $this->assertNull($seller->reviewed_at);
        $this->assertNull($seller->reviewed_by);
    }

    /**
     * The currency is fixed at first application (ADR 0007). A resubmission is
     * the same shop trying again, not a chance to re-denominate it.
     */
    public function test_resubmitting_cannot_change_the_currency(): void
    {
        $user = User::factory()->create();
        Seller::factory()->for($user)->rejected()->create(['currency' => 'EUR']);

        $this->actingAs($user)
            ->fromFrontend()
            ->postJson('/api/v1/seller/application', $this->application(['currency' => 'USD']))
            ->assertCreated()
            ->assertJsonPath('data.currency', 'EUR');
    }

    public function test_it_validates_the_shop_name(): void
    {
        $this->actingAs(User::factory()->create())
            ->fromFrontend()
            ->postJson('/api/v1/seller/application', $this->application(['shop_name' => 'x']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('shop_name');
    }
}
