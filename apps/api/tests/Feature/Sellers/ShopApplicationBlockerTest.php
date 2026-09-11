<?php

declare(strict_types=1);

namespace Tests\Feature\Sellers;

use App\Models\Seller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The signed-in user's answer to "can I apply to sell, and if not, why".
 *
 * Only worth sending if it agrees with what applying then does, so the last
 * test asks both questions of the same account: whatever the answer says is in
 * the way is what POST /seller/application refuses with (ADR 0033), as
 * CheckoutBlockerTest does for checkout.
 */
final class ShopApplicationBlockerTest extends TestCase
{
    use RefreshDatabase;

    public function test_somebody_with_a_confirmed_address_and_no_shop_may_apply(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.shop_application_blocker', null);
    }

    public function test_an_unconfirmed_address_comes_first(): void
    {
        $this->actingAs(User::factory()->create(['email_verified_at' => null]))
            ->getJson('/api/v1/auth/me')
            ->assertJsonPath('data.shop_application_blocker', 'unverified_email');
    }

    public function test_an_application_already_waiting_is_in_the_way(): void
    {
        $this->actingAs(Seller::factory()->create()->user)
            ->getJson('/api/v1/auth/me')
            ->assertJsonPath('data.shop_application_blocker', 'awaiting_review');
    }

    public function test_an_open_shop_is_in_the_way(): void
    {
        $this->actingAs(Seller::factory()->approved()->create()->user)
            ->getJson('/api/v1/auth/me')
            ->assertJsonPath('data.shop_application_blocker', 'already_open');
    }

    /**
     * Rejection is not final. The applicant fixes what was wrong and sends it
     * again, which is why a rejected shop returns to pending (ADR 0007).
     */
    public function test_a_rejected_applicant_may_apply_again(): void
    {
        $this->actingAs(Seller::factory()->rejected()->create()->user)
            ->getJson('/api/v1/auth/me')
            ->assertJsonPath('data.shop_application_blocker', null);
    }

    public function test_the_answer_agrees_with_what_applying_does(): void
    {
        $accounts = [
            'no shop' => [User::factory()->create(), 201],
            'unconfirmed address' => [User::factory()->create(['email_verified_at' => null]), 403],
            'awaiting review' => [Seller::factory()->create()->user, 409],
            'already open' => [Seller::factory()->approved()->create()->user, 409],
            'rejected' => [Seller::factory()->rejected()->create()->user, 201],
        ];

        foreach ($accounts as $label => [$user, $status]) {
            $blocker = $this->actingAs($user)
                ->getJson('/api/v1/auth/me')
                ->json('data.shop_application_blocker');

            $this->actingAs($user)
                ->fromFrontend()
                ->postJson('/api/v1/seller/application', [
                    'shop_name' => "Shop for {$label}",
                    'contact_email' => 'shop@example.test',
                    'currency' => 'EUR',
                ])
                ->assertStatus($status);

            $this->assertSame($status === 201, $blocker === null, $label);
        }
    }
}
