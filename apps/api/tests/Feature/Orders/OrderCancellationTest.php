<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Who may call an order off, when, and what happens to the stock.
 *
 * The asymmetry is the interesting part: **a buyer may cancel only while nobody
 * has committed.** Once a seller has accepted they may have set stock aside or
 * started work, and it becomes theirs alone to call off - right up until it
 * ships.
 *
 * And cancelling gives the stock back. Checkout takes it at placement
 * (ADR 0011), so a cancellation that did not return it would be inventory
 * quietly deleted from a shop.
 */
final class OrderCancellationTest extends TestCase
{
    use PlacesOrders;
    use RefreshDatabase;

    private User $buyer;

    private User $shopOwner;

    private Seller $shop;

    private ProductVariant $variant;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buyer = User::factory()->create();
        $this->shopOwner = User::factory()->create();
        $this->shop = $this->approvedShop($this->shopOwner);
        $this->variant = $this->publishedVariant($this->shop, stock: 10);

        // Two of ten, so eight remain once checkout has taken them.
        $this->order = $this->placeOrder($this->buyer, $this->variant, 2);

        $this->assertSame(8, $this->variant->refresh()->stock);
    }

    public function test_a_buyer_can_cancel_while_nobody_has_committed(): void
    {
        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson("/api/v1/orders/{$this->order->reference}/cancellation")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.can_cancel', false);

        $this->assertNotNull($this->order->refresh()->cancelled_at);
    }

    /**
     * The first thing in the application that puts stock back.
     */
    public function test_cancelling_returns_exactly_what_checkout_took(): void
    {
        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson("/api/v1/orders/{$this->order->reference}/cancellation")
            ->assertOk();

        $this->assertSame(10, $this->variant->refresh()->stock);
    }

    public function test_a_buyer_cannot_cancel_once_the_seller_has_accepted(): void
    {
        $this->accept();

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson("/api/v1/orders/{$this->order->reference}/cancellation")
            ->assertStatus(409)
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath(
                'message',
                'The seller has already accepted this order, so only they can cancel it now.',
            );

        $this->assertSame(OrderStatus::Accepted, $this->order->refresh()->status);
        $this->assertSame(8, $this->variant->refresh()->stock, 'A refused cancellation returns nothing.');
    }

    public function test_the_buyer_is_told_they_cannot_cancel_before_they_try(): void
    {
        $this->accept();

        $this->actingAs($this->buyer)
            ->getJson("/api/v1/orders/{$this->order->reference}")
            ->assertOk()
            ->assertJsonPath('data.can_cancel', false);
    }

    // --- The seller's side ----------------------------------------------------

    public function test_a_seller_can_cancel_a_pending_order(): void
    {
        $this->sellerCancel()->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(10, $this->variant->refresh()->stock);
    }

    /**
     * Later than the buyer may, because after acceptance the seller is the
     * party who would be let down by it.
     */
    public function test_a_seller_can_still_cancel_after_accepting(): void
    {
        $this->accept();

        $this->sellerCancel()
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.can_cancel', false);

        $this->assertSame(10, $this->variant->refresh()->stock);
    }

    public function test_nothing_cancels_a_shipped_order(): void
    {
        $this->accept();

        $this->actingAs($this->shopOwner)
            ->fromFrontend()
            ->postJson("/api/v1/seller/orders/{$this->order->reference}/shipment")
            ->assertOk();

        $this->sellerCancel()
            ->assertStatus(409)
            ->assertJsonPath('message', 'This order has already been shipped, so it cannot be cancelled.');

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson("/api/v1/orders/{$this->order->reference}/cancellation")
            ->assertStatus(409);

        $this->assertSame(8, $this->variant->refresh()->stock);
    }

    public function test_cancelling_twice_does_not_return_the_stock_twice(): void
    {
        $this->sellerCancel()->assertOk();
        $this->sellerCancel()->assertStatus(409)->assertJsonPath('status', 'cancelled');

        $this->assertSame(10, $this->variant->refresh()->stock);
    }

    /**
     * A line whose variant has been removed has nowhere to return stock to, and
     * the order still cancels. The order's own figures are untouched by any of
     * it - a receipt does not move (ADR 0011).
     */
    public function test_an_order_whose_variant_was_removed_still_cancels(): void
    {
        $this->variant->delete();

        $this->sellerCancel()
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.total_minor', $this->order->total_minor)
            ->assertJsonPath('data.items.0.unit_price_minor', 650);
    }

    private function accept(): void
    {
        $this->actingAs($this->shopOwner)
            ->fromFrontend()
            ->postJson("/api/v1/seller/orders/{$this->order->reference}/acceptance")
            ->assertOk();
    }

    /** @return TestResponse<Response> */
    private function sellerCancel(): TestResponse
    {
        return $this->actingAs($this->shopOwner)
            ->fromFrontend()
            ->postJson("/api/v1/seller/orders/{$this->order->reference}/cancellation");
    }
}
