<?php

declare(strict_types=1);

namespace Tests\Feature\Sellers;

use App\Models\Seller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ShopTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Having no shop is a normal state for almost every account, and the
     * frontend asks this on every page load to decide what the navigation
     * says. Answering 404 would make the common case an error.
     */
    public function test_an_account_with_no_shop_gets_a_null_answer_not_a_404(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/seller')
            ->assertOk()
            ->assertExactJson(['data' => null]);
    }

    public function test_an_owner_sees_their_own_shop_at_any_status(): void
    {
        $user = User::factory()->create();
        $seller = Seller::factory()->for($user)->rejected('The photographs are stock images.')->create();

        $this->actingAs($user)
            ->getJson('/api/v1/seller')
            ->assertOk()
            ->assertJsonPath('data.id', $seller->id)
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'The photographs are stock images.')
            ->assertJsonPath('data.can_edit', true);
    }

    public function test_an_owner_can_edit_shop_details(): void
    {
        $user = User::factory()->create();
        Seller::factory()->for($user)->approved()->create();

        $this->actingAs($user)
            ->fromFrontend()
            ->patchJson('/api/v1/seller', ['description' => 'Now with oat buns.'])
            ->assertOk()
            ->assertJsonPath('data.description', 'Now with oat buns.');
    }

    /**
     * PATCH means partial. A field that is absent is left alone rather than
     * being cleared.
     */
    public function test_editing_one_field_leaves_the_others_alone(): void
    {
        $user = User::factory()->create();
        $seller = Seller::factory()->for($user)->approved()->create(['shop_name' => 'Koskela Bake House']);

        $this->actingAs($user)
            ->fromFrontend()
            ->patchJson('/api/v1/seller', ['description' => 'Changed.'])
            ->assertOk()
            ->assertJsonPath('data.shop_name', 'Koskela Bake House');

        $this->assertSame('Koskela Bake House', $seller->refresh()->shop_name);
    }

    /**
     * The address is the shop's identity to everybody who saved a link to it,
     * and the currency is fixed at application (ADR 0007). Neither is in the
     * request's rules, so a payload carrying them changes nothing.
     */
    public function test_the_slug_and_currency_cannot_be_edited(): void
    {
        $user = User::factory()->create();
        $seller = Seller::factory()->for($user)->approved()->create([
            'slug' => 'koskela-bake-house',
            'currency' => 'EUR',
        ]);

        $this->actingAs($user)
            ->fromFrontend()
            ->patchJson('/api/v1/seller', [
                'slug' => 'somewhere-else',
                'currency' => 'USD',
                'description' => 'Legitimate edit.',
            ])
            ->assertOk();

        $seller->refresh();

        $this->assertSame('koskela-bake-house', $seller->slug);
        $this->assertSame('EUR', $seller->currency->value);
        $this->assertSame('Legitimate edit.', $seller->description);
    }

    /**
     * A shop cannot approve itself by sending a status. The field is not in
     * the rules and not fillable on the model.
     */
    public function test_a_shop_cannot_approve_itself(): void
    {
        $user = User::factory()->create();
        $seller = Seller::factory()->for($user)->create();

        $this->actingAs($user)
            ->fromFrontend()
            ->patchJson('/api/v1/seller', ['status' => 'approved'])
            ->assertOk();

        $this->assertSame('pending', $seller->refresh()->status->value);
    }

    /**
     * Staff decide whether a shop may trade. They do not rewrite somebody
     * else's shop description, and there is no route that would let them:
     * PATCH /seller edits the caller's own shop, and a staff member with no
     * shop is refused by the `seller` middleware.
     */
    public function test_staff_cannot_edit_somebody_elses_shop(): void
    {
        $staff = User::factory()->staff()->create();
        Seller::factory()->approved()->create();

        $this->actingAs($staff)
            ->fromFrontend()
            ->patchJson('/api/v1/seller', ['description' => 'Not mine to change.'])
            ->assertForbidden();
    }

    public function test_an_anonymous_caller_gets_401(): void
    {
        $this->getJson('/api/v1/seller')->assertUnauthorized();
    }
}
