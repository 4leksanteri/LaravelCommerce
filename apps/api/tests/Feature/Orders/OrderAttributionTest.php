<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Actions\Orders\AutoCompleteShippedOrders;
use App\Actions\Orders\ExpireStaleOrders;
use App\Models\Order;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who ended an order, and why (ADR 0014, ADR 0035).
 *
 * Recorded by the actions that end orders, published on both sides of the
 * order, and held by the database: an actor never appears on an order that did
 * not end that way, and a reason is only ever a shop's.
 */
final class OrderAttributionTest extends TestCase
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

    public function test_a_buyer_cancelling_is_recorded_as_the_buyer_with_no_reason(): void
    {
        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson("/api/v1/orders/{$this->order->reference}/cancellation")
            ->assertOk()
            ->assertJsonPath('data.cancelled_by', 'buyer')
            ->assertJsonPath('data.cancellation_reason', null);
    }

    /** The buyer is owed a reason, and reads it on their side of the order. */
    public function test_a_shop_cancelling_has_to_say_why_and_the_buyer_sees_it(): void
    {
        $this->actingAs($this->shopOwner)
            ->fromFrontend()
            ->postJson("/api/v1/seller/orders/{$this->order->reference}/cancellation")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->actingAs($this->shopOwner)
            ->fromFrontend()
            ->postJson("/api/v1/seller/orders/{$this->order->reference}/cancellation", [
                'reason' => 'The last one sold in the shop this morning.',
            ])
            ->assertOk()
            ->assertJsonPath('data.cancelled_by', 'seller');

        $this->actingAs($this->buyer)
            ->getJson("/api/v1/orders/{$this->order->reference}")
            ->assertJsonPath('data.cancelled_by', 'seller')
            ->assertJsonPath('data.cancellation_reason', 'The last one sold in the shop this morning.');
    }

    public function test_an_order_nobody_accepted_in_time_is_recorded_as_the_deadlines(): void
    {
        app(ExpireStaleOrders::class)->handle(now()->addMinute(), 100);

        $this->assertSame('deadline', $this->order->refresh()->cancelled_by?->value);
    }

    public function test_a_buyer_confirming_arrival_is_recorded_as_the_buyer(): void
    {
        $this->send();

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson("/api/v1/orders/{$this->order->reference}/completion")
            ->assertOk()
            ->assertJsonPath('data.completed_by', 'buyer');
    }

    public function test_an_order_completed_by_its_deadline_says_so(): void
    {
        $this->send();

        app(AutoCompleteShippedOrders::class)->handle(now()->addDays(60), 100);

        $this->assertSame('deadline', $this->order->refresh()->completed_by?->value);
    }

    /** A reason is the shop's to give, and the database refuses any other. */
    public function test_the_database_refuses_a_reason_on_a_buyers_cancellation(): void
    {
        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson("/api/v1/orders/{$this->order->reference}/cancellation")
            ->assertOk();

        $this->expectException(QueryException::class);

        $this->order->refresh()->forceFill(['cancellation_reason' => 'Changed my mind.'])->save();
    }

    /** A shop never completes an order (ADR 0012), and cannot be recorded as having done so. */
    public function test_the_database_refuses_a_shop_as_the_one_who_completed(): void
    {
        $this->send();

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson("/api/v1/orders/{$this->order->reference}/completion")
            ->assertOk();

        $this->expectException(QueryException::class);

        $this->order->refresh()->forceFill(['completed_by' => 'seller'])->save();
    }

    private function send(): void
    {
        foreach (['acceptance', 'shipment'] as $step) {
            $this->actingAs($this->shopOwner)
                ->fromFrontend()
                ->postJson("/api/v1/seller/orders/{$this->order->reference}/{$step}")
                ->assertOk();
        }
    }
}
