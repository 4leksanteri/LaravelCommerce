<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Enums\Currency;
use App\Enums\DisputeResolution;
use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PayoutAccount;
use App\Models\Seller;
use App\Models\User;
use App\Notifications\Orders\DisputeOpened;
use App\Notifications\Orders\DisputeResolved;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\PendingCommand;
use RuntimeException;
use Tests\Support\FakesStripe;
use Tests\TestCase;

/**
 * What happens when the two sides disagree about what arrived (ADR 0051).
 *
 * The rule under all of it: **a dispute exists exactly while the money is
 * held.** An order has to have shipped, and its payment has to be paid, not
 * refunded and not yet transferred - which is the same `isHeld()` both money
 * actions already gate on. Before that window a buyer can simply cancel; after
 * it, sending money back would be a Stripe reversal, and there are none.
 */
final class DisputeTest extends TestCase
{
    use FakesStripe;
    use RefreshDatabase;

    private User $buyer;

    private User $shopOwner;

    private User $staff;

    private Seller $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buyer = User::factory()->create(['name' => 'Aino Virtanen']);
        $this->shopOwner = User::factory()->create();
        $this->staff = User::factory()->staff()->create();

        $this->shop = Seller::factory()->for($this->shopOwner)->approved()->create([
            'currency' => Currency::EUR,
            'shop_name' => 'Second Hand Time',
        ]);

        // Pinned rather than read from the environment, as TransferAndRefundTest
        // pins it: what this suite believes about the arithmetic should not
        // move with a deployment's fee.
        config(['payments.platform_fee_bps' => 500]);
    }

    // --- Opening one ---------------------------------------------------------

    public function test_a_buyer_disputes_an_order_that_has_been_sent(): void
    {
        $order = $this->shippedOrder();

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson($this->disputeUrl($order), ['reason' => 'It never arrived.'])
            ->assertCreated()
            ->assertJsonPath('data.reason', 'It never arrived.')
            ->assertJsonPath('data.is_open', true)
            ->assertJsonPath('data.resolution', null)
            ->assertJsonPath('data.resolved_at', null);
    }

    public function test_the_shop_is_told_at_once(): void
    {
        Notification::fake();

        $order = $this->shippedOrder();

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson($this->disputeUrl($order), ['reason' => 'It never arrived.'])
            ->assertCreated();

        Notification::assertSentTo($this->shop, DisputeOpened::class);
    }

    /**
     * Nothing has gone wrong until something has been sent, and a buyer who
     * wants out before then can cancel instead.
     */
    public function test_an_order_that_has_not_shipped_cannot_be_disputed(): void
    {
        foreach ([OrderStatus::Pending, OrderStatus::Accepted] as $status) {
            $order = $this->shippedOrder($status);

            $this->actingAs($this->buyer)
                ->fromFrontend()
                ->postJson($this->disputeUrl($order), ['reason' => 'Too early.'])
                ->assertStatus(409);
        }
    }

    /**
     * **This used to be the bound, and ADR 0061 moved it.**
     *
     * It asserted that money reaching the shop ended the argument, because
     * sending it back would have been a reversal and ADR 0041 built none. There
     * is one now, so a completed order whose money has already gone can still
     * be disputed - the decision reverses the transfer and refunds from the
     * platform.
     *
     * Kept rather than deleted, and turned round: what it guards now is that
     * the window still *has* a far edge.
     */
    public function test_a_completed_order_can_still_be_disputed_while_the_window_is_open(): void
    {
        $order = $this->settledOrder(completedDaysAgo: 1);

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson($this->disputeUrl($order), ['reason' => 'I opened the box and it is the wrong lens.'])
            ->assertCreated();
    }

    /**
     * The far edge, and it is the half that protects the shop.
     *
     * Money that can be taken back at any time is money a shop can never treat
     * as its own, which costs honest sellers more than an unbounded window
     * would ever catch (ADR 0061).
     */
    public function test_an_order_completed_too_long_ago_cannot_be_disputed(): void
    {
        $days = (int) config('orders.dispute_after_completion_days');
        $order = $this->settledOrder(completedDaysAgo: $days + 1);

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson($this->disputeUrl($order), ['reason' => 'Far too late.'])
            ->assertStatus(409);
    }

    /** And the buyer's own page says so rather than offering a button that fails. */
    public function test_the_order_page_closes_the_window_with_it(): void
    {
        $days = (int) config('orders.dispute_after_completion_days');

        $open = $this->settledOrder(completedDaysAgo: 1);
        $shut = $this->settledOrder(completedDaysAgo: $days + 1);

        $this->actingAs($this->buyer)
            ->getJson("/api/v1/orders/{$open->reference}")
            ->assertOk()
            ->assertJsonPath('data.can_dispute', true);

        $this->actingAs($this->buyer)
            ->getJson("/api/v1/orders/{$shut->reference}")
            ->assertOk()
            ->assertJsonPath('data.can_dispute', false);
    }

    public function test_a_second_dispute_is_refused(): void
    {
        $order = $this->shippedOrder();

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson($this->disputeUrl($order), ['reason' => 'It never arrived.'])
            ->assertCreated();

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson($this->disputeUrl($order), ['reason' => 'Saying it again.'])
            ->assertStatus(409);
    }

    public function test_a_reason_is_required(): void
    {
        $order = $this->shippedOrder();

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson($this->disputeUrl($order), ['reason' => '   '])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    /** Somebody else's order is not found, rather than refused. */
    public function test_another_buyers_order_cannot_be_disputed(): void
    {
        $order = $this->shippedOrder();

        $this->actingAs(User::factory()->create())
            ->fromFrontend()
            ->postJson($this->disputeUrl($order), ['reason' => 'Not mine.'])
            ->assertNotFound();
    }

    // --- The clock ------------------------------------------------------------

    /**
     * **The mechanism the whole chapter rests on.** A shipped order completes
     * on its deadline and that transfers the money to the shop, so an order
     * being argued about must not reach it.
     */
    public function test_an_open_dispute_stops_auto_completion(): void
    {
        $order = $this->shippedOrder();
        $order->forceFill(['auto_complete_at' => now()->subDay()])->save();

        Dispute::factory()->for($order)->create();

        $this->console('orders:auto-complete')->assertExitCode(0);

        $this->assertSame(OrderStatus::Shipped, $order->refresh()->status);
        $this->assertNull($order->completed_at);
    }

    /** And once it has been decided, the clock runs again. */
    public function test_a_resolved_dispute_no_longer_holds_the_clock(): void
    {
        $this->verifiedShop();
        $stripe = $this->fakeStripe()->respond('POST', '/v1/transfers', [
            'id' => 'tr_1Sent',
            'object' => 'transfer',
        ]);

        $order = $this->shippedOrder();
        $order->forceFill(['auto_complete_at' => now()->subDay()])->save();

        Dispute::factory()->for($order)->resolved(DisputeResolution::Released, $this->staff)->create();

        $this->console('orders:auto-complete')->assertExitCode(0);

        $this->assertSame(OrderStatus::Completed, $order->refresh()->status);
        $this->assertSame(1, $stripe->timesSentTo('POST', '/v1/transfers'));
    }

    // --- Deciding one ---------------------------------------------------------

    /**
     * Decided for the buyer: the order is cancelled and the money goes back.
     *
     * The refund is asserted through the fake rather than assumed, because
     * `CancelOrder` reports a failed refund rather than raising it - so without
     * an answer queued here, `refunded_at` would stay null and this test would
     * be passing for the wrong reason.
     */
    public function test_staff_decide_for_the_buyer_and_the_money_goes_back(): void
    {
        $stripe = $this->fakeStripe()->respond('POST', '/v1/refunds', [
            'id' => 're_1Back',
            'object' => 'refund',
        ]);

        $order = $this->shippedOrder();
        $dispute = Dispute::factory()->for($order)->create();

        $this->actingAs($this->staff)
            ->fromFrontend()
            ->postJson($this->resolutionUrl($dispute), [
                'resolution' => 'refunded',
                'note' => 'Tracking shows it was never scanned.',
            ])
            ->assertOk()
            ->assertJsonPath('data.is_open', false)
            ->assertJsonPath('data.resolution', 'refunded');

        $order->refresh();

        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(OrderActor::Staff, $order->cancelled_by);

        // The platform's reasoning lives on the dispute, not on the order: the
        // database allows a cancellation reason only from a seller.
        $this->assertNull($order->cancellation_reason);

        $this->assertSame(1, $stripe->timesSentTo('POST', '/v1/refunds'));
        $this->assertNotNull($order->payment?->refunded_at);
    }

    /** Decided for the shop: the order completes and the money is released. */
    public function test_staff_decide_for_the_shop_and_the_money_is_released(): void
    {
        $this->verifiedShop();
        $stripe = $this->fakeStripe()->respond('POST', '/v1/transfers', [
            'id' => 'tr_1Sent',
            'object' => 'transfer',
        ]);

        $order = $this->shippedOrder();
        $dispute = Dispute::factory()->for($order)->create();

        $this->actingAs($this->staff)
            ->fromFrontend()
            ->postJson($this->resolutionUrl($dispute), [
                'resolution' => 'released',
                'note' => 'The carrier confirmed delivery and it was signed for.',
            ])
            ->assertOk()
            ->assertJsonPath('data.resolution', 'released');

        $order->refresh();

        $this->assertSame(OrderStatus::Completed, $order->status);

        // Not `deadline`, and not the buyer: the platform ended this one.
        $this->assertSame(OrderActor::Staff, $order->completed_by);

        // Five per cent of 95000 is 4750, and the shop gets the rest.
        $this->assertSame('90250', (string) $stripe->sentTo('POST', '/v1/transfers')['amount']);
    }

    public function test_both_sides_are_told_what_was_decided(): void
    {
        Notification::fake();

        $order = $this->shippedOrder();
        $dispute = Dispute::factory()->for($order)->create();

        $this->actingAs($this->staff)
            ->fromFrontend()
            ->postJson($this->resolutionUrl($dispute), [
                'resolution' => 'refunded',
                'note' => 'It never arrived.',
            ])
            ->assertOk();

        Notification::assertSentTo($this->buyer, DisputeResolved::class);
        Notification::assertSentTo($this->shop, DisputeResolved::class);
    }

    public function test_deciding_one_twice_is_refused(): void
    {
        $this->fakeStripe()->respond('POST', '/v1/refunds', ['id' => 're_1Back', 'object' => 'refund']);

        $order = $this->shippedOrder();
        $dispute = Dispute::factory()->for($order)->create();

        foreach (['refunded', 'released'] as $index => $resolution) {
            $response = $this->actingAs($this->staff)
                ->fromFrontend()
                ->postJson($this->resolutionUrl($dispute), [
                    'resolution' => $resolution,
                    'note' => 'A decision.',
                ]);

            $index === 0 ? $response->assertOk() : $response->assertStatus(409);
        }
    }

    public function test_a_note_is_required(): void
    {
        $dispute = Dispute::factory()->for($this->shippedOrder())->create();

        $this->actingAs($this->staff)
            ->fromFrontend()
            ->postJson($this->resolutionUrl($dispute), ['resolution' => 'refunded'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('note');
    }

    // --- Who may decide -------------------------------------------------------

    public function test_the_queue_is_staff_only(): void
    {
        $this->actingAs($this->buyer)
            ->getJson('/api/v1/admin/disputes')
            ->assertForbidden();
    }

    public function test_the_queue_lists_what_is_open(): void
    {
        Dispute::factory()->for($this->shippedOrder())->create();

        $this->actingAs($this->staff)
            ->getJson('/api/v1/admin/disputes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.buyer_name', 'Aino Virtanen')
            ->assertJsonPath('data.0.shop_name', 'Second Hand Time')
            ->assertJsonPath('data.0.total_minor', 95000);
    }

    /** A decided one leaves the queue: it is read on its order instead. */
    public function test_a_decided_dispute_is_not_in_the_queue(): void
    {
        Dispute::factory()
            ->for($this->shippedOrder())
            ->resolved(DisputeResolution::Refunded, $this->staff)
            ->create();

        $this->actingAs($this->staff)
            ->getJson('/api/v1/admin/disputes')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /** A person deciding where their own money goes is not a decision. */
    public function test_staff_cannot_decide_a_dispute_they_are_a_party_to(): void
    {
        $staffBuyer = User::factory()->staff()->create();
        $dispute = Dispute::factory()->for($this->shippedOrder(buyer: $staffBuyer))->create();

        $this->actingAs($staffBuyer)
            ->fromFrontend()
            ->postJson($this->resolutionUrl($dispute), [
                'resolution' => 'released',
                'note' => 'Deciding my own.',
            ])
            ->assertForbidden();
    }

    // --- What each side sees --------------------------------------------------

    public function test_both_order_pages_publish_the_dispute(): void
    {
        $order = $this->shippedOrder();

        $this->actingAs($this->buyer)
            ->getJson("/api/v1/orders/{$order->reference}")
            ->assertOk()
            ->assertJsonPath('data.dispute', null)
            ->assertJsonPath('data.can_dispute', true);

        Dispute::factory()->for($order)->create(['reason' => 'It never arrived.']);

        $this->actingAs($this->buyer)
            ->getJson("/api/v1/orders/{$order->reference}")
            ->assertOk()
            ->assertJsonPath('data.dispute.reason', 'It never arrived.')
            ->assertJsonPath('data.dispute.is_open', true)

            // Already disputed, so there is nothing left to raise.
            ->assertJsonPath('data.can_dispute', false);

        // The shop sees the same one, reason included: a complaint they cannot
        // read is one they cannot answer.
        $this->actingAs($this->shopOwner)
            ->getJson("/api/v1/seller/orders/{$order->reference}")
            ->assertOk()
            ->assertJsonPath('data.dispute.reason', 'It never arrived.');
    }

    // --- Fixtures -------------------------------------------------------------

    private function verifiedShop(): void
    {
        PayoutAccount::factory()->for($this->shop, 'seller')->active()->create([
            'stripe_account_id' => 'acct_1Shop',
        ]);
    }

    /**
     * A paid order at a point in its life.
     *
     * Built with factories rather than driven through the cart and checkout,
     * because what this suite is about is what happens after something shipped
     * - and the factory states set the timestamps `orders_timeline_check`
     * requires for each status.
     */
    private function shippedOrder(OrderStatus $status = OrderStatus::Shipped, ?User $buyer = null): Order
    {
        $factory = Order::factory()->for($buyer ?? $this->buyer)->for($this->shop);

        $factory = match ($status) {
            OrderStatus::Shipped => $factory->shipped(),
            OrderStatus::Accepted => $factory->accepted(),
            default => $factory,
        };

        $order = $factory->create([
            'currency' => Currency::EUR,
            'total_minor' => 95000,
        ]);

        Payment::factory()->forOrder($order)->paid()->create();

        return $order->refresh();
    }

    /**
     * A completed order whose money has already reached the shop (ADR 0061).
     *
     * The state the old bound refused outright, and the one the reversal exists
     * for. `completed_at` is set back rather than the clock moved, so a test can
     * stand on either side of the window without travelling in time.
     */
    private function settledOrder(int $completedDaysAgo): Order
    {
        $order = $this->shippedOrder();

        $order->forceFill([
            'status' => OrderStatus::Completed,
            'completed_at' => now()->subDays($completedDaysAgo),
            'completed_by' => OrderActor::Buyer,
        ])->save();

        $order->payment?->forceFill([
            'platform_fee_minor' => 4750,
            'stripe_transfer_id' => 'tr_1Gone'.$order->id,
            'transferred_at' => now()->subDays($completedDaysAgo),
        ])->save();

        return $order->refresh();
    }

    private function disputeUrl(Order $order): string
    {
        return "/api/v1/orders/{$order->reference}/dispute";
    }

    private function resolutionUrl(Dispute $dispute): string
    {
        return "/api/v1/admin/disputes/{$dispute->id}/resolution";
    }

    /**
     * `$this->artisan()` with a type, as TransferAndRefundTest and
     * ExpireOrdersTest do.
     *
     * It is declared `PendingCommand|int` - an int when console output is not
     * being mocked, which it is here. The branch proves that to the analyser
     * rather than promising it.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function console(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);

        if (! $pending instanceof PendingCommand) {
            throw new RuntimeException('Console output is not being mocked, so nothing can be asserted on it.');
        }

        return $pending;
    }
}
