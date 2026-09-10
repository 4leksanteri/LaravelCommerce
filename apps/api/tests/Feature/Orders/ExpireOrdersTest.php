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
 * The scheduled command that gives back stock nobody is going to buy.
 *
 * Two things it has to be, because whatever fires it in production will do so
 * **at least** once and possibly twice (ADR 0013): idempotent, and bounded.
 * Both are asserted here rather than described.
 */
final class ExpireOrdersTest extends TestCase
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

    public function test_a_pending_order_past_the_window_is_cancelled_and_its_stock_returned(): void
    {
        $order = $this->placeOrder($this->buyer, $this->variant, 3);

        $this->assertSame(17, $this->variant->refresh()->stock);

        $this->age($order, hours: 80);

        $this->console('orders:expire')->assertExitCode(Command::SUCCESS);

        $order->refresh();

        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertNotNull($order->cancelled_at);
        $this->assertSame(20, $this->variant->refresh()->stock);
    }

    public function test_an_order_inside_the_window_is_left_alone(): void
    {
        $order = $this->placeOrder($this->buyer, $this->variant, 3);

        $this->age($order, hours: 71);

        $this->console('orders:expire')->assertExitCode(Command::SUCCESS);

        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
        $this->assertSame(17, $this->variant->refresh()->stock);
    }

    /**
     * The clock runs on the seller, and it stops when they answer. An accepted
     * order is somebody's commitment; expiring it would cancel work that may
     * already have started.
     */
    public function test_an_accepted_order_never_expires(): void
    {
        $order = $this->placeOrder($this->buyer, $this->variant, 3);

        $this->actingAs($this->shopOwner)
            ->fromFrontend()
            ->postJson("/api/v1/seller/orders/{$order->reference}/acceptance")
            ->assertOk();

        $this->age($order, hours: 500);

        $this->console('orders:expire')->assertExitCode(Command::SUCCESS);

        $this->assertSame(OrderStatus::Accepted, $order->refresh()->status);
        $this->assertSame(17, $this->variant->refresh()->stock);
    }

    public function test_an_already_cancelled_order_is_not_touched_again(): void
    {
        $order = $this->placeOrder($this->buyer, $this->variant, 3);

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson("/api/v1/orders/{$order->reference}/cancellation")
            ->assertOk();

        $this->assertSame(20, $this->variant->refresh()->stock);

        $this->age($order, hours: 500);

        $this->console('orders:expire')->assertExitCode(Command::SUCCESS);

        $this->assertSame(
            20,
            $this->variant->refresh()->stock,
            'Stock must not be returned twice.',
        );
    }

    /**
     * The property that matters most, because at-least-once delivery means a
     * second run over the same window is normal rather than exceptional.
     */
    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        $order = $this->placeOrder($this->buyer, $this->variant, 3);
        $this->age($order, hours: 80);

        $this->console('orders:expire')->assertExitCode(Command::SUCCESS);
        $this->console('orders:expire')->assertExitCode(Command::SUCCESS);

        $this->assertSame(20, $this->variant->refresh()->stock);
        $this->assertSame(1, Order::query()->where('status', OrderStatus::Cancelled)->count());
    }

    /**
     * A backlog must not produce a run that never ends. The rest are picked up
     * by the next one, oldest first.
     */
    public function test_a_run_is_bounded_by_the_limit(): void
    {
        foreach (range(1, 4) as $ignored) {
            $this->age($this->placeOrder($this->buyer, $this->variant, 1), hours: 80);
        }

        $this->assertSame(16, $this->variant->refresh()->stock);

        $this->console('orders:expire', ['--limit' => 2])->assertExitCode(Command::SUCCESS);

        $this->assertSame(2, Order::query()->where('status', OrderStatus::Cancelled)->count());
        $this->assertSame(18, $this->variant->refresh()->stock);

        $this->console('orders:expire', ['--limit' => 2])->assertExitCode(Command::SUCCESS);

        $this->assertSame(4, Order::query()->where('status', OrderStatus::Cancelled)->count());
        $this->assertSame(20, $this->variant->refresh()->stock);
    }

    /**
     * Overlapping runs are expected, not exceptional - so a run that finds the
     * lock held reports it and exits **zero**. A red mark for something normal
     * trains people to ignore red marks.
     */
    public function test_a_run_that_cannot_take_the_lock_does_nothing_and_succeeds(): void
    {
        $order = $this->placeOrder($this->buyer, $this->variant, 3);
        $this->age($order, hours: 80);

        $held = Cache::lock('orders:expire', 600);
        $this->assertTrue($held->get());

        try {
            $this->console('orders:expire')
                ->expectsOutputToContain('Another run holds the lock.')
                ->assertExitCode(Command::SUCCESS);
        } finally {
            $held->release();
        }

        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
        $this->assertSame(17, $this->variant->refresh()->stock);
    }

    public function test_the_lock_is_released_so_the_next_run_can_take_it(): void
    {
        $this->console('orders:expire')->assertExitCode(Command::SUCCESS);

        $lock = Cache::lock('orders:expire', 600);

        $this->assertTrue($lock->get(), 'A finished run must not leave the lock held.');

        $lock->release();
    }

    public function test_an_empty_run_succeeds(): void
    {
        $this->console('orders:expire')
            ->expectsOutputToContain('Expired 0')
            ->assertExitCode(Command::SUCCESS);
    }

    /**
     * `$this->artisan()` with a type.
     *
     * It is declared `PendingCommand|int` - an int when console output is not
     * being mocked, which it is here and everywhere in this suite. The branch
     * proves that to the analyser rather than promising it, and fails loudly
     * if a test ever turns the mocking off and then tries to assert on output
     * that is no longer captured.
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

    /**
     * `created_at` is what the window is measured against, and Eloquent will
     * not let it be set by a normal save.
     */
    private function age(Order $order, int $hours): void
    {
        Order::query()
            ->whereKey($order->id)
            ->update(['created_at' => now()->subHours($hours)]);
    }
}
