<?php

declare(strict_types=1);

namespace Tests\Feature\Moderation;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Report;
use App\Models\Review;
use App\Models\Seller;
use App\Models\User;
use App\Notifications\Moderation\ListingTakenDown;
use App\Notifications\Moderation\ReviewHidden;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Orders\PlacesOrders;
use Tests\TestCase;

/**
 * Reporting something, and what the platform does about it (ADR 0054).
 *
 * Two rules carry the whole chapter, and most of what is below is one of them:
 *
 * **What can be reported is what can be seen.** Every endpoint here resolves
 * its subject through the storefront's own scopes, so a draft, an unapproved
 * shop's listing and an already-hidden review are 404 rather than 403 - and
 * nobody can probe for what exists by reporting it.
 *
 * **Reporting is not accusing.** Nothing happens to a listing when it is
 * reported; it stays on sale until a person decides. The alternative would hand
 * anybody with two accounts the power to close a competitor's shop window for
 * as long as a queue takes.
 */
final class ReportTest extends TestCase
{
    use PlacesOrders;
    use RefreshDatabase;

    private User $reporter;

    private User $shopOwner;

    private User $staff;

    private Seller $shop;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reporter = User::factory()->create();
        $this->shopOwner = User::factory()->create();
        $this->staff = User::factory()->staff()->create();

        $this->shop = $this->approvedShop($this->shopOwner);
        $this->variant = $this->publishedVariant($this->shop, stock: 20);
    }

    // --- Reporting something -------------------------------------------------

    public function test_somebody_signed_in_reports_a_listing(): void
    {
        $this->actingAs($this->reporter)
            ->fromFrontend()
            ->postJson($this->listingReportsUrl(), [
                'reason' => 'counterfeit',
                'note' => 'The serial in the photographs belongs to a different model.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.reason', 'counterfeit')
            ->assertJsonPath('data.is_open', true)
            ->assertJsonPath('data.upheld', null)
            ->assertJsonPath('data.decided_at', null)
            ->assertJsonPath('data.subject.kind', 'listing')
            ->assertJsonPath('data.subject.title', $this->product()->name);
    }

    /**
     * A review is reported by id, unlike the review endpoints beside it.
     *
     * Those are a singleton because the only review you may touch is your own;
     * a report is always about somebody else's, so the path has to name which.
     */
    public function test_a_review_is_reported_by_id(): void
    {
        $review = $this->reviewBySomebodyElse();

        $this->actingAs($this->reporter)
            ->fromFrontend()
            ->postJson($this->reviewReportsUrl($review), ['reason' => 'abusive'])
            ->assertCreated()
            ->assertJsonPath('data.reason', 'abusive')
            ->assertJsonPath('data.subject.kind', 'review');
    }

    public function test_a_guest_cannot_report(): void
    {
        $this->postJson($this->listingReportsUrl(), ['reason' => 'spam'])
            ->assertUnauthorized();
    }

    /**
     * Not 403: they were entitled to report it, and did. What is in the way is
     * that the platform has not looked at the first one yet (ADR 0008).
     */
    public function test_reporting_the_same_thing_twice_is_refused(): void
    {
        $this->report();

        $this->actingAs($this->reporter)
            ->fromFrontend()
            ->postJson($this->listingReportsUrl(), ['reason' => 'spam'])
            ->assertStatus(409);
    }

    /**
     * **The partial unique index covers open reports only**, deliberately. A
     * listing that was fine in March may not be in June, and somebody whose
     * first report was dismissed is not barred from the subject forever.
     */
    public function test_a_dismissed_report_can_be_made_again(): void
    {
        $report = $this->report();

        $this->decide($report, upheld: false)->assertOk();

        $this->actingAs($this->reporter)
            ->fromFrontend()
            ->postJson($this->listingReportsUrl(), ['reason' => 'counterfeit'])
            ->assertCreated();
    }

    /** Two people may report the same thing, which is rather the point. */
    public function test_somebody_else_may_report_the_same_listing(): void
    {
        $this->report();

        $this->actingAs(User::factory()->create())
            ->fromFrontend()
            ->postJson($this->listingReportsUrl(), ['reason' => 'prohibited'])
            ->assertCreated();
    }

    public function test_a_reason_is_required(): void
    {
        $this->actingAs($this->reporter)
            ->fromFrontend()
            ->postJson($this->listingReportsUrl(), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    /**
     * "Something else" with nothing after it is a report nobody can act on, so
     * the one reason that carries no meaning on its own has to be explained.
     */
    public function test_other_needs_words(): void
    {
        $this->actingAs($this->reporter)
            ->fromFrontend()
            ->postJson($this->listingReportsUrl(), ['reason' => 'other'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('note');

        $this->actingAs($this->reporter)
            ->fromFrontend()
            ->postJson($this->listingReportsUrl(), [
                'reason' => 'other',
                'note' => 'It is the same listing posted eleven times.',
            ])
            ->assertCreated();
    }

    /** **Reporting is not accusing.** The listing is untouched until a person decides. */
    public function test_nothing_happens_to_the_listing_when_it_is_reported(): void
    {
        $this->report();

        $this->assertSame(ProductStatus::Published, $this->product()->refresh()->status);
        $this->assertNull($this->product()->refresh()->removed_at);

        $this->getJson($this->productUrl())->assertOk();
    }

    // --- What cannot be reported ---------------------------------------------

    public function test_a_draft_listing_cannot_be_reported(): void
    {
        $draft = Product::factory()->for($this->shop, 'seller')->create();

        $this->actingAs($this->reporter)
            ->fromFrontend()
            ->postJson("/api/v1/shops/{$this->shop->slug}/products/{$draft->slug}/reports", [
                'reason' => 'spam',
            ])
            ->assertNotFound();
    }

    public function test_a_listing_in_an_unapproved_shop_cannot_be_reported(): void
    {
        $pending = Seller::factory()->for(User::factory())->create();
        $listing = Product::factory()->for($pending, 'seller')->published()->create();

        $this->actingAs($this->reporter)
            ->fromFrontend()
            ->postJson("/api/v1/shops/{$pending->slug}/products/{$listing->slug}/reports", [
                'reason' => 'spam',
            ])
            ->assertNotFound();
    }

    /** Already out of sight, so there is nothing left to report. */
    public function test_a_hidden_review_cannot_be_reported(): void
    {
        $review = $this->reviewBySomebodyElse();
        $review->forceFill(['hidden_at' => now(), 'hidden_reason' => 'Gone.', 'hidden_by' => $this->staff->id])->save();

        $this->actingAs($this->reporter)
            ->fromFrontend()
            ->postJson($this->reviewReportsUrl($review), ['reason' => 'abusive'])
            ->assertNotFound();
    }

    /**
     * A review is resolved through its listing, so quoting one listing's review
     * id under another's slug finds nothing.
     */
    public function test_a_review_belonging_to_another_listing_is_not_found(): void
    {
        $elsewhere = $this->publishedVariant($this->shop);
        $review = Review::factory()->create([
            'product_id' => $elsewhere->product_id,
            'user_id' => User::factory()->create()->id,
        ]);

        $this->actingAs($this->reporter)
            ->fromFrontend()
            ->postJson($this->reviewReportsUrl($review), ['reason' => 'abusive'])
            ->assertNotFound();
    }

    // --- The queue ------------------------------------------------------------

    public function test_the_queue_is_staff_only(): void
    {
        $this->actingAs($this->reporter)
            ->getJson('/api/v1/admin/reports')
            ->assertForbidden();
    }

    public function test_the_queue_lists_what_is_open_oldest_first(): void
    {
        $review = $this->reviewBySomebodyElse();

        $this->report();
        $this->report($this->reviewReportsUrl($review), 'abusive');

        $this->actingAs($this->staff)
            ->getJson('/api/v1/admin/reports')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.subject.kind', 'listing')
            ->assertJsonPath('data.1.subject.kind', 'review')
            ->assertJsonPath('meta.total', 2);
    }

    /** A queue is a list of things to do, and a decided report is not one. */
    public function test_a_decided_report_leaves_the_queue(): void
    {
        $this->decide($this->report(), upheld: false)->assertOk();

        $this->actingAs($this->staff)
            ->getJson('/api/v1/admin/reports')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // --- Deciding -------------------------------------------------------------

    /** **Upholding a report is the takedown.** There is no other way to remove one. */
    public function test_upholding_a_listing_report_takes_it_down(): void
    {
        $this->decide($this->report(), upheld: true, note: 'It is not the camera in the photographs.')
            ->assertOk()
            ->assertJsonPath('data.upheld', true)
            ->assertJsonPath('data.is_open', false);

        $product = $this->product()->refresh();

        $this->assertSame(ProductStatus::Draft, $product->status);
        $this->assertNull($product->published_at);
        $this->assertNotNull($product->removed_at);
        $this->assertSame('It is not the camera in the photographs.', $product->removal_reason);
        $this->assertSame($this->staff->id, $product->removed_by);

        // And it is off the storefront, by the same scope that hides a draft.
        $this->getJson($this->productUrl())->assertNotFound();
    }

    /**
     * **Sticky, which is the whole point.** Without this the seller republishes
     * a minute later - exactly the hole suspension had to close for shops.
     */
    public function test_a_listing_taken_down_cannot_be_published_again(): void
    {
        $this->decide($this->report(), upheld: true)->assertOk();

        $this->actingAs($this->shopOwner)
            ->fromFrontend()
            ->postJson("/api/v1/seller/products/{$this->product()->id}/publication")
            ->assertStatus(409);
    }

    public function test_upholding_a_review_report_hides_it(): void
    {
        $review = $this->reviewBySomebodyElse(rating: 1);
        $report = $this->report($this->reviewReportsUrl($review), 'abusive');

        $this->decide($report, upheld: true, note: 'Aimed at the seller rather than at what was bought.')
            ->assertOk();

        $this->assertNotNull($review->refresh()->hidden_at);

        // Gone from the public list, which reads `visible()` rather than the
        // relation, and so had to say so itself.
        $this->getJson($this->reviewsUrl())
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // And out of the rating, which reads it through `Product::reviews()`.
        $this->getJson($this->productUrl())
            ->assertOk()
            ->assertJsonPath('data.rating', null)
            ->assertJsonPath('data.review_count', 0);
    }

    /**
     * **The row stays.** ADR 0047 refused deletion, and hiding is what the
     * platform does instead - so hiding somebody's review must not quietly hand
     * them a fresh one, which would be moderation undoing itself.
     */
    public function test_a_hidden_review_still_holds_its_authors_place(): void
    {
        /*
         * The author has to have genuinely earned the review, rather than
         * having one written to the table for them. Otherwise `LeaveReview`
         * refuses the second one for want of a completed order and this passes
         * without ever exercising the rule it is named for.
         */
        $author = User::factory()->create();
        $this->receiveAnOrder($author);

        $this->actingAs($author)
            ->fromFrontend()
            ->postJson($this->reviewsUrl(), ['rating' => 1, 'body' => 'It arrived broken.'])
            ->assertCreated();

        $review = Review::query()->latest('id')->firstOrFail();

        $this->decide($this->report($this->reviewReportsUrl($review), 'abusive'), upheld: true)->assertOk();

        $this->assertNotNull($review->refresh()->hidden_at);

        $this->actingAs($author)
            ->fromFrontend()
            ->postJson($this->reviewsUrl(), ['rating' => 5])
            ->assertStatus(409);
    }

    public function test_dismissing_a_report_changes_nothing(): void
    {
        $this->decide($this->report(), upheld: false, note: 'Nothing here breaks the rules.')
            ->assertOk()
            ->assertJsonPath('data.upheld', false);

        $this->assertSame(ProductStatus::Published, $this->product()->refresh()->status);
        $this->assertNull($this->product()->refresh()->removed_at);

        $this->getJson($this->productUrl())->assertOk();
    }

    /** Two members of staff reached the same report, and one of them lost. */
    public function test_deciding_one_twice_is_refused(): void
    {
        $report = $this->report();

        $this->decide($report, upheld: false)->assertOk();
        $this->decide($report, upheld: true)->assertStatus(409);
    }

    public function test_a_note_is_required(): void
    {
        $report = $this->report();

        $this->actingAs($this->staff)
            ->fromFrontend()
            ->postJson("/api/v1/admin/reports/{$report->id}/decision", ['upheld' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('note');
    }

    /** Somebody deciding their own complaint is not deciding anything. */
    public function test_staff_cannot_decide_a_report_they_made(): void
    {
        $staffReporter = User::factory()->staff()->create();

        $this->actingAs($staffReporter)
            ->fromFrontend()
            ->postJson($this->listingReportsUrl(), ['reason' => 'counterfeit'])
            ->assertCreated();

        $report = Report::query()->latest('id')->firstOrFail();

        $this->actingAs($staffReporter)
            ->fromFrontend()
            ->postJson("/api/v1/admin/reports/{$report->id}/decision", [
                'upheld' => true,
                'note' => 'Deciding my own.',
            ])
            ->assertForbidden();
    }

    /**
     * The owner hears, and the reporter does not.
     *
     * Telling the reporter would make every dismissed report an argument and
     * every upheld one a scoreboard.
     */
    public function test_the_owner_is_told_what_came_down(): void
    {
        Notification::fake();

        $this->decide($this->report(), upheld: true)->assertOk();

        Notification::assertSentTo($this->shop, ListingTakenDown::class);
        Notification::assertNothingSentTo($this->reporter);
    }

    public function test_the_author_is_told_when_their_review_is_hidden(): void
    {
        Notification::fake();

        $author = User::factory()->create();
        $review = $this->reviewBySomebodyElse(author: $author);

        $this->decide($this->report($this->reviewReportsUrl($review), 'abusive'), upheld: true)->assertOk();

        Notification::assertSentTo($author, ReviewHidden::class);
    }

    // --- What each side is told -----------------------------------------------

    public function test_a_listing_says_whether_the_viewer_may_report_it(): void
    {
        // A guest signs in first, so there is no button to draw.
        $this->getJson($this->productUrl())
            ->assertOk()
            ->assertJsonPath('data.can_report', false);

        $this->actingAs($this->reporter)
            ->getJson($this->productUrl())
            ->assertOk()
            ->assertJsonPath('data.can_report', true);

        // Reporting your own shop is not something the page should offer.
        $this->actingAs($this->shopOwner)
            ->getJson($this->productUrl())
            ->assertOk()
            ->assertJsonPath('data.can_report', false);
    }

    /** Answered on a card too, unlike `can_review`, because it costs nothing. */
    public function test_a_card_answers_it_as_well(): void
    {
        $this->actingAs($this->reporter)
            ->getJson("/api/v1/shops/{$this->shop->slug}/products")
            ->assertOk()
            ->assertJsonPath('data.0.can_report', true);
    }

    public function test_a_review_says_whether_the_reader_may_report_it(): void
    {
        $author = User::factory()->create();
        $this->reviewBySomebodyElse(author: $author);

        $this->actingAs($this->reporter)
            ->getJson($this->reviewsUrl())
            ->assertOk()
            ->assertJsonPath('data.0.can_report', true);

        // The way to take back what you wrote is to rewrite it.
        $this->actingAs($author)
            ->getJson($this->reviewsUrl())
            ->assertOk()
            ->assertJsonPath('data.0.can_report', false);
    }

    /**
     * A takedown with no explanation is a shop owner with nothing to fix, and
     * `can_publish` alone would draw a button that will never work.
     */
    public function test_the_seller_sees_why_their_listing_came_down(): void
    {
        $this->actingAs($this->shopOwner)
            ->getJson("/api/v1/seller/products/{$this->product()->id}")
            ->assertOk()
            ->assertJsonPath('data.was_removed_by_staff', false)
            ->assertJsonPath('data.removal_reason', null);

        $this->decide($this->report(), upheld: true, note: 'It is not the camera in the photographs.')
            ->assertOk();

        $this->actingAs($this->shopOwner)
            ->getJson("/api/v1/seller/products/{$this->product()->id}")
            ->assertOk()
            ->assertJsonPath('data.was_removed_by_staff', true)
            ->assertJsonPath('data.removal_reason', 'It is not the camera in the photographs.')
            ->assertJsonPath('data.status', 'draft');
    }

    // --- Fixtures -------------------------------------------------------------

    private function product(): Product
    {
        return $this->variant->product;
    }

    /**
     * A review written by somebody other than the reporter.
     *
     * Written straight to the table: what earns a review is ADR 0047's subject
     * and `ReviewTest` covers it, while everything here is about reporting one.
     */
    private function reviewBySomebodyElse(?User $author = null, int $rating = 3): Review
    {
        return Review::factory()->rated($rating)->create([
            'product_id' => $this->product()->id,
            'user_id' => ($author ?? User::factory()->create())->id,
        ]);
    }

    /**
     * A buyer who bought this listing and confirmed it arrived, which is what
     * `LeaveReview` requires (ADR 0047).
     *
     * Driven through the endpoints rather than written as rows, as `ReviewTest`
     * does it, because the timeline the database enforces is part of what makes
     * an order completed.
     */
    private function receiveAnOrder(User $buyer): void
    {
        $order = $this->placeOrder($buyer, $this->variant);

        foreach (['acceptance', 'shipment'] as $step) {
            $this->actingAs($this->shopOwner)
                ->fromFrontend()
                ->postJson("/api/v1/seller/orders/{$order->reference}/{$step}")
                ->assertOk();
        }

        $this->actingAs($buyer)
            ->fromFrontend()
            ->postJson("/api/v1/orders/{$order->reference}/completion")
            ->assertOk();
    }

    /** Files one, and hands back the row so a decision can name it. */
    private function report(?string $url = null, string $reason = 'counterfeit'): Report
    {
        $this->actingAs($this->reporter)
            ->fromFrontend()
            ->postJson($url ?? $this->listingReportsUrl(), ['reason' => $reason])
            ->assertCreated();

        return Report::query()->latest('id')->firstOrFail();
    }

    /**
     * @return TestResponse<Response>
     */
    private function decide(
        Report $report,
        bool $upheld,
        string $note = 'The platform has looked at this.',
    ): TestResponse {
        return $this->actingAs($this->staff)
            ->fromFrontend()
            ->postJson("/api/v1/admin/reports/{$report->id}/decision", [
                'upheld' => $upheld,
                'note' => $note,
            ]);
    }

    private function productUrl(): string
    {
        return "/api/v1/shops/{$this->shop->slug}/products/{$this->product()->slug}";
    }

    private function reviewsUrl(): string
    {
        return $this->productUrl().'/reviews';
    }

    private function listingReportsUrl(): string
    {
        return $this->productUrl().'/reports';
    }

    private function reviewReportsUrl(Review $review): string
    {
        return $this->reviewsUrl()."/{$review->id}/reports";
    }
}
