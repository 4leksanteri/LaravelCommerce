<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Models\Order;
use App\Models\Seller;
use App\Models\User;
use App\Notifications\Orders\MessageReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * What the two sides of an order say to each other (ADR 0050).
 *
 * One conversation per order, read by both parties at two different addresses.
 * Being a party to the order is the whole permission: each route is scoped to
 * its own relation, so somebody else's conversation is not refused, it is never
 * in the query.
 */
final class OrderMessageTest extends TestCase
{
    use PlacesOrders;
    use RefreshDatabase;

    private User $buyer;

    private User $shopOwner;

    private Seller $shop;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buyer = User::factory()->create();
        $this->shopOwner = User::factory()->create();
        $this->shop = $this->approvedShop($this->shopOwner);
        $this->order = $this->placeOrder($this->buyer, $this->publishedVariant($this->shop));
    }

    // --- Saying something ----------------------------------------------------

    public function test_the_buyer_writes_to_the_shop_and_the_shop_reads_it(): void
    {
        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson($this->buyerUrl(), ['body' => 'Has this been posted yet?'])
            ->assertCreated()
            ->assertJsonPath('data.body', 'Has this been posted yet?')
            ->assertJsonPath('data.sender', 'buyer')
            ->assertJsonPath('data.read_at', null);

        $this->actingAs($this->shopOwner)
            ->getJson($this->shopUrl())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.body', 'Has this been posted yet?');
    }

    public function test_the_shop_writes_back_and_the_buyer_reads_it(): void
    {
        $this->actingAs($this->shopOwner)
            ->fromFrontend()
            ->postJson($this->shopUrl(), ['body' => 'Going out this afternoon.'])
            ->assertCreated()
            ->assertJsonPath('data.sender', 'seller');

        $this->actingAs($this->buyer)
            ->getJson($this->buyerUrl())
            ->assertOk()
            ->assertJsonPath('data.0.body', 'Going out this afternoon.');
    }

    /**
     * Oldest first. A conversation read backwards is not one, and this is the
     * one list in the application that is not newest-first.
     */
    public function test_a_conversation_reads_in_the_order_it_was_said(): void
    {
        $this->say($this->buyer, $this->buyerUrl(), 'First.');
        $this->say($this->shopOwner, $this->shopUrl(), 'Second.');
        $this->say($this->buyer, $this->buyerUrl(), 'Third.');

        $this->actingAs($this->buyer)
            ->getJson($this->buyerUrl())
            ->assertOk()
            ->assertJsonPath('data.0.body', 'First.')
            ->assertJsonPath('data.1.body', 'Second.')
            ->assertJsonPath('data.2.body', 'Third.');
    }

    /**
     * **The sender is the route, never the payload.** A buyer who sends one
     * cannot sign it as the shop, because nothing reads a sender from a body.
     */
    public function test_a_sender_in_the_payload_is_ignored(): void
    {
        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson($this->buyerUrl(), ['body' => 'Not the shop.', 'sender' => 'seller'])
            ->assertCreated()
            ->assertJsonPath('data.sender', 'buyer');
    }

    public function test_a_message_has_to_say_something(): void
    {
        foreach (['', '   '] as $body) {
            $this->actingAs($this->buyer)
                ->fromFrontend()
                ->postJson($this->buyerUrl(), ['body' => $body])
                ->assertStatus(422)
                ->assertJsonValidationErrors('body');
        }
    }

    /**
     * The decision the whole chapter rests on: there is no state in which these
     * two stop being able to talk. A cancelled order is where a conversation is
     * needed most.
     */
    public function test_a_cancelled_order_can_still_be_talked_about(): void
    {
        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson("/api/v1/orders/{$this->order->reference}/cancellation")
            ->assertOk();

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson($this->buyerUrl(), ['body' => 'Sorry, I ordered the wrong one.'])
            ->assertCreated();

        $this->actingAs($this->shopOwner)
            ->fromFrontend()
            ->postJson($this->shopUrl(), ['body' => 'No trouble at all.'])
            ->assertCreated();
    }

    // --- Whose conversation it is --------------------------------------------

    public function test_somebody_elses_order_is_not_found(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->getJson($this->buyerUrl())
            ->assertNotFound();

        $this->actingAs($stranger)
            ->fromFrontend()
            ->postJson($this->buyerUrl(), ['body' => 'Let me in.'])
            ->assertNotFound();
    }

    public function test_another_shops_order_is_not_found(): void
    {
        $otherOwner = User::factory()->create();
        $this->approvedShop($otherOwner);

        $this->actingAs($otherOwner)
            ->getJson($this->shopUrl())
            ->assertNotFound();
    }

    public function test_it_needs_somebody_signed_in(): void
    {
        /*
         * `placeOrder` signs the buyer in to reach checkout, and `actingAs`
         * persists on the test instance - so without this the "signed out"
         * request is still the buyer's, and asserts nothing. PaginationTest
         * hit the same thing and says so at `fetch()`.
         */
        Auth::forgetGuards();

        $this->getJson($this->buyerUrl())->assertUnauthorized();
    }

    /**
     * An unpaid order does not exist to its shop (ADR 0042), so there is
     * nothing for the shop to be written to about.
     */
    public function test_a_shop_cannot_be_written_to_about_an_unpaid_order(): void
    {
        $unpaid = $this->placeUnpaidOrder($this->buyer, $this->publishedVariant($this->shop));

        $this->actingAs($this->shopOwner)
            ->getJson("/api/v1/seller/orders/{$unpaid->reference}/messages")
            ->assertNotFound();
    }

    // --- What is waiting to be read ------------------------------------------

    public function test_each_side_is_told_what_it_has_not_read(): void
    {
        $this->say($this->buyer, $this->buyerUrl(), 'Two questions.');
        $this->say($this->buyer, $this->buyerUrl(), 'Is it boxed?');

        // The shop has two waiting; the buyer has none, because a message is
        // never unread to whoever wrote it.
        $this->actingAs($this->shopOwner)
            ->getJson("/api/v1/seller/orders/{$this->order->reference}")
            ->assertOk()
            ->assertJsonPath('data.unread_message_count', 2);

        $this->actingAs($this->buyer)
            ->getJson("/api/v1/orders/{$this->order->reference}")
            ->assertOk()
            ->assertJsonPath('data.unread_message_count', 0);
    }

    /** The same figure, in the list, where it comes from the scope's join. */
    public function test_the_lists_carry_the_same_count(): void
    {
        $this->say($this->shopOwner, $this->shopUrl(), 'Posted this morning.');

        $this->actingAs($this->buyer)
            ->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonPath('data.0.unread_message_count', 1);

        $this->actingAs($this->shopOwner)
            ->getJson('/api/v1/seller/orders')
            ->assertOk()
            ->assertJsonPath('data.0.unread_message_count', 0);
    }

    public function test_reading_clears_only_the_other_sides_messages(): void
    {
        $this->say($this->buyer, $this->buyerUrl(), 'A question.');
        $this->say($this->shopOwner, $this->shopUrl(), 'An answer.');

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson($this->buyerUrl().'/read')
            ->assertNoContent();

        // The buyer has read the shop's message; the shop has not read theirs.
        $this->actingAs($this->buyer)
            ->getJson("/api/v1/orders/{$this->order->reference}")
            ->assertJsonPath('data.unread_message_count', 0);

        $this->actingAs($this->shopOwner)
            ->getJson("/api/v1/seller/orders/{$this->order->reference}")
            ->assertJsonPath('data.unread_message_count', 1);
    }

    /** A second call has nothing left to do, and says so without failing. */
    public function test_marking_read_twice_is_harmless(): void
    {
        $this->say($this->shopOwner, $this->shopUrl(), 'Anything else?');

        foreach ([1, 2] as $ignored) {
            $this->actingAs($this->buyer)
                ->fromFrontend()
                ->postJson($this->buyerUrl().'/read')
                ->assertNoContent();
        }

        $this->actingAs($this->buyer)
            ->getJson("/api/v1/orders/{$this->order->reference}")
            ->assertJsonPath('data.unread_message_count', 0);
    }

    /** The sender is told when the other side has read what they wrote. */
    public function test_a_message_records_when_it_was_read(): void
    {
        $this->say($this->buyer, $this->buyerUrl(), 'Did you see this?');

        $this->actingAs($this->shopOwner)
            ->fromFrontend()
            ->postJson($this->shopUrl().'/read')
            ->assertNoContent();

        $this->actingAs($this->buyer)
            ->getJson($this->buyerUrl())
            ->assertOk()
            ->assertJsonPath('data.0.read_at', fn (?string $readAt): bool => $readAt !== null);
    }

    // --- Telling the other side ----------------------------------------------

    public function test_the_shop_is_told_when_the_buyer_writes(): void
    {
        Notification::fake();

        $this->say($this->buyer, $this->buyerUrl(), 'Has it gone out?');

        Notification::assertSentTo($this->shopOwner, MessageReceived::class);
        Notification::assertNotSentTo($this->buyer, MessageReceived::class);
    }

    public function test_the_buyer_is_told_when_the_shop_writes(): void
    {
        Notification::fake();

        $this->say($this->shopOwner, $this->shopUrl(), 'It went out today.');

        Notification::assertSentTo($this->buyer, MessageReceived::class);
        Notification::assertNotSentTo($this->shopOwner, MessageReceived::class);
    }

    /**
     * The mail carries the message itself and links to the recipient's own
     * address for the order - the two sides read it at different ones.
     */
    public function test_the_mail_quotes_the_message_and_links_to_the_readers_page(): void
    {
        $this->say($this->buyer, $this->buyerUrl(), 'Please send it recorded.');

        $message = $this->order->messages()->firstOrFail();
        $mail = (new MessageReceived($this->order->refresh(), $message))->toMail($this->shopOwner);

        $this->assertStringContainsString('Please send it recorded.', implode(' ', $mail->introLines));
        $this->assertSame('Read and reply', $mail->actionText);
        $this->assertStringContainsString("/seller/orders/{$this->order->reference}", (string) $mail->actionUrl);
    }

    private function say(User $actor, string $url, string $body): void
    {
        $this->actingAs($actor)
            ->fromFrontend()
            ->postJson($url, ['body' => $body])
            ->assertCreated();
    }

    private function buyerUrl(): string
    {
        return "/api/v1/orders/{$this->order->reference}/messages";
    }

    private function shopUrl(): string
    {
        return "/api/v1/seller/orders/{$this->order->reference}/messages";
    }
}
