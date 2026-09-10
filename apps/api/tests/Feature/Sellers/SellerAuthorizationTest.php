<?php

declare(strict_types=1);

namespace Tests\Feature\Sellers;

use App\Enums\UserRole;
use App\Models\Seller;
use App\Models\User;
use App\Policies\SellerPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The authorization stance itself, asserted rather than described.
 *
 * Two of these test the policy directly rather than through HTTP. A policy is
 * the thing the rule lives in, and a rule that is only ever exercised through
 * one route is a rule nobody has checked applies to the next one.
 */
final class SellerAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private SellerPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new SellerPolicy;
    }

    // --- Roles are not selling capability ------------------------------------

    /**
     * Being staff says nothing about whether somebody sells, and having a shop
     * says nothing about whether they review. They are different columns
     * answering different questions (ADR 0007).
     */
    public function test_staff_may_also_run_a_shop(): void
    {
        $staff = User::factory()->staff()->create();
        $shop = Seller::factory()->for($staff)->create();

        $this->assertTrue($this->policy->update($staff, $shop));
        $this->assertTrue($this->policy->viewAny($staff));

        // And still cannot wave their own through.
        $this->assertFalse($this->policy->review($staff, $shop));
    }

    public function test_having_a_shop_does_not_make_somebody_staff(): void
    {
        $seller = User::factory()->create();
        Seller::factory()->for($seller)->approved()->create();

        $this->assertFalse($this->policy->viewAny($seller));
        $this->assertFalse($this->policy->review($seller, Seller::factory()->create()));
    }

    public function test_there_is_no_seller_role(): void
    {
        // Selling is a row, not a role. If a `seller` case is ever added to
        // UserRole, this fails and the reasoning in ADR 0007 gets re-read
        // before it happens.
        $this->assertSame(
            ['customer', 'staff', 'admin'],
            UserRole::values(),
        );
    }

    // --- The policy, directly -------------------------------------------------

    public function test_a_shop_is_editable_only_by_its_owner(): void
    {
        $owner = User::factory()->create();
        $shop = Seller::factory()->for($owner)->create();

        $this->assertTrue($this->policy->update($owner, $shop));
        $this->assertFalse($this->policy->update(User::factory()->create(), $shop));

        // Staff decide whether a shop may trade; they do not rewrite it.
        $this->assertFalse($this->policy->update(User::factory()->staff()->create(), $shop));
        $this->assertFalse($this->policy->update(User::factory()->admin()->create(), $shop));
    }

    public function test_only_staff_review_and_never_their_own(): void
    {
        $shop = Seller::factory()->create();

        $this->assertTrue($this->policy->review(User::factory()->staff()->create(), $shop));
        $this->assertTrue($this->policy->review(User::factory()->admin()->create(), $shop));
        $this->assertFalse($this->policy->review(User::factory()->create(), $shop));

        $owner = $shop->user;
        $owner->forceFill(['role' => 'admin'])->save();

        $this->assertFalse(
            $this->policy->review($owner->refresh(), $shop),
            'Even an admin must not review their own application.',
        );
    }

    // --- Seller-only endpoints require a shop --------------------------------

    /**
     * The `seller` middleware answers 403, not 404: the endpoint exists and
     * the caller is authenticated, and what they lack is the standing to use
     * it. Nothing is disclosed - the only account described is their own.
     */
    public function test_an_account_without_a_shop_cannot_reach_a_seller_endpoint(): void
    {
        $this->actingAs(User::factory()->create())
            ->fromFrontend()
            ->patchJson('/api/v1/seller', ['description' => 'I do not have a shop.'])
            ->assertForbidden();
    }

    public function test_staff_without_a_shop_are_refused_the_same_way(): void
    {
        // Staff are not exempt. Reviewing is not selling.
        $this->actingAs(User::factory()->staff()->create())
            ->fromFrontend()
            ->patchJson('/api/v1/seller', ['description' => 'Not mine to change.'])
            ->assertForbidden();
    }

    /**
     * A pending shop is still the owner's to edit while they wait. The `seller`
     * middleware deliberately does not check approval - that is a different
     * question, and widening it here would lock applicants out of fixing the
     * application they are being reviewed on.
     */
    public function test_a_pending_shop_is_still_editable_by_its_owner(): void
    {
        $owner = User::factory()->create();
        Seller::factory()->for($owner)->create();

        $this->actingAs($owner)
            ->fromFrontend()
            ->patchJson('/api/v1/seller', ['description' => 'Corrected while waiting.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');
    }

    // --- Authorization is not the route prefix -------------------------------

    /**
     * `admin` in the path is a URL. The refusal comes from SellerPolicy, which
     * is what keeps working when a controller moves.
     */
    public function test_the_admin_prefix_grants_nothing_by_itself(): void
    {
        $customer = User::factory()->create();
        $shop = Seller::factory()->create();

        $this->actingAs($customer)->fromFrontend()->getJson('/api/v1/admin/sellers')->assertForbidden();
        $this->actingAs($customer)->fromFrontend()
            ->postJson("/api/v1/admin/sellers/{$shop->id}/approval")->assertForbidden();
        $this->actingAs($customer)->fromFrontend()
            ->postJson("/api/v1/admin/sellers/{$shop->id}/rejection", ['reason' => 'Long enough to pass.'])
            ->assertForbidden();
    }
}
