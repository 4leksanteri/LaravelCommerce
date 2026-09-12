<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Enums\Currency;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Seller;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Orders\PlacesOrders;
use Tests\TestCase;

/**
 * What each side is told about the money (ADR 0043).
 *
 * Escrow has worked since ADR 0041 and said so to nobody: a buyer's order read
 * the same whether they had paid for it or not, and a shop had no way to see
 * that its share had been sent. These are the fields that answer those two
 * questions, and the list behind the payouts page.
 */
final class PaymentSurfacesTest extends TestCase
{
    use PlacesOrders;
    use RefreshDatabase;

    private User $buyer;

    private User $shopOwner;

    private Seller $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buyer = User::factory()->create();
        $this->shopOwner = User::factory()->create();
        $this->shop = $this->approvedShop($this->shopOwner);

        /*
         * Pinned rather than read from the environment, as TransferAndRefundTest
         * pins it: what a deployment happens to charge is not what these
         * assertions are about.
         */
        config(['payments.platform_fee_bps' => 500]);
    }

    // --- What the buyer is told ----------------------------------------------

    public function test_a_buyer_sees_that_their_order_was_paid(): void
    {
        $order = $this->placeOrder($this->buyer, $this->publishedVariant($this->shop));

        $body = $this->actingAs($this->buyer)
            ->getJson("/api/v1/orders/{$order->reference}")
            ->assertOk()
            ->assertJsonPath('data.payment_status', PaymentStatus::Succeeded->value)
            ->assertJsonPath('data.can_pay', false)
            ->assertJsonPath('data.refunded_at', null)
            ->json();

        $this->assertIsArray($body);
        $this->assertNotNull($body['data']['paid_at'], 'A paid order should say when.');
    }

    /**
     * The buyer's view is the one place an unpaid order stays visible, because
     * it is where paying for it starts (ADR 0042). `can_pay` is what a page
     * draws that link from.
     */
    public function test_a_buyer_is_told_their_unpaid_order_still_needs_paying(): void
    {
        $order = $this->placeUnpaidOrder($this->buyer, $this->publishedVariant($this->shop));

        $this->actingAs($this->buyer)
            ->getJson("/api/v1/orders/{$order->reference}")
            ->assertOk()
            ->assertJsonPath('data.can_pay', true)
            ->assertJsonPath('data.paid_at', null)

            // No intent was opened: Stripe is faked and answers nothing here.
            ->assertJsonPath('data.payment_status', null);
    }

    public function test_a_refunded_order_says_so_to_its_buyer(): void
    {
        $order = Order::factory()
            ->for($this->buyer)
            ->for($this->shop)
            ->cancelled()
            ->create(['currency' => Currency::EUR, 'total_minor' => 4000]);

        Payment::factory()->forOrder($order)->refunded()->create();

        $body = $this->actingAs($this->buyer)
            ->getJson("/api/v1/orders/{$order->reference}")
            ->assertOk()

            // The charge still succeeded. A refund is a second event, not a
            // correction of the first (ADR 0041).
            ->assertJsonPath('data.payment_status', PaymentStatus::Succeeded->value)
            ->assertJsonPath('data.can_pay', false)
            ->json();

        $this->assertIsArray($body);
        $this->assertNotNull($body['data']['refunded_at'], 'A refunded order should say when.');
    }

    // --- What the shop is told -----------------------------------------------

    public function test_a_shop_sees_what_it_will_receive_before_the_money_moves(): void
    {
        // 650 a unit, two of them: 1300, of which five per cent is 65.
        $order = $this->placeOrder($this->buyer, $this->publishedVariant($this->shop));

        $this->actingAs($this->shopOwner)
            ->getJson("/api/v1/seller/orders/{$order->reference}")
            ->assertOk()
            ->assertJsonPath('data.total_minor', 1300)
            ->assertJsonPath('data.platform_fee_minor', 65)
            ->assertJsonPath('data.payout_amount_minor', 1235)

            // Nothing has moved yet: the buyer has not confirmed the parcel.
            ->assertJsonPath('data.transferred_at', null)
            ->assertJsonPath('data.refunded_at', null);
    }

    /**
     * Once it has moved, the recorded fee is what is shown - not what today's
     * rate would take. `Payment::platformFeeMinor()` is where that rule lives.
     */
    public function test_a_shop_sees_the_fee_that_was_actually_taken(): void
    {
        $order = $this->transferredOrder(95000, 4750);

        config(['payments.platform_fee_bps' => 2500]);

        $body = $this->actingAs($this->shopOwner)
            ->getJson("/api/v1/seller/orders/{$order->reference}")
            ->assertOk()
            ->assertJsonPath('data.platform_fee_minor', 4750)
            ->assertJsonPath('data.payout_amount_minor', 90250)
            ->json();

        $this->assertIsArray($body);
        $this->assertNotNull($body['data']['transferred_at'], 'A transferred order should say when.');
    }

    // --- What the payouts page lists -----------------------------------------

    public function test_the_payouts_list_shows_what_this_shop_has_been_paid(): void
    {
        $older = $this->transferredOrder(10000, 500, at: now()->subDays(2));
        $newer = $this->transferredOrder(20000, 1000, at: now()->subDay());

        // Paid, and still held: not a payout, and not in this list.
        $held = Order::factory()->for($this->buyer)->for($this->shop)->create([
            'currency' => Currency::EUR,
            'total_minor' => 7000,
        ]);
        Payment::factory()->forOrder($held)->paid()->create();

        $this->actingAs($this->shopOwner)
            ->getJson('/api/v1/seller/payout-account/transfers')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2)

            // Newest first, as every list here is.
            ->assertJsonPath('data.0.order_reference', $newer->reference)
            ->assertJsonPath('data.0.charged_minor', 20000)
            ->assertJsonPath('data.0.platform_fee_minor', 1000)
            ->assertJsonPath('data.0.amount_minor', 19000)
            ->assertJsonPath('data.0.currency', Currency::EUR->value)
            ->assertJsonPath('data.1.order_reference', $older->reference);
    }

    public function test_another_shops_payouts_are_not_in_it(): void
    {
        $otherShop = $this->approvedShop(User::factory()->create());

        $theirs = Order::factory()->for($this->buyer)->for($otherShop)->completed()->create([
            'currency' => Currency::EUR,
            'total_minor' => 50000,
        ]);
        Payment::factory()->forOrder($theirs)->transferred(2500)->create();

        $this->actingAs($this->shopOwner)
            ->getJson('/api/v1/seller/payout-account/transfers')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    /** Behind `seller`, like everything else about getting paid. */
    public function test_the_payouts_list_needs_a_shop(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/seller/payout-account/transfers')
            ->assertForbidden();
    }

    /**
     * A completed order whose money reached the shop.
     *
     * The factory's `completed()` state sets the timestamps that status
     * requires: `orders_timeline_check` refuses a completed order with no
     * shipping date.
     */
    private function transferredOrder(int $totalMinor, int $feeMinor, ?CarbonInterface $at = null): Order
    {
        $order = Order::factory()
            ->for($this->buyer)
            ->for($this->shop)
            ->completed()
            ->create(['currency' => Currency::EUR, 'total_minor' => $totalMinor]);

        $payment = Payment::factory()->forOrder($order)->transferred($feeMinor);

        if ($at instanceof CarbonInterface) {
            $payment->create(['transferred_at' => $at]);

            return $order->refresh();
        }

        $payment->create();

        return $order->refresh();
    }
}
