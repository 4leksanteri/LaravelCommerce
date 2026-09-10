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
 * "It has not arrived yet."
 *
 * **Auto-completion is not safe without this.** A shipped order completes on a
 * deadline whether or not anything turned up, and once payments exist that
 * releases money for a parcel nobody received. A courier that is a week late is
 * ordinary; a marketplace that declares delivery because of it is not.
 *
 * It is not a dispute. The buyer is not claiming anything went wrong, only that
 * it has not gone right yet, and the cheapest honest answer is more time.
 */
final class CompletionExtensionTest extends TestCase
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
        $this->variant = $this->publishedVariant($this->shop, stock: 20);
        $this->order = $this->placeOrder($this->buyer, $this->variant, 2);
    }

    public function test_a_buyer_can_push_back_the_deadline_on_a_late_parcel(): void
    {
        $this->ship();

        $before = $this->order->refresh()->auto_complete_at;
        $this->assertNotNull($before);

        $this->extend()
            ->assertOk()
            ->assertJsonPath('data.completion_extensions_left', 1)
            ->assertJsonPath('data.can_extend_completion', true);

        $after = $this->order->refresh()->auto_complete_at;

        $this->assertNotNull($after);
        $this->assertTrue($after->isSameDay($before->copy()->addDays(7)));
    }

    /**
     * Pushed from the deadline rather than from today, so asking early does not
     * buy less time than asking late. The alternative rewards leaving it until
     * the last moment.
     */
    public function test_the_extension_is_added_to_the_deadline_not_to_today(): void
    {
        $this->ship();

        $before = $this->order->refresh()->auto_complete_at;
        $this->assertNotNull($before);

        // Asked for on the day it shipped, with the full window still to run.
        $this->extend()->assertOk();

        $this->assertTrue(
            $this->order->refresh()->auto_complete_at?->isSameDay($before->copy()->addDays(7)) === true,
            'Asking early must not cost the buyer the time they had left.',
        );
    }

    public function test_it_holds_off_auto_completion(): void
    {
        $this->ship();

        // The original deadline, reached.
        Order::query()->whereKey($this->order->id)->update(['auto_complete_at' => now()->subMinute()]);

        $this->extend()->assertOk();

        $this->artisan('orders:auto-complete');

        $this->assertSame(OrderStatus::Shipped, $this->order->refresh()->status);
    }

    public function test_extensions_run_out(): void
    {
        $this->ship();

        $this->extend()->assertOk()->assertJsonPath('data.completion_extensions_left', 1);
        $this->extend()->assertOk()->assertJsonPath('data.completion_extensions_left', 0);

        $this->extend()
            ->assertStatus(409)
            ->assertJsonPath('status', 'shipped')
            ->assertJsonPath(
                'message',
                'This order has been extended as far as it can be, and will complete on its own.',
            );
    }

    public function test_the_buyer_is_told_before_they_try(): void
    {
        $this->ship();

        $this->extend()->assertOk();
        $this->extend()->assertOk();

        $this->actingAs($this->buyer)
            ->getJson("/api/v1/orders/{$this->order->reference}")
            ->assertOk()
            ->assertJsonPath('data.can_extend_completion', false)
            ->assertJsonPath('data.completion_extensions_left', 0);
    }

    public function test_there_is_nothing_to_extend_before_it_ships(): void
    {
        $this->extend()
            ->assertStatus(409)
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath(
                'message',
                'This order has not been shipped yet, so there is nothing to wait for.',
            );

        $this->actingAs($this->buyer)
            ->getJson("/api/v1/orders/{$this->order->reference}")
            ->assertOk()
            ->assertJsonPath('data.can_extend_completion', false)
            ->assertJsonPath('data.auto_complete_at', null);
    }

    public function test_a_completed_order_cannot_be_extended(): void
    {
        $this->ship();

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson("/api/v1/orders/{$this->order->reference}/completion")
            ->assertOk();

        $this->extend()->assertStatus(409)->assertJsonPath('status', 'completed');
    }

    /**
     * The deadline is the buyer's to move, not the seller's - it is the
     * seller's payout it holds up.
     */
    public function test_a_seller_cannot_extend_their_own_sale(): void
    {
        $this->ship();

        $this->actingAs($this->shopOwner)
            ->fromFrontend()
            ->postJson("/api/v1/orders/{$this->order->reference}/completion-extension")
            ->assertNotFound();
    }

    public function test_another_shopper_cannot_extend_it(): void
    {
        $this->ship();

        $this->actingAs(User::factory()->create())
            ->fromFrontend()
            ->postJson("/api/v1/orders/{$this->order->reference}/completion-extension")
            ->assertNotFound();
    }

    private function ship(): void
    {
        $this->actingAs($this->shopOwner)
            ->fromFrontend()
            ->postJson("/api/v1/seller/orders/{$this->order->reference}/acceptance")
            ->assertOk();

        $this->actingAs($this->shopOwner)
            ->fromFrontend()
            ->postJson("/api/v1/seller/orders/{$this->order->reference}/shipment")
            ->assertOk();
    }

    /** @return TestResponse<Response> */
    private function extend(): TestResponse
    {
        return $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson("/api/v1/orders/{$this->order->reference}/completion-extension");
    }
}
