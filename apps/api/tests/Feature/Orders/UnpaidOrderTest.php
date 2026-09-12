<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Actions\Orders\AcceptOrder;
use App\Actions\Orders\ExpireStaleOrders;
use App\Enums\OrderStatus;
use App\Exceptions\OrderTransitionNotAllowedException;
use App\Models\ProductVariant;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What an order is before anybody has paid for it (ADR 0042).
 *
 * `pending` meant one thing until payments arrived and two afterwards: waiting
 * on a shop, and waiting on a card. These hold the difference - a shop sees
 * only the first, and the second is gone in minutes rather than days.
 */
final class UnpaidOrderTest extends TestCase
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

        $this->buyer = User::factory()->create();
        $this->shopOwner = User::factory()->create();
        $this->shop = $this->approvedShop($this->shopOwner);
        $this->variant = $this->publishedVariant($this->shop, stock: 20);
    }

    // --- What a shop sees ----------------------------------------------------

    public function test_a_shops_queue_leaves_out_an_order_nobody_has_paid_for(): void
    {
        $unpaid = $this->placeUnpaidOrder($this->buyer, $this->variant);
        $paid = $this->placeOrder($this->buyer, $this->variant);

        $this->actingAs($this->shopOwner)
            ->getJson('/api/v1/seller/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', $paid->reference)
            ->assertJsonPath('meta.total', 1);

        $this->assertSame(OrderStatus::Pending, $unpaid->refresh()->status);
    }

    /** To this shop it does not exist yet, so it answers the same as one that never did. */
    public function test_an_unpaid_orders_page_is_not_found_by_its_shop(): void
    {
        $unpaid = $this->placeUnpaidOrder($this->buyer, $this->variant);

        $this->actingAs($this->shopOwner)
            ->getJson("/api/v1/seller/orders/{$unpaid->reference}")
            ->assertNotFound();
    }

    /**
     * The belt to the queue's brace: a page left open while a card was
     * declined, or a client calling the endpoint directly.
     */
    public function test_accepting_an_unpaid_order_is_refused(): void
    {
        $unpaid = $this->placeUnpaidOrder($this->buyer, $this->variant);

        // The shop cannot even reach it through its own queue, so the refusal
        // is asserted where it lives: the action behind the endpoint.
        $accept = app(AcceptOrder::class);

        $this->expectException(OrderTransitionNotAllowedException::class);
        $this->expectExceptionMessage('Nobody has paid for this order yet.');

        $accept->handle($unpaid);
    }

    public function test_a_paid_order_can_be_accepted(): void
    {
        $paid = $this->placeOrder($this->buyer, $this->variant);

        $this->actingAs($this->shopOwner)
            ->fromFrontend()
            ->postJson("/api/v1/seller/orders/{$paid->reference}/acceptance")
            ->assertOk();

        $this->assertSame(OrderStatus::Accepted, $paid->refresh()->status);
    }

    // --- What the buyer sees -------------------------------------------------

    /** It is theirs, and it is where the card form lives. */
    public function test_the_buyer_sees_their_unpaid_order_throughout(): void
    {
        $unpaid = $this->placeUnpaidOrder($this->buyer, $this->variant);

        $this->actingAs($this->buyer)
            ->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonPath('data.0.reference', $unpaid->reference);

        $this->actingAs($this->buyer)
            ->getJson("/api/v1/orders/{$unpaid->reference}")
            ->assertOk();
    }

    // --- Two clocks ----------------------------------------------------------

    public function test_an_unpaid_order_expires_in_minutes_and_gives_its_stock_back(): void
    {
        $unpaid = $this->placeUnpaidOrder($this->buyer, $this->variant, 3);

        $this->assertSame(17, $this->variant->refresh()->stock);

        // Half an hour old: past the short clock, nowhere near the long one.
        app(ExpireStaleOrders::class)->handle(
            unpaidBefore: now()->subMinutes(30),
            paidBefore: now()->subHours(72),
            limit: 100,
        );

        $unpaid->refresh();
        $this->assertSame(OrderStatus::Pending, $unpaid->status, 'It is not yet stale.');

        $unpaid->forceFill(['created_at' => now()->subHour()])->save();

        app(ExpireStaleOrders::class)->handle(
            unpaidBefore: now()->subMinutes(30),
            paidBefore: now()->subHours(72),
            limit: 100,
        );

        $this->assertSame(OrderStatus::Cancelled, $unpaid->refresh()->status);
        $this->assertSame(20, $this->variant->refresh()->stock);
    }

    /** A paid order is waiting on a person, and keeps the days it always had. */
    public function test_a_paid_order_is_left_alone_by_the_short_clock(): void
    {
        $paid = $this->placeOrder($this->buyer, $this->variant, 3);

        $paid->forceFill(['created_at' => now()->subHour()])->save();

        app(ExpireStaleOrders::class)->handle(
            unpaidBefore: now()->subMinutes(30),
            paidBefore: now()->subHours(72),
            limit: 100,
        );

        $this->assertSame(OrderStatus::Pending, $paid->refresh()->status);
        $this->assertSame(17, $this->variant->refresh()->stock);
    }
}
