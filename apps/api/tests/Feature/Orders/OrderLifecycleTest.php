<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * The state machine.
 *
 * ```text
 * Pending ──accept──▶ Accepted ──ship──▶ Shipped ──confirm──▶ Completed
 * ```
 *
 * Every step forward is somebody's decision, and every step out of order is a
 * **409** rather than a 403: the caller is a party to the order and entitled to
 * act on it, and what is in the way is where it has got to (ADR 0008).
 */
final class OrderLifecycleTest extends TestCase
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
        $this->variant = $this->publishedVariant($this->shop);
        $this->order = $this->placeOrder($this->buyer, $this->variant);
    }

    public function test_an_order_starts_pending_and_awaits_the_seller(): void
    {
        $this->buyerSees()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.can_cancel', true)
            ->assertJsonPath('data.can_complete', false)
            ->assertJsonPath('data.accepted_at', null);

        $this->sellerSees()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.can_accept', true)
            ->assertJsonPath('data.can_ship', false)
            ->assertJsonPath('data.can_cancel', true);
    }

    public function test_the_whole_way_through(): void
    {
        $this->accept()->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.can_accept', false)
            ->assertJsonPath('data.can_ship', true);

        $this->assertNotNull($this->order->refresh()->accepted_at);

        $this->ship()->assertOk()
            ->assertJsonPath('data.status', 'shipped')
            ->assertJsonPath('data.can_ship', false)
            ->assertJsonPath('data.can_cancel', false);

        // Only now can the buyer confirm, and only the buyer can.
        $this->buyerSees()->assertJsonPath('data.can_complete', true);

        $this->complete()->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.can_complete', false)
            ->assertJsonPath('data.can_cancel', false);

        $order = $this->order->refresh();

        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertNotNull($order->accepted_at);
        $this->assertNotNull($order->shipped_at);
        $this->assertNotNull($order->completed_at);
        $this->assertNull($order->cancelled_at);
    }

    // --- Out of order ---------------------------------------------------------

    public function test_an_order_cannot_be_shipped_before_it_is_accepted(): void
    {
        $this->ship()
            ->assertStatus(409)
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('message', 'Accept this order before shipping it.');

        $this->assertSame(OrderStatus::Pending, $this->order->refresh()->status);
    }

    public function test_an_order_cannot_be_accepted_twice(): void
    {
        $this->accept()->assertOk();

        $this->accept()
            ->assertStatus(409)
            ->assertJsonPath('status', 'accepted');
    }

    public function test_an_order_cannot_be_completed_before_it_ships(): void
    {
        $this->complete()
            ->assertStatus(409)
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('message', 'This order has not been shipped yet.');

        $this->accept()->assertOk();

        $this->complete()
            ->assertStatus(409)
            ->assertJsonPath('status', 'accepted');
    }

    public function test_a_cancelled_order_goes_nowhere(): void
    {
        $this->buyerCancel()->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->accept()->assertStatus(409)->assertJsonPath('status', 'cancelled');
        $this->ship()->assertStatus(409)->assertJsonPath('status', 'cancelled');
        $this->complete()->assertStatus(409)->assertJsonPath('status', 'cancelled');
    }

    public function test_a_completed_order_goes_nowhere(): void
    {
        $this->accept()->assertOk();
        $this->ship()->assertOk();
        $this->complete()->assertOk();

        $this->complete()->assertStatus(409)->assertJsonPath('status', 'completed');
        $this->buyerCancel()->assertStatus(409)->assertJsonPath('status', 'completed');
        $this->sellerCancel()->assertStatus(409)->assertJsonPath('status', 'completed');
    }

    // --- Who may --------------------------------------------------------------

    /**
     * The rule with the most riding on it. Completion is what will release a
     * payout, so a seller who could complete their own order could declare
     * their own money releasable.
     *
     * There is no seller completion endpoint, and the buyer's one resolves
     * through `$user->orders()` - so a seller asking to complete their own sale
     * is asking for something they did not buy.
     */
    public function test_a_seller_cannot_complete_their_own_sale(): void
    {
        $this->accept()->assertOk();
        $this->ship()->assertOk();

        $this->actingAs($this->shopOwner)
            ->fromFrontend()
            ->postJson("/api/v1/orders/{$this->order->reference}/completion")
            ->assertNotFound();

        $this->assertSame(OrderStatus::Shipped, $this->order->refresh()->status);
    }

    public function test_a_buyer_cannot_accept_or_ship_their_own_order(): void
    {
        // No seller profile at all, so the `seller` middleware refuses.
        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson("/api/v1/seller/orders/{$this->order->reference}/acceptance")
            ->assertForbidden();

        $this->assertSame(OrderStatus::Pending, $this->order->refresh()->status);
    }

    public function test_another_shop_cannot_touch_this_order(): void
    {
        $stranger = User::factory()->create();
        $this->approvedShop($stranger);

        $this->actingAs($stranger)
            ->fromFrontend()
            ->postJson("/api/v1/seller/orders/{$this->order->reference}/acceptance")
            ->assertNotFound();

        $this->assertSame(OrderStatus::Pending, $this->order->refresh()->status);
    }

    public function test_transitions_require_a_signed_in_caller(): void
    {
        // `setUp` placed an order, and `actingAs` leaves the buyer set on this
        // test instance's guard - so without this the requests below are the
        // buyer's and cancelling succeeds. The same call LogoutController
        // makes, for the same reason.
        Auth::forgetGuards();

        $this->fromFrontend()
            ->postJson("/api/v1/orders/{$this->order->reference}/cancellation")
            ->assertUnauthorized();

        $this->fromFrontend()
            ->postJson("/api/v1/seller/orders/{$this->order->reference}/acceptance")
            ->assertUnauthorized();
    }

    // --- Helpers --------------------------------------------------------------

    /** @return TestResponse<Response> */
    private function accept(): TestResponse
    {
        return $this->actingAs($this->shopOwner)->fromFrontend()
            ->postJson("/api/v1/seller/orders/{$this->order->reference}/acceptance");
    }

    /** @return TestResponse<Response> */
    private function ship(): TestResponse
    {
        return $this->actingAs($this->shopOwner)->fromFrontend()
            ->postJson("/api/v1/seller/orders/{$this->order->reference}/shipment");
    }

    /** @return TestResponse<Response> */
    private function sellerCancel(): TestResponse
    {
        return $this->actingAs($this->shopOwner)->fromFrontend()
            ->postJson("/api/v1/seller/orders/{$this->order->reference}/cancellation");
    }

    /** @return TestResponse<Response> */
    private function complete(): TestResponse
    {
        return $this->actingAs($this->buyer)->fromFrontend()
            ->postJson("/api/v1/orders/{$this->order->reference}/completion");
    }

    /** @return TestResponse<Response> */
    private function buyerCancel(): TestResponse
    {
        return $this->actingAs($this->buyer)->fromFrontend()
            ->postJson("/api/v1/orders/{$this->order->reference}/cancellation");
    }

    /** @return TestResponse<Response> */
    private function buyerSees(): TestResponse
    {
        return $this->actingAs($this->buyer)
            ->getJson("/api/v1/orders/{$this->order->reference}")
            ->assertOk();
    }

    /** @return TestResponse<Response> */
    private function sellerSees(): TestResponse
    {
        return $this->actingAs($this->shopOwner)
            ->getJson("/api/v1/seller/orders/{$this->order->reference}")
            ->assertOk();
    }
}
