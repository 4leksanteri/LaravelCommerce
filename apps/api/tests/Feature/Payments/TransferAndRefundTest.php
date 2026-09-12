<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Enums\Currency;
use App\Enums\OrderActor;
use App\Enums\OrderStatus;
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
