<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\PendingCommand;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Tests\TestCase;

/**
 * Completing a shipped order on behalf of a buyer who never confirmed.
 *
 * Buyers forget, and an order that stays `shipped` forever never releases a
 * payout. The clock is the only honest way to close it without letting sellers
 * complete their own sales (ADR 0014).
 */
final class AutoCompleteOrdersTest extends TestCase
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

    public function test_shipping_sets_the_deadline(): void
    {
        $this->ship();

        $order = $this->order->refresh();

        $this->assertNotNull($order->shipped_at);
        $this->assertNotNull($order->auto_complete_at);

        $this->assertTrue(
            $order->auto_complete_at->isSameDay($order->shipped_at->copy()->addDays(14)),
            'Fourteen days after posting, by default.',
        );
    }

    public function test_a_shipped_order_completes_once_its_deadline_passes(): void
    {
        $this->ship();
        $this->dueNow();

        $this->console('orders:auto-complete')->assertExitCode(Command::SUCCESS);

        $order = $this->order->refresh();

        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertNotNull($order->completed_at);
    }

    public function test_a_shipped_order_inside_its_deadline_is_left_alone(): void
    {
        $this->ship();

        $this->console('orders:auto-complete')
            ->expectsOutputToContain('Completed 0')
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame(OrderStatus::Shipped, $this->order->refresh()->status);
    }

    public function test_an_order_that_has_not_shipped_never_auto_completes(): void
    {
        $this->accept();

        $this->console('orders:auto-complete')->assertExitCode(Command::SUCCESS);

        $this->assertSame(OrderStatus::Accepted, $this->order->refresh()->status);
    }

    /**
     * Completing an order makes it no longer shipped, so a second run over the
     * same window finds nothing. At-least-once delivery makes that the normal
     * case rather than the exceptional one (ADR 0013).
     */
    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        $this->ship();
        $this->dueNow();

        $this->console('orders:auto-complete')->assertExitCode(Command::SUCCESS);
        $completedAt = $this->order->refresh()->completed_at;

        $this->console('orders:auto-complete')
            ->expectsOutputToContain('Completed 0')
            ->assertExitCode(Command::SUCCESS);

        $this->assertEquals($completedAt, $this->order->refresh()->completed_at);
    }

    /**
     * The seller's escape hatch beats the clock: a shipment they know went
     * missing is cancelled, and the clock then has nothing to complete.
     */
    public function test_a_cancelled_shipment_is_not_completed_by_the_clock(): void
    {
        $this->ship();
        $this->dueNow();

        $this->actingAs($this->shopOwner)
            ->fromFrontend()
            ->postJson("/api/v1/seller/orders/{$this->order->reference}/cancellation")
            ->assertOk();

        $this->console('orders:auto-complete')->assertExitCode(Command::SUCCESS);

        $this->assertSame(OrderStatus::Cancelled, $this->order->refresh()->status);
    }

    public function test_a_run_is_bounded_by_the_limit(): void
    {
        $second = $this->placeOrder($this->buyer, $this->variant, 1);

        foreach ([$this->order, $second] as $order) {
            $this->shipOrder($order);
            $this->dueNow($order);
        }

        $this->console('orders:auto-complete', ['--limit' => 1])->assertExitCode(Command::SUCCESS);

        $this->assertSame(1, Order::query()->where('status', OrderStatus::Completed)->count());

        $this->console('orders:auto-complete', ['--limit' => 1])->assertExitCode(Command::SUCCESS);

        $this->assertSame(2, Order::query()->where('status', OrderStatus::Completed)->count());
    }

    public function test_a_run_that_cannot_take_the_lock_does_nothing_and_succeeds(): void
    {
        $this->ship();
        $this->dueNow();

        $held = Cache::lock('orders:auto-complete', 600);
        $this->assertTrue($held->get());

        try {
            $this->console('orders:auto-complete')
                ->expectsOutputToContain('Another run holds the lock.')
                ->assertExitCode(Command::SUCCESS);
        } finally {
            $held->release();
        }

        $this->assertSame(OrderStatus::Shipped, $this->order->refresh()->status);
    }

    /**
     * The two scheduled commands take different locks, so one running does not
     * hold the other up.
     */
    public function test_it_does_not_share_a_lock_with_the_expiry_command(): void
    {
        $this->ship();
        $this->dueNow();

        $held = Cache::lock('orders:expire', 600);
        $this->assertTrue($held->get());

        try {
            $this->console('orders:auto-complete')->assertExitCode(Command::SUCCESS);
        } finally {
            $held->release();
        }

        $this->assertSame(OrderStatus::Completed, $this->order->refresh()->status);
    }

    // --- Helpers --------------------------------------------------------------

    private function accept(?Order $order = null): void
    {
        $reference = ($order ?? $this->order)->reference;

        $this->actingAs($this->shopOwner)
            ->fromFrontend()
            ->postJson("/api/v1/seller/orders/{$reference}/acceptance")
            ->assertOk();
    }

    private function ship(): void
    {
        $this->shipOrder($this->order);
    }

    private function shipOrder(Order $order): void
    {
        $this->accept($order);

        $this->actingAs($this->shopOwner)
            ->fromFrontend()
            ->postJson("/api/v1/seller/orders/{$order->reference}/shipment")
            ->assertOk();
    }

    /** Brings the deadline forward rather than moving the clock. */
    private function dueNow(?Order $order = null): void
    {
        Order::query()
            ->whereKey(($order ?? $this->order)->id)
            ->update(['auto_complete_at' => now()->subMinute()]);
    }

    /**
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
