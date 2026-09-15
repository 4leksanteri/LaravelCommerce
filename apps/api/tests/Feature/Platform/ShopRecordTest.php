<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Enums\SellerStatus;
use App\Models\Appeal;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PayoutAccount;
use App\Models\PlatformDecision;
use App\Models\Product;
use App\Models\Report;
use App\Models\Review;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Orders\PlacesOrders;
use Tests\Support\FakesStripe;
use Tests\TestCase;

/**
 * What the platform has decided about a shop (ADR 0060).
 *
 * **Every sanction here is driven through the endpoint that takes it**, never
 * written onto a model with `forceFill`. That is the whole discipline of this
 * file: a record is written by the action taking the decision, so a fixture
 * that sets the columns directly would leave nothing to assert and every test
 * below would pass while proving nothing.
 *
 * The claim it exists for: **a lifted sanction erases itself**.
 * `sellers_suspension_is_whole` and `products_removal_is_whole` tie the
 * columns to a status, so reinstating a shop and restoring a listing null them
 * - and without this table a shop stopped three times reads as one never
 * stopped at all.
 */
final class ShopRecordTest extends TestCase
{
    use FakesStripe;
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

    // --- The claim this table exists for --------------------------------------

    /**
     * **The whole point.** Lifting a suspension clears every column that said
     * it happened, and the record is what is left.
     */
    public function test_a_suspension_outlives_being_lifted(): void
    {
        $this->suspend();
        $this->reinstate();

        $shop = $this->shop->refresh();

        // Nothing on the shop says it was ever stopped.
        $this->assertSame(SellerStatus::Approved, $shop->status);
        $this->assertNull($shop->suspended_at);
        $this->assertNull($shop->suspension_reason);
        $this->assertNull($shop->suspended_by);

        // The record says it twice: stopped, and let back.
        $this->assertSame(
            ['shop_reinstated', 'shop_suspended'],
            $this->kinds(),
        );
    }

    /** Newest first: this is a record, not a queue of work to do. */
    public function test_the_record_reads_newest_first(): void
    {
        $this->suspend();
        $this->reinstate();

        $this->assertSame(
            ['shop_reinstated', 'shop_suspended'],
            $this->read()->assertOk()->json('data.*.kind'),
        );
    }

    /** The reason given at the time, which the shop's own columns no longer have. */
    public function test_the_record_keeps_the_reason_the_shop_was_given(): void
    {
        $this->suspend();
        $this->reinstate();

        $this->assertSame(
            'Three disputes decided against it this month.',
            $this->read()->assertOk()->json('data.1.reason'),
        );
    }

    // --- Takedowns ------------------------------------------------------------

    public function test_a_takedown_outlives_the_appeal_that_undoes_it(): void
    {
        $listing = $this->takeDownAListing();

        $this->assertSame(['listing_removed'], $this->kinds());

        $appeal = $this->appealFor($listing);
        $this->decide($appeal, upheld: true)->assertOk();

        $listing->refresh();

        // The listing itself has forgotten entirely.
        $this->assertNull($listing->removed_at);
        $this->assertNull($listing->removal_reason);
        $this->assertNull($listing->removed_by);

        $this->assertSame(
            ['appeal_upheld', 'listing_restored', 'listing_removed'],
            $this->kinds(),
        );
    }

    public function test_a_dismissed_appeal_is_recorded_and_the_listing_stays_down(): void
    {
        $listing = $this->takeDownAListing();
        $appeal = $this->appealFor($listing);

        $this->decide($appeal, upheld: false)->assertOk();

        $this->assertNotNull($listing->refresh()->removed_at);
        $this->assertSame(['appeal_dismissed', 'listing_removed'], $this->kinds());
    }

    /** A record is read to weigh a shop, so it says which way each one went. */
    public function test_the_record_says_which_decisions_count_against_the_shop(): void
    {
        $listing = $this->takeDownAListing();
        $this->decide($this->appealFor($listing), upheld: true)->assertOk();

        $this->assertSame(
            [false, false, true],
            $this->read()->assertOk()->json('data.*.counts_against_the_shop'),
        );
    }

    // --- What is deliberately not recorded ------------------------------------

    /**
     * **Hiding a review is not the shop's doing**, so it is on no shop's record.
     * Counting it would show somebody weighing a suspension three strikes that
     * the shop's own customers had earned.
     */
    public function test_hiding_a_review_is_on_nobodys_record(): void
    {
        $this->hideAReview();

        $this->assertSame(0, PlatformDecision::query()->count());
    }

    /** And neither is an appeal about one, for the same reason. */
    public function test_an_appeal_about_a_hidden_review_is_on_nobodys_record(): void
    {
        [$review, $author] = $this->hideAReview();
        $listing = $review->product;

        $this->actingAs($author)
            ->fromFrontend()
            ->postJson("/api/v1/shops/{$this->shop->slug}/products/{$listing->slug}/reviews/appeal", [
                'reason' => 'It is about the pedal rather than about the person selling it.',
            ])
            ->assertCreated();

        $this->decide(Appeal::query()->latest('id')->firstOrFail(), upheld: true)->assertOk();

        $this->assertNull($review->refresh()->hidden_at);
        $this->assertSame(0, PlatformDecision::query()->count());
    }

    // --- Disputes -------------------------------------------------------------

    public function test_a_dispute_decided_for_the_buyer_counts_against_the_shop(): void
    {
        $this->fakeStripe()->respond('POST', '/v1/refunds', ['id' => 're_1Back', 'object' => 'refund']);

        $this->resolve('refunded', 'Tracking shows it was never scanned.')->assertOk();

        $this->assertSame(['dispute_refunded'], $this->kinds());

        $record = $this->read()->assertOk();

        $this->assertTrue($record->json('data.0.counts_against_the_shop'));
        $this->assertSame('dispute', $record->json('data.0.subject.kind'));
    }

    /** Decided the shop's way, and the record says so rather than staying quiet. */
    public function test_a_dispute_released_to_the_shop_is_recorded_without_counting_against_it(): void
    {
        PayoutAccount::factory()->for($this->shop, 'seller')->active()->create([
            'stripe_account_id' => 'acct_1Shop',
        ]);

        $this->fakeStripe()->respond('POST', '/v1/transfers', ['id' => 'tr_1Sent', 'object' => 'transfer']);

        $this->resolve('released', 'The carrier confirmed it was signed for.')->assertOk();

        $this->assertSame(['dispute_released'], $this->kinds());
        $this->assertFalse($this->read()->assertOk()->json('data.0.counts_against_the_shop'));
    }

    // --- Who may read it ------------------------------------------------------

    public function test_the_record_is_staff_only(): void
    {
        $this->actingAs($this->owner)
            ->getJson("/api/v1/admin/sellers/{$this->shop->id}/decisions")
            ->assertForbidden();
    }

    public function test_a_guest_is_refused_rather_than_shown_nothing(): void
    {
        $this->getJson("/api/v1/admin/sellers/{$this->shop->id}/decisions")->assertUnauthorized();
    }

    // --- What it publishes ----------------------------------------------------

    public function test_it_publishes_exactly_these_keys(): void
    {
        $this->suspend();

        $body = $this->read()->assertOk()->json('data.0');

        $this->assertIsArray($body);
        $this->assertSame(
            ['id', 'kind', 'counts_against_the_shop', 'reason', 'decided_at', 'subject'],
            array_keys($body),
        );

        $this->assertSame(['kind', 'title', 'href'], array_keys((array) $body['subject']));
    }

    /**
     * Who decided is recorded and never published, for the reason a dispute's
     * `resolved_by` and a suspension's `suspended_by` are not.
     */
    public function test_it_never_says_who_decided(): void
    {
        $this->suspend();

        $this->read()->assertOk()->assertJsonMissing(['decided_by' => $this->staff->id]);

        $this->assertSame(
            $this->staff->id,
            PlatformDecision::query()->latest('id')->firstOrFail()->decided_by,
        );
    }

    /**
     * A morph carries no foreign key, so a seller may delete the listing they
     * were punished over. The record survives and says the subject has gone.
     */
    public function test_a_deleted_listing_leaves_the_record_standing(): void
    {
        $listing = $this->takeDownAListing();

        $listing->delete();

        $record = $this->read()->assertOk();

        $this->assertSame('listing_removed', $record->json('data.0.kind'));
        $this->assertNull($record->json('data.0.subject'));
    }

    // --- Fixtures -------------------------------------------------------------

    /**
     * Through the endpoint, never by `forceFill`. A suspension written onto the
     * model directly records nothing, which would make every test above pass
     * for the wrong reason.
     */
    private function suspend(): void
    {
        $this->actingAs($this->staff)
            ->fromFrontend()
            ->postJson("/api/v1/admin/sellers/{$this->shop->id}/suspension", [
                'reason' => 'Three disputes decided against it this month.',
            ])
            ->assertOk();
    }

    private function reinstate(): void
    {
        $this->actingAs($this->staff)
            ->fromFrontend()
            ->deleteJson("/api/v1/admin/sellers/{$this->shop->id}/suspension")
            ->assertOk();
    }

    /** Reported by a shopper, then upheld by staff - the only way a listing comes down. */
    private function takeDownAListing(): Product
    {
        $listing = $this->publishedVariant($this->shop)->product;

        $this->actingAs(User::factory()->create())
            ->fromFrontend()
            ->postJson("/api/v1/shops/{$this->shop->slug}/products/{$listing->slug}/reports", [
                'reason' => 'counterfeit',
                'note' => 'The photographs are lifted from another shop.',
            ])
            ->assertCreated();

        $this->actingAs($this->staff)
            ->fromFrontend()
            ->postJson('/api/v1/admin/reports/'.Report::query()->latest('id')->firstOrFail()->id.'/decision', [
                'upheld' => true,
                'note' => 'Not what it claims to be.',
            ])
            ->assertOk();

        return $listing->refresh();
    }

    /**
     * A review hidden the same way: reported, then upheld.
     *
     * @return array{0: Review, 1: User}
     */
    private function hideAReview(): array
    {
        $author = User::factory()->create();
        $listing = $this->publishedVariant($this->shop)->product;

        $review = Review::factory()->rated(2)->create([
            'product_id' => $listing->id,
            'user_id' => $author->id,
        ]);

        $this->actingAs(User::factory()->create())
            ->fromFrontend()
            ->postJson(
                "/api/v1/shops/{$this->shop->slug}/products/{$listing->slug}/reviews/{$review->id}/reports",
                ['reason' => 'abusive', 'note' => 'It is about the seller rather than the pedal.'],
            )
            ->assertCreated();

        $this->actingAs($this->staff)
            ->fromFrontend()
            ->postJson('/api/v1/admin/reports/'.Report::query()->latest('id')->firstOrFail()->id.'/decision', [
                'upheld' => true,
                'note' => 'Aimed at the seller rather than at what was bought.',
            ])
            ->assertOk();

        return [$review->refresh(), $author];
    }

    private function appealFor(Product $listing): Appeal
    {
        $this->actingAs($this->owner)
            ->fromFrontend()
            ->postJson("/api/v1/seller/products/{$listing->id}/appeal", [
                'reason' => 'It is the item in the photographs, and I still have the receipt.',
            ])
            ->assertCreated();

        return Appeal::query()->latest('id')->firstOrFail();
    }

    /**
     * @return TestResponse<Response>
     */
    private function decide(Appeal $appeal, bool $upheld): TestResponse
    {
        return $this->actingAs($this->staff)
            ->fromFrontend()
            ->postJson("/api/v1/admin/appeals/{$appeal->id}/decision", [
                'upheld' => $upheld,
                'note' => 'The platform has looked again and this is what it found.',
            ]);
    }

    /**
     * A disputed order, decided. Built with factories for the reason
     * `DisputeTest` gives: what matters here is what happens after it shipped.
     *
     * @return TestResponse<Response>
     */
    private function resolve(string $resolution, string $note): TestResponse
    {
        $order = Order::factory()
            ->for(User::factory()->create())
            ->for($this->shop)
            ->shipped()
            ->create(['currency' => $this->shop->currency, 'total_minor' => 95000]);

        Payment::factory()->forOrder($order)->paid()->create();

        $dispute = Dispute::factory()->for($order->refresh())->create();

        return $this->actingAs($this->staff)
            ->fromFrontend()
            ->postJson("/api/v1/admin/disputes/{$dispute->id}/resolution", [
                'resolution' => $resolution,
                'note' => $note,
            ]);
    }

    /**
     * Every kind on the shop's record, newest first.
     *
     * @return array<int, string>
     */
    private function kinds(): array
    {
        // `get()` and map rather than `pluck`, which hydrates through the cast
        // and hands back DecisionKind instances a string comparison would choke
        // on.
        return PlatformDecision::query()
            ->where('seller_id', $this->shop->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (PlatformDecision $decision): string => $decision->kind->value)
            ->all();
    }

    /**
     * @return TestResponse<Response>
     */
    private function read(): TestResponse
    {
        return $this->actingAs($this->staff)
            ->getJson("/api/v1/admin/sellers/{$this->shop->id}/decisions");
    }
}
