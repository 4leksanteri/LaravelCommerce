<?php

declare(strict_types=1);

namespace Tests\Feature\Reviews;

use App\Models\ProductVariant;
use App\Models\Review;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Orders\PlacesOrders;
use Tests\TestCase;

/**
 * What somebody who bought a thing may say about it (ADR 0047).
 *
 * The rule under all of it: a review needs a **completed** order. Completion is
 * the buyer confirming the parcel arrived, and it is what releases the money to
 * the shop - so it is the strongest statement this domain has that a person
 * actually received what they are talking about. Paid is not enough: a card is
 * charged before anything is posted.
 */
final class ReviewTest extends TestCase
{
    use PlacesOrders;
    use RefreshDatabase;

    private User $buyer;

    private User $shopOwner;

    private Seller $shop;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buyer = User::factory()->create(['name' => 'Aino Virtanen']);
        $this->shopOwner = User::factory()->create();
        $this->shop = $this->approvedShop($this->shopOwner);
        $this->variant = $this->publishedVariant($this->shop, stock: 20);
    }

    // --- Who may write one ---------------------------------------------------

    public function test_somebody_who_received_it_can_review_it(): void
    {
        $this->receiveAnOrder();

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson($this->reviewsUrl(), ['rating' => 5, 'body' => 'Exactly as described.'])
            ->assertCreated()
            ->assertJsonPath('data.rating', 5)
            ->assertJsonPath('data.body', 'Exactly as described.')
            ->assertJsonPath('data.was_edited', false)

            // Shortened, because a product page is public and a full name
            // against a purchase is nobody else's business.
            ->assertJsonPath('data.author', 'Aino V.');
    }

    /** A rating on its own is a review. Somebody gave four stars and meant it. */
    public function test_words_are_optional(): void
    {
        $this->receiveAnOrder();

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson($this->reviewsUrl(), ['rating' => 4])
            ->assertCreated()
            ->assertJsonPath('data.body', null);
    }

    public function test_a_rating_outside_one_to_five_is_refused(): void
    {
        $this->receiveAnOrder();

        foreach ([0, 6] as $rating) {
            $this->actingAs($this->buyer)
                ->fromFrontend()
                ->postJson($this->reviewsUrl(), ['rating' => $rating])
                ->assertStatus(422)
                ->assertJsonValidationErrors('rating');
        }
    }

    /**
     * Not 403: they are entitled to review things they receive, and this is
     * about where their orders have got to (ADR 0008).
     */
    public function test_somebody_who_never_bought_it_cannot_review_it(): void
    {
        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson($this->reviewsUrl(), ['rating' => 1, 'body' => 'Never bought it.'])
            ->assertStatus(409);
    }

    /** Paid and posted is not received. The clock that matters is completion. */
    public function test_an_order_that_has_only_been_sent_does_not_earn_one(): void
    {
        $order = $this->placeOrder($this->buyer, $this->variant);

        foreach (['acceptance', 'shipment'] as $step) {
            $this->actingAs($this->shopOwner)
                ->fromFrontend()
                ->postJson("/api/v1/seller/orders/{$order->reference}/{$step}")
                ->assertOk();
        }

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson($this->reviewsUrl(), ['rating' => 5])
            ->assertStatus(409);
    }

    public function test_a_second_review_of_the_same_listing_is_refused(): void
    {
        $this->receiveAnOrder();

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson($this->reviewsUrl(), ['rating' => 5])
            ->assertCreated();

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson($this->reviewsUrl(), ['rating' => 1])
            ->assertStatus(409);
    }

    // --- Changing your mind --------------------------------------------------

    public function test_the_author_can_rewrite_what_they_said(): void
    {
        $this->receiveAnOrder();

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson($this->reviewsUrl(), ['rating' => 2, 'body' => 'The strap broke.'])
            ->assertCreated();

        // So `updated_at` is past `created_at` by more than the same instant.
        $this->travel(2)->minutes();

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->patchJson($this->reviewsUrl(), ['rating' => 4, 'body' => 'They sent a new strap.'])
            ->assertOk()
            ->assertJsonPath('data.rating', 4)
            ->assertJsonPath('data.body', 'They sent a new strap.')

            // A reader is entitled to know it has been rewritten.
            ->assertJsonPath('data.was_edited', true);
    }

    /** Somebody else's review is not found, rather than refused. */
    public function test_nobody_else_can_change_it(): void
    {
        $this->receiveAnOrder();

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson($this->reviewsUrl(), ['rating' => 2])
            ->assertCreated();

        $this->actingAs(User::factory()->create())
            ->fromFrontend()
            ->patchJson($this->reviewsUrl(), ['rating' => 5])
            ->assertNotFound();
    }

    // --- What the storefront says --------------------------------------------

    public function test_a_listing_publishes_what_it_is_rated(): void
    {
        $this->receiveAnOrder();

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson($this->reviewsUrl(), ['rating' => 4])
            ->assertCreated();

        // Four, not 4.0: JSON has one number type, and a whole average is
        // written without a fractional part.
        $this->getJson($this->productUrl())
            ->assertOk()
            ->assertJsonPath('data.rating', 4)
            ->assertJsonPath('data.review_count', 1);

        // And on a card, where the aggregate is one join rather than a count
        // per listing.
        $this->getJson("/api/v1/shops/{$this->shop->slug}/products")
            ->assertOk()
            ->assertJsonPath('data.0.rating', 4)
            ->assertJsonPath('data.0.review_count', 1);

        /*
         * A second opinion, written straight to the table because this test is
         * about the average rather than about who may leave one.
         *
         * Four and five is four and a half, and it is the case worth asserting:
         * a rating that came back as 4 or 5 here would mean the average had
         * been rounded to an integer somewhere between PostgreSQL and the page.
         */
        Review::factory()->rated(5)->create([
            'product_id' => $this->variant->product_id,
            'user_id' => User::factory()->create()->id,
        ]);

        $this->getJson($this->productUrl())
            ->assertOk()
            ->assertJsonPath('data.rating', 4.5)
            ->assertJsonPath('data.review_count', 2);
    }

    public function test_a_listing_nobody_has_reviewed_says_so_rather_than_zero(): void
    {
        $this->getJson($this->productUrl())
            ->assertOk()
            ->assertJsonPath('data.rating', null)
            ->assertJsonPath('data.review_count', 0);
    }

    public function test_the_reviews_are_public_and_paged(): void
    {
        $this->receiveAnOrder();

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson($this->reviewsUrl(), ['rating' => 5, 'body' => 'Bought it twice.'])
            ->assertCreated();

        // Signed out: a review is for shoppers deciding.
        $this->getJson($this->reviewsUrl())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.body', 'Bought it twice.')
            ->assertJsonPath('meta.total', 1);
    }

    /**
     * The listing is resolved through the storefront's own scopes, so a draft
     * answers the same way it does everywhere else.
     */
    public function test_a_listing_that_is_not_on_sale_has_no_reviews_to_read(): void
    {
        $this->getJson("/api/v1/shops/{$this->shop->slug}/products/nothing-like-this/reviews")
            ->assertNotFound();
    }

    // --- What the buyer is told about their own standing ---------------------

    public function test_the_listing_tells_a_buyer_whether_they_may_review_it(): void
    {
        $this->actingAs($this->buyer)
            ->getJson($this->productUrl())
            ->assertOk()
            ->assertJsonPath('data.can_review', false)
            ->assertJsonPath('data.your_review', null);

        $this->receiveAnOrder();

        $this->actingAs($this->buyer)
            ->getJson($this->productUrl())
            ->assertOk()
            ->assertJsonPath('data.can_review', true);

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson($this->reviewsUrl(), ['rating' => 3])
            ->assertCreated();

        // Once said, there is nothing left to leave - and what they said comes
        // back with the listing so a page can offer to change it.
        $this->actingAs($this->buyer)
            ->getJson($this->productUrl())
            ->assertOk()
            ->assertJsonPath('data.can_review', false)
            ->assertJsonPath('data.your_review.rating', 3);
    }

    /**
     * Buys it, and confirms it arrived - which is what a review needs.
     *
     * Driven through the endpoints rather than written as rows, because the
     * timeline the database enforces is part of what makes an order completed.
     */
    private function receiveAnOrder(): void
    {
        $order = $this->placeOrder($this->buyer, $this->variant);

        foreach (['acceptance', 'shipment'] as $step) {
            $this->actingAs($this->shopOwner)
                ->fromFrontend()
                ->postJson("/api/v1/seller/orders/{$order->reference}/{$step}")
                ->assertOk();
        }

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson("/api/v1/orders/{$order->reference}/completion")
            ->assertOk();
    }

    private function productUrl(): string
    {
        return "/api/v1/shops/{$this->shop->slug}/products/{$this->variant->product->slug}";
    }

    private function reviewsUrl(): string
    {
        return $this->productUrl().'/reviews';
    }
}
