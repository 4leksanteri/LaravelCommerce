<?php

declare(strict_types=1);

namespace Tests\Feature\Appeals;

use App\Enums\ProductStatus;
use App\Enums\SellerStatus;
use App\Models\Appeal;
use App\Models\Product;
use App\Models\Review;
use App\Models\Seller;
use App\Models\User;
use App\Notifications\Appeals\AppealDecided;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Orders\PlacesOrders;
use Tests\TestCase;

/**
 * Answering back about a decision the platform took (ADR 0059).
 *
 * Two rules carry the chapter:
 *
 * **Only against something that is actually stopped.** A listing nobody removed
 * and a shop that is trading have no decision behind them to argue with.
 *
 * **Raising one changes nothing.** The sanction stands until a person decides,
 * because an appeal that lifted it would make appealing a free way back.
 */
final class AppealTest extends TestCase
{
    use PlacesOrders;
    use RefreshDatabase;

    private User $owner;

    private User $staff;

    private Seller $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->staff = User::factory()->staff()->create();
        $this->shop = $this->approvedShop($this->owner);
    }

    // --- A suspended shop -----------------------------------------------------

    public function test_a_suspended_shop_answers_back(): void
    {
        $this->suspend();

        $this->actingAs($this->owner)
            ->fromFrontend()
            ->postJson('/api/v1/seller/appeal', ['reason' => 'The trademark is registered to us, and I have the certificate.'])
            ->assertCreated()
            ->assertJsonPath('data.is_open', true)
            ->assertJsonPath('data.upheld', null)
            ->assertJsonPath('data.subject.kind', 'shop')

            // The argument is against the platform's own sentence, so the queue
            // carries it beside the appeal.
            ->assertJsonPath('data.subject.sanction_reason', 'Trading under a name they do not own.');
    }

    /** **It does not lift the suspension.** The shop is still stopped. */
    public function test_raising_one_does_not_lift_the_suspension(): void
    {
        $this->suspend();

        $this->actingAs($this->owner)
            ->fromFrontend()
            ->postJson('/api/v1/seller/appeal', ['reason' => 'We have the paperwork for this.'])
            ->assertCreated();

        $this->assertSame(SellerStatus::Suspended, $this->shop->refresh()->status);
        $this->getJson("/api/v1/shops/{$this->shop->slug}")->assertNotFound();
    }

    public function test_a_trading_shop_has_nothing_to_appeal(): void
    {
        $this->actingAs($this->owner)
            ->fromFrontend()
            ->postJson('/api/v1/seller/appeal', ['reason' => 'Nothing has happened to us at all.'])
            ->assertStatus(409);
    }

    public function test_a_second_appeal_is_refused_while_the_first_waits(): void
    {
        $this->suspend();

        foreach (['The first thing I have to say.', 'And the second thing.'] as $index => $reason) {
            $response = $this->actingAs($this->owner)
                ->fromFrontend()
                ->postJson('/api/v1/seller/appeal', ['reason' => $reason]);

            $index === 0 ? $response->assertCreated() : $response->assertStatus(409);
        }
    }

    /** A dismissed appeal does not bar a later one: there may be more to say. */
    public function test_a_dismissed_appeal_can_be_followed_by_another(): void
    {
        $this->suspend();
        $appeal = $this->appealTheSuspension();

        $this->decide($appeal, upheld: false)->assertOk();

        $this->actingAs($this->owner)
            ->fromFrontend()
            ->postJson('/api/v1/seller/appeal', ['reason' => 'I have found the certificate since.'])
            ->assertCreated();
    }

    public function test_a_reason_that_says_nothing_is_refused(): void
    {
        $this->suspend();

        $this->actingAs($this->owner)
            ->fromFrontend()
            ->postJson('/api/v1/seller/appeal', ['reason' => 'no'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    // --- A removed listing ----------------------------------------------------

    public function test_a_shop_answers_back_about_a_takedown(): void
    {
        $listing = $this->removedListing();

        $this->actingAs($this->owner)
            ->fromFrontend()
            ->postJson("/api/v1/seller/products/{$listing->id}/appeal", [
                'reason' => 'It is the camera in the photographs, and I have the receipt.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.subject.kind', 'listing')
            ->assertJsonPath('data.subject.sanction_reason', 'Not what it claims to be.');
    }

    public function test_another_shops_listing_cannot_be_appealed(): void
    {
        $listing = $this->removedListing();

        $this->actingAs(User::factory()->create())
            ->fromFrontend()
            ->postJson("/api/v1/seller/products/{$listing->id}/appeal", ['reason' => 'Not my listing at all.'])
            ->assertForbidden();
    }

    public function test_a_listing_nobody_took_down_has_nothing_to_appeal(): void
    {
        $listing = $this->publishedVariant($this->shop)->product;

        $this->actingAs($this->owner)
            ->fromFrontend()
            ->postJson("/api/v1/seller/products/{$listing->id}/appeal", ['reason' => 'This one is perfectly fine.'])
            ->assertStatus(409);
    }

    // --- Who may read the queue -----------------------------------------------

    public function test_the_queue_is_staff_only(): void
    {
        $this->actingAs($this->owner)->getJson('/api/v1/admin/appeals')->assertForbidden();
    }

    public function test_the_queue_lists_what_is_open_and_loses_what_is_decided(): void
    {
        $this->suspend();
        $appeal = $this->appealTheSuspension();

        $this->actingAs($this->staff)
            ->getJson('/api/v1/admin/appeals')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.subject.kind', 'shop');

        $this->decide($appeal, upheld: false)->assertOk();

        $this->actingAs($this->staff)
            ->getJson('/api/v1/admin/appeals')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // --- Deciding -------------------------------------------------------------

    /** **The undo ADR 0054 left open**, for a shop. */
    public function test_upholding_a_shops_appeal_puts_it_back_on_the_marketplace(): void
    {
        $this->suspend();
        $appeal = $this->appealTheSuspension();

        $this->decide($appeal, upheld: true, note: 'The certificate checks out.')
            ->assertOk()
            ->assertJsonPath('data.upheld', true)
            ->assertJsonPath('data.is_open', false);

        $shop = $this->shop->refresh();

        $this->assertSame(SellerStatus::Approved, $shop->status);
        $this->assertNull($shop->suspension_reason);

        $this->getJson("/api/v1/shops/{$shop->slug}")->assertOk();
    }

    /**
     * **A listing comes back as a draft, not on sale.** Clearing the removal
     * restores the seller's ability to sell it; putting it back on the
     * storefront would be the platform making a shop's decision for it.
     */
    public function test_upholding_a_listings_appeal_lets_it_be_published_again(): void
    {
        $listing = $this->removedListing();
        $appeal = Appeal::factory()->about($listing)->create(['user_id' => $this->owner->id]);

        $this->decide($appeal, upheld: true, note: 'The receipt settles it.')->assertOk();

        $listing->refresh();

        $this->assertNull($listing->removed_at);
        $this->assertNull($listing->removal_reason);

        // Still a draft. The undo is the removal, not the publication.
        $this->assertSame(ProductStatus::Draft, $listing->status);

        // And publishing it now works, which the takedown was refusing.
        $this->actingAs($this->owner)
            ->fromFrontend()
            ->postJson("/api/v1/seller/products/{$listing->id}/publication")
            ->assertOk();
    }

    public function test_upholding_a_reviews_appeal_makes_it_visible_again(): void
    {
        $author = User::factory()->create();
        $variant = $this->publishedVariant($this->shop);

        $review = Review::factory()->rated(2)->create([
            'product_id' => $variant->product_id,
            'user_id' => $author->id,
        ]);

        $review->forceFill([
            'hidden_at' => now(),
            'hidden_reason' => 'Aimed at the seller rather than at what was bought.',
            'hidden_by' => $this->staff->id,
        ])->save();

        $appeal = Appeal::factory()->about($review)->create(['user_id' => $author->id]);

        $this->decide($appeal, upheld: true, note: 'It is about the thing after all.')->assertOk();

        $this->assertNull($review->refresh()->hidden_at);

        $this->getJson("/api/v1/shops/{$this->shop->slug}/products/{$variant->product->slug}/reviews")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_dismissing_one_changes_nothing(): void
    {
        $this->suspend();
        $appeal = $this->appealTheSuspension();

        $this->decide($appeal, upheld: false, note: 'The original decision stands.')
            ->assertOk()
            ->assertJsonPath('data.upheld', false);

        $this->assertSame(SellerStatus::Suspended, $this->shop->refresh()->status);
    }

    public function test_deciding_one_twice_is_refused(): void
    {
        $this->suspend();
        $appeal = $this->appealTheSuspension();

        $this->decide($appeal, upheld: false)->assertOk();
        $this->decide($appeal, upheld: true)->assertStatus(409);
    }

    public function test_a_note_is_required(): void
    {
        $this->suspend();
        $appeal = $this->appealTheSuspension();

        $this->actingAs($this->staff)
            ->fromFrontend()
            ->postJson("/api/v1/admin/appeals/{$appeal->id}/decision", ['upheld' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('note');
    }

    /** Somebody deciding their own case is not deciding anything. */
    public function test_staff_cannot_decide_their_own_appeal(): void
    {
        $staffOwner = User::factory()->staff()->create();
        $shop = $this->approvedShop($staffOwner);

        $shop->forceFill([
            'status' => SellerStatus::Suspended,
            'suspended_at' => now(),
            'suspension_reason' => 'Something the platform decided.',
            'suspended_by' => $this->staff->id,
        ])->save();

        $appeal = Appeal::factory()->about($shop)->create(['user_id' => $staffOwner->id]);

        $this->actingAs($staffOwner)
            ->fromFrontend()
            ->postJson("/api/v1/admin/appeals/{$appeal->id}/decision", [
                'upheld' => true,
                'note' => 'Deciding my own case.',
            ])
            ->assertForbidden();
    }

    public function test_whoever_appealed_is_told_either_way(): void
    {
        Notification::fake();

        $this->suspend();
        $appeal = $this->appealTheSuspension();

        $this->decide($appeal, upheld: false)->assertOk();

        Notification::assertSentTo($this->owner, AppealDecided::class);
    }

    // --- What the shop is told ------------------------------------------------

    public function test_the_shop_is_told_whether_it_may_appeal(): void
    {
        $this->actingAs($this->owner)
            ->getJson('/api/v1/seller')
            ->assertOk()
            ->assertJsonPath('data.can_appeal', false);

        $this->suspend();

        $this->actingAs($this->owner)
            ->getJson('/api/v1/seller')
            ->assertOk()
            ->assertJsonPath('data.can_appeal', true);

        $this->appealTheSuspension();

        // One at a time: the page says it is being looked at rather than
        // offering the form again.
        $this->actingAs($this->owner)
            ->getJson('/api/v1/seller')
            ->assertOk()
            ->assertJsonPath('data.can_appeal', false);
    }

    // --- Fixtures -------------------------------------------------------------

    private function suspend(): void
    {
        $this->shop->forceFill([
            'status' => SellerStatus::Suspended,
            'suspended_at' => now(),
            'suspension_reason' => 'Trading under a name they do not own.',
            'suspended_by' => $this->staff->id,
        ])->save();
    }

    private function removedListing(): Product
    {
        $listing = $this->publishedVariant($this->shop)->product;

        $listing->forceFill([
            'status' => ProductStatus::Draft,
            'published_at' => null,
            'removed_at' => now(),
            'removal_reason' => 'Not what it claims to be.',
            'removed_by' => $this->staff->id,
        ])->save();

        return $listing->refresh();
    }

    private function appealTheSuspension(): Appeal
    {
        $this->actingAs($this->owner)
            ->fromFrontend()
            ->postJson('/api/v1/seller/appeal', ['reason' => 'The trademark is registered to us.'])
            ->assertCreated();

        return Appeal::query()->latest('id')->firstOrFail();
    }

    /**
     * @return TestResponse<Response>
     */
    private function decide(
        Appeal $appeal,
        bool $upheld,
        string $note = 'The platform has looked again.',
    ): TestResponse {
        return $this->actingAs($this->staff)
            ->fromFrontend()
            ->postJson("/api/v1/admin/appeals/{$appeal->id}/decision", [
                'upheld' => $upheld,
                'note' => $note,
            ]);
    }
}
