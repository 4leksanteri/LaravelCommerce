<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Actions\Payments\ReverseTransfer;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use RuntimeException;
use Tests\Support\FakesStripe;
use Tests\TestCase;

/**
 * Where the money goes when an order finishes (ADR 0041).
 *
 * The claim on every page of this marketplace is that a payment is held until
 * the buyer confirms the parcel arrived. These are the tests that make it true:
 * completion sends it on, cancelling gives it back, and a shop that cannot yet
 * receive money keeps waiting rather than losing it.
 */
final class TransferAndRefundTest extends TestCase
{
    use FakesStripe;
    use RefreshDatabase;

    private User $buyer;

    private Seller $shop;

    private User $shopOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buyer = User::factory()->create();
        $this->shopOwner = User::factory()->create();
        $this->shop = Seller::factory()->for($this->shopOwner)->approved()->create([
            'currency' => Currency::EUR,
        ]);

        /*
         * The fee is pinned rather than read from the environment. It is
         * configuration (ADR 0041), and a deployment that charges 6.5% should
         * not change what this suite believes about the arithmetic.
         */
        config(['payments.platform_fee_bps' => 500]);
    }

    private function verifiedShop(): void
    {
        PayoutAccount::factory()->for($this->shop, 'seller')->active()->create([
            'stripe_account_id' => 'acct_1Shop',
        ]);
    }

    /**
     * An order at a point in its life, paid for.
     *
     * The factory's own states set the timestamps each status requires - the
     * `orders_timeline_check` constraint refuses a pending order with an
     * acceptance date, and a completed one without a shipping date.
     */
    private function paidOrder(OrderStatus $status, int $totalMinor = 95000): Order
    {
        $factory = Order::factory()->for($this->buyer)->for($this->shop);

        $factory = match ($status) {
            OrderStatus::Shipped => $factory->shipped(),
            OrderStatus::Completed => $factory->completed(),

            // Who cancelled is recorded on every real cancellation (ADR 0035),
            // and the refund puts it in Stripe's metadata.
            OrderStatus::Cancelled => $factory->cancelled()->state(['cancelled_by' => OrderActor::Buyer]),

            default => $factory,
        };

        $order = $factory->create([
            'currency' => Currency::EUR,
            'total_minor' => $totalMinor,
        ]);

        Payment::factory()->forOrder($order)->paid()->create();

        return $order->refresh();
    }

    // --- Completion sends it on ----------------------------------------------

    public function test_completing_an_order_transfers_the_total_less_the_fee(): void
    {
        $this->verifiedShop();
        $order = $this->paidOrder(OrderStatus::Shipped);
        $stripe = $this->fakeStripe()->respond('POST', '/v1/transfers', ['id' => 'tr_1Sent', 'object' => 'transfer']);

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson("/api/v1/orders/{$order->reference}/completion")
            ->assertOk();

        // Five per cent of 95000 is 4750, and the shop gets the rest.
        $sent = $stripe->sentTo('POST', '/v1/transfers');
        $this->assertSame('90250', (string) $sent['amount']);
        $this->assertSame('eur', $sent['currency']);
        $this->assertSame('acct_1Shop', $sent['destination']);
        $this->assertSame($order->reference, $sent['transfer_group']);

        $payment = $this->paymentFor($order);
        $this->assertSame(4750, $payment->platform_fee_minor);
        $this->assertSame('tr_1Sent', $payment->stripe_transfer_id);
        $this->assertNotNull($payment->transferred_at);
        $this->assertFalse($payment->isHeld());
    }

    /** Paying a shop twice is the worst thing this could do. */
    public function test_the_transfer_carries_an_idempotency_key_of_the_orders_own(): void
    {
        $this->verifiedShop();
        $order = $this->paidOrder(OrderStatus::Shipped);
        $stripe = $this->fakeStripe()->respond('POST', '/v1/transfers', ['id' => 'tr_1Sent', 'object' => 'transfer']);

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson("/api/v1/orders/{$order->reference}/completion")
            ->assertOk();

        $this->assertContains(
            'Idempotency-Key: order-transfer-'.$order->reference,
            $stripe->headersSentTo('POST', '/v1/transfers'),
        );
    }

    /**
     * A shop still finishing its Stripe verification keeps the sale. The money
     * stays on the platform, and `payments:settle` sends it when it can.
     */
    public function test_a_shop_that_cannot_receive_yet_is_left_alone_and_the_money_is_kept(): void
    {
        PayoutAccount::factory()->for($this->shop, 'seller')->create(['stripe_account_id' => 'acct_1Waiting']);
        $order = $this->paidOrder(OrderStatus::Shipped);
        $stripe = $this->fakeStripe();

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson("/api/v1/orders/{$order->reference}/completion")
            ->assertOk();

        $stripe->assertNothingSent();

        $payment = $this->paymentFor($order);
        $this->assertNull($payment->transferred_at);
        $this->assertTrue($payment->isHeld());

        // The order completed regardless: the money is a separate question.
        $this->assertSame(OrderStatus::Completed, $order->refresh()->status);
    }

    // --- Cancelling gives it back --------------------------------------------

    public function test_cancelling_a_paid_order_refunds_it_in_full(): void
    {
        $order = $this->paidOrder(OrderStatus::Pending);
        $stripe = $this->fakeStripe()->respond('POST', '/v1/refunds', ['id' => 're_1Back', 'object' => 'refund']);

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson("/api/v1/orders/{$order->reference}/cancellation")
            ->assertOk();

        $sent = $stripe->sentTo('POST', '/v1/refunds');
        $this->assertSame($this->paymentFor($order)->stripe_payment_intent_id, $sent['payment_intent']);

        // No amount: Stripe refunds the intent in full when none is given, which
        // is one fewer figure to get wrong.
        $this->assertArrayNotHasKey('amount', $sent);

        $payment = $this->paymentFor($order);
        $this->assertSame('re_1Back', $payment->stripe_refund_id);
        $this->assertNotNull($payment->refunded_at);

        // The charge still succeeded: a refund is a second event, not a
        // correction of the first.
        $this->assertSame('succeeded', $payment->status->value);
    }

    /**
     * The uncomfortable one, and deliberate: a shop may call off an order it
     * has already sent, and the buyer is made whole (ADR 0041).
     */
    public function test_a_shop_cancelling_after_shipping_refunds_the_buyer(): void
    {
        $order = $this->paidOrder(OrderStatus::Shipped);
        $this->fakeStripe()->respond('POST', '/v1/refunds', ['id' => 're_1Back', 'object' => 'refund']);

        $this->actingAs($this->shopOwner)
            ->fromFrontend()
            ->postJson("/api/v1/seller/orders/{$order->reference}/cancellation", [
                'reason' => 'The parcel never reached the courier.',
            ])
            ->assertOk();

        $this->assertNotNull($this->paymentFor($order)->refunded_at);
    }

    public function test_an_unpaid_order_is_cancelled_without_asking_stripe_for_anything(): void
    {
        $order = Order::factory()->for($this->buyer)->for($this->shop)->create([
            'currency' => Currency::EUR,
            'total_minor' => 95000,
        ]);

        $stripe = $this->fakeStripe();

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson("/api/v1/orders/{$order->reference}/cancellation")
            ->assertOk();

        $stripe->assertNothingSent();
    }

    // --- Pulling it back again (ADR 0061) ------------------------------------

    /**
     * The thing ADR 0041 deliberately did not build.
     *
     * In full, with no amount sent - Stripe reverses the whole transfer, which
     * is one fewer figure this can get wrong, exactly as the refund argues.
     */
    public function test_a_transfer_can_be_pulled_back_off_the_shop(): void
    {
        $order = $this->transferredOrder();
        $stripe = $this->fakeStripe()->respond(
            'POST',
            '/v1/transfers/tr_1Sent/reversals',
            ['id' => 'trr_1Back', 'object' => 'transfer_reversal'],
        );

        $this->reverse($order);

        $sent = $stripe->sentTo('POST', '/v1/transfers/tr_1Sent/reversals');
        $this->assertArrayNotHasKey('amount', $sent);
        $this->assertSame($order->reference, $sent['metadata']['order_reference']);

        $this->assertContains(
            'Idempotency-Key: order-reversal-'.$order->reference,
            $stripe->headersSentTo('POST', '/v1/transfers/tr_1Sent/reversals'),
            'Reversing twice would take from a shop money it never received.',
        );

        $this->assertNotNull($this->paymentFor($order)->reversed_at);
    }

    /**
     * **A reversal records a second event; it does not undo the first.**
     *
     * The transfer happened and the marketplace kept its fee, and both stay
     * true. Clearing them would be the erasure ADR 0060 exists to stop - and
     * `payments_transfer_is_whole` ties all three, so nulling one means nulling
     * the lot.
     */
    public function test_reversing_leaves_the_transfer_on_the_record(): void
    {
        $order = $this->transferredOrder();
        $this->fakeStripe()->respond(
            'POST',
            '/v1/transfers/tr_1Sent/reversals',
            ['id' => 'trr_1Back', 'object' => 'transfer_reversal'],
        );

        $this->reverse($order);

        $payment = $this->paymentFor($order);

        $this->assertNotNull($payment->transferred_at);
        $this->assertSame('tr_1Sent', $payment->stripe_transfer_id);
        $this->assertSame(4750, $payment->platform_fee_minor);
        $this->assertTrue($payment->isReversed());

        // Not held: the money is on the platform but owed to the buyer, which
        // is not the same as waiting on an outcome.
        $this->assertFalse($payment->isHeld());
        $this->assertTrue($payment->canBeRefunded());
    }

    /**
     * The whole point of the reversal, end to end: a dispute decided for the
     * buyer after the shop has already been paid.
     *
     * **The order stays completed.** `orders_timeline_check` refuses
     * `cancelled` while `completed_at` is set, and clearing that date would
     * erase that the buyer confirmed. It did complete; a later decision moved
     * the money back.
     */
    public function test_a_dispute_after_completion_reverses_and_refunds(): void
    {
        $order = $this->transferredOrder();
        $dispute = Dispute::factory()->for($order)->create();
        $staff = User::factory()->staff()->create();

        $stripe = $this->fakeStripe()
            ->respond('POST', '/v1/transfers/tr_1Sent/reversals', ['id' => 'trr_1Back', 'object' => 'transfer_reversal'])
            ->respond('POST', '/v1/refunds', ['id' => 're_1Back', 'object' => 'refund']);

        $this->actingAs($staff)
            ->fromFrontend()
            ->postJson("/api/v1/admin/disputes/{$dispute->id}/resolution", [
                'resolution' => 'refunded',
                'note' => 'It was the wrong lens, and the photographs show it.',
            ])
            ->assertOk();

        $this->assertSame(1, $stripe->timesSentTo('POST', '/v1/transfers/tr_1Sent/reversals'));
        $this->assertSame(1, $stripe->timesSentTo('POST', '/v1/refunds'));

        $payment = $this->paymentFor($order);
        $this->assertNotNull($payment->reversed_at);
        $this->assertNotNull($payment->refunded_at);

        // Still completed, and still recording who completed it.
        $this->assertSame(OrderStatus::Completed, $order->refresh()->status);
    }

    /**
     * Decided for the shop after it has already been paid: nothing moves.
     *
     * `CompleteOrder` would throw on an order that is already complete, so this
     * is a case rather than a fall-through.
     */
    public function test_a_dispute_released_after_completion_moves_no_money(): void
    {
        $order = $this->transferredOrder();
        $dispute = Dispute::factory()->for($order)->create();
        $staff = User::factory()->staff()->create();

        $stripe = $this->fakeStripe();

        $this->actingAs($staff)
            ->fromFrontend()
            ->postJson("/api/v1/admin/disputes/{$dispute->id}/resolution", [
                'resolution' => 'released',
                'note' => 'The tracking shows it was delivered and signed for.',
            ])
            ->assertOk();

        $stripe->assertNothingSent();
        $this->assertSame(OrderStatus::Completed, $order->refresh()->status);
    }

    // --- What was left behind ------------------------------------------------

    public function test_settling_sends_money_that_did_not_move_when_the_order_finished(): void
    {
        $this->verifiedShop();

        $completed = $this->paidOrder(OrderStatus::Completed);
        $cancelled = $this->paidOrder(OrderStatus::Cancelled);

        $this->fakeStripe()
            ->respond('POST', '/v1/transfers', ['id' => 'tr_1Late', 'object' => 'transfer'])
            ->respond('POST', '/v1/refunds', ['id' => 're_1Late', 'object' => 'refund']);

        $this->console('payments:settle')
            ->expectsOutputToContain('Transferred 1, refunded 1, waiting 0, failed 0.')
            ->assertSuccessful();

        $this->assertNotNull($this->paymentFor($completed)->transferred_at);
        $this->assertNotNull($this->paymentFor($cancelled)->refunded_at);
    }

    /** A shop that cannot be paid yet is waiting, not failing. */
    public function test_settling_counts_a_shop_that_cannot_be_paid_as_waiting(): void
    {
        PayoutAccount::factory()->for($this->shop, 'seller')->create(['stripe_account_id' => 'acct_1Waiting']);
        $this->paidOrder(OrderStatus::Completed);

        $stripe = $this->fakeStripe();

        $this->console('payments:settle')
            ->expectsOutputToContain('Transferred 0, refunded 0, waiting 1, failed 0.')
            ->assertSuccessful();

        $stripe->assertNothingSent();
    }

    /**
     * **The failure the settle query was rewritten for** (ADR 0061).
     *
     * A reversal that succeeded and a refund that then failed leaves
     * `transferred_at` set, `reversed_at` set and `refunded_at` null. The old
     * query looked for payments with no transfer at all, so it could not see
     * this row - the buyer's money would have sat on the platform forever - and
     * the old code decided transfer-or-refund from the order's status, which
     * here is `completed`, so it would have sent the shop a second payment.
     */
    public function test_settling_finishes_a_reversal_that_was_interrupted(): void
    {
        $order = $this->transferredOrder();

        // Reversed, and the refund never made.
        $this->paymentFor($order)->forceFill([
            'stripe_transfer_reversal_id' => 'trr_1Half',
            'reversed_at' => now(),
        ])->save();

        Dispute::factory()->for($order)->resolved(DisputeResolution::Refunded, User::factory()->staff()->create())->create();

        $stripe = $this->fakeStripe()->respond('POST', '/v1/refunds', ['id' => 're_1Finished', 'object' => 'refund']);

        $this->console('payments:settle')
            ->expectsOutputToContain('Transferred 0, refunded 1, waiting 0, failed 0.')
            ->assertSuccessful();

        $this->assertNotNull($this->paymentFor($order)->refunded_at);

        // And emphatically not a second transfer to the shop.
        $this->assertSame(0, $stripe->timesSentTo('POST', '/v1/transfers'));
    }

    /** The other half of the pair: the reversal itself never went. */
    public function test_settling_finishes_a_reversal_that_never_started(): void
    {
        $order = $this->transferredOrder();

        Dispute::factory()->for($order)->resolved(DisputeResolution::Refunded, User::factory()->staff()->create())->create();

        $stripe = $this->fakeStripe()
            ->respond('POST', '/v1/transfers/tr_1Sent/reversals', ['id' => 'trr_1Late', 'object' => 'transfer_reversal'])
            ->respond('POST', '/v1/refunds', ['id' => 're_1Late', 'object' => 'refund']);

        $this->console('payments:settle')->assertSuccessful();

        $payment = $this->paymentFor($order);
        $this->assertNotNull($payment->reversed_at);
        $this->assertNotNull($payment->refunded_at);
        $this->assertSame(0, $stripe->timesSentTo('POST', '/v1/transfers'));
    }

    public function test_settling_twice_sends_nothing_the_second_time(): void
    {
        $this->verifiedShop();
        $this->paidOrder(OrderStatus::Completed);

        $stripe = $this->fakeStripe()->respond('POST', '/v1/transfers', ['id' => 'tr_1Once', 'object' => 'transfer']);

        $this->console('payments:settle')->assertSuccessful();
        $this->console('payments:settle')->assertSuccessful();

        $this->assertSame(1, $stripe->timesSentTo('POST', '/v1/transfers'));
    }

    /**
     * A completed order whose money has already reached the shop (ADR 0061).
     *
     * Written onto the payment rather than driven through a completion, so the
     * transfer id is known here and the reversal path it produces can be queued
     * on the fake - which matches on the exact path, id included.
     */
    private function transferredOrder(): Order
    {
        $order = $this->paidOrder(OrderStatus::Completed);

        $this->paymentFor($order)->forceFill([
            'platform_fee_minor' => 4750,
            'stripe_transfer_id' => 'tr_1Sent',
            'transferred_at' => now(),
        ])->save();

        return $order->refresh();
    }

    /** Pulls a transfer back through the action, as a decision would. */
    private function reverse(Order $order): void
    {
        app(ReverseTransfer::class)->handle($order);
    }

    /**
     * The order's payment, read back from the database and known to be there.
     *
     * Reaching through `$order->payment` in an assertion gives the analyser a
     * nullable relation to argue about at every call site. Asking once, and
     * failing the test where it is genuinely absent, says the same thing and
     * proves it - and re-reads the row, which is what these assertions want
     * after an action has written to it.
     */
    private function paymentFor(Order $order): Payment
    {
        $payment = Payment::query()->where('order_id', $order->id)->first();

        if (! $payment instanceof Payment) {
            $this->fail("Order {$order->reference} has no payment.");
        }

        return $payment;
    }

    /**
     * `$this->artisan()` with a type, as ExpireOrdersTest does.
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
