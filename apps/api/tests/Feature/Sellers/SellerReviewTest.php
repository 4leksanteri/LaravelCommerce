<?php

declare(strict_types=1);

namespace Tests\Feature\Sellers;

use App\Enums\SellerStatus;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SellerReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_approve_an_application(): void
    {
        $staff = User::factory()->staff()->create();
        $seller = Seller::factory()->create();

        $this->actingAs($staff)
            ->fromFrontend()
            ->postJson("/api/v1/admin/sellers/{$seller->id}/approval")
            ->assertOk()
            ->assertJsonPath('data.status', SellerStatus::Approved->value)
            ->assertJsonPath('data.is_public', true);

        $seller->refresh();

        $this->assertSame(SellerStatus::Approved, $seller->status);
        $this->assertSame($staff->id, $seller->reviewed_by);
        $this->assertNotNull($seller->reviewed_at);
    }

    public function test_staff_can_reject_an_application_with_a_reason(): void
    {
        $staff = User::factory()->staff()->create();
        $seller = Seller::factory()->create();

        $this->actingAs($staff)
            ->fromFrontend()
            ->postJson("/api/v1/admin/sellers/{$seller->id}/rejection", [
                'reason' => 'The contact address bounces, so we cannot reach the shop.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', SellerStatus::Rejected->value)
            ->assertJsonPath('data.is_public', false)
            ->assertJsonPath('data.rejection_reason', 'The contact address bounces, so we cannot reach the shop.');
    }

    /**
     * A rejection the applicant cannot act on leaves them to guess. The table
     * has a check constraint saying the same thing.
     */
    public function test_a_rejection_requires_a_reason(): void
    {
        $staff = User::factory()->staff()->create();
        $seller = Seller::factory()->create();

        $this->actingAs($staff)
            ->fromFrontend()
            ->postJson("/api/v1/admin/sellers/{$seller->id}/rejection", ['reason' => 'no'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->assertSame(SellerStatus::Pending, $seller->refresh()->status);
    }

    public function test_an_ordinary_customer_cannot_review(): void
    {
        $seller = Seller::factory()->create();

        $this->actingAs(User::factory()->create())
            ->fromFrontend()
            ->postJson("/api/v1/admin/sellers/{$seller->id}/approval")
            ->assertForbidden();

        $this->assertSame(SellerStatus::Pending, $seller->refresh()->status);
    }

    /**
     * Being staff does not let somebody wave their own shop through.
     */
    public function test_staff_cannot_review_their_own_application(): void
    {
        $staff = User::factory()->staff()->create();
        $seller = Seller::factory()->for($staff)->create();

        $this->actingAs($staff)
            ->fromFrontend()
            ->postJson("/api/v1/admin/sellers/{$seller->id}/approval")
            ->assertForbidden();

        $this->assertSame(SellerStatus::Pending, $seller->refresh()->status);
    }

    /**
     * Two reviewers opening the queue at once. The one who arrives second is
     * told, rather than silently becoming the author of somebody else's
     * decision.
     *
     * 409, not 403 - they were allowed - and not 422 - what they sent was
     * fine. The world moved underneath them.
     */
    public function test_an_application_cannot_be_reviewed_twice(): void
    {
        $first = User::factory()->staff()->create();
        $second = User::factory()->staff()->create();
        $seller = Seller::factory()->create();

        $this->actingAs($first)
            ->fromFrontend()
            ->postJson("/api/v1/admin/sellers/{$seller->id}/approval")
            ->assertOk();

        $this->actingAs($second)
            ->fromFrontend()
            ->postJson("/api/v1/admin/sellers/{$seller->id}/rejection", [
                'reason' => 'Arrived a moment too late to matter.',
            ])
            ->assertStatus(409);

        $seller->refresh();

        $this->assertSame(SellerStatus::Approved, $seller->status);
        $this->assertSame($first->id, $seller->reviewed_by);
    }

    public function test_staff_can_list_the_queue_oldest_first(): void
    {
        $staff = User::factory()->staff()->create();

        $older = Seller::factory()->create(['applied_at' => now()->subDays(3)]);
        $newer = Seller::factory()->create(['applied_at' => now()->subDay()]);

        $this->actingAs($staff)
            ->fromFrontend()
            ->getJson('/api/v1/admin/sellers?status=pending')
            ->assertOk()
            ->assertJsonPath('data.0.id', $older->id)
            ->assertJsonPath('data.1.id', $newer->id);
    }

    public function test_the_queue_can_be_filtered_by_status(): void
    {
        $staff = User::factory()->staff()->create();

        Seller::factory()->create();
        $approved = Seller::factory()->approved()->create();

        $this->actingAs($staff)
            ->fromFrontend()
            ->getJson('/api/v1/admin/sellers?status=approved')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $approved->id);
    }

    /** A mistyped link says so, rather than quietly answering with every shop. */
    public function test_a_status_that_does_not_exist_is_refused(): void
    {
        $staff = User::factory()->staff()->create();

        Seller::factory()->create();

        $this->actingAs($staff)
            ->fromFrontend()
            ->getJson('/api/v1/admin/sellers?status=lost')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_an_ordinary_customer_cannot_read_the_queue(): void
    {
        $this->actingAs(User::factory()->create())
            ->fromFrontend()
            ->getJson('/api/v1/admin/sellers')
            ->assertForbidden();
    }

    public function test_an_anonymous_caller_cannot_read_the_queue(): void
    {
        $this->getJson('/api/v1/admin/sellers')->assertUnauthorized();
    }
}
