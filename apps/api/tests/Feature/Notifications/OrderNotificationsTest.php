<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Actions\Orders\AutoCompleteShippedOrders;
use App\Actions\Orders\ExpireStaleOrders;
use App\Enums\OrderParty;
use App\Models\Address;
use App\Models\Order;
use App\Models\Seller;
use App\Models\User;
use App\Notifications\Orders\CompletionExtended;
use App\Notifications\Orders\OrderAccepted;
use App\Notifications\Orders\OrderCancelled;
use App\Notifications\Orders\OrderCompleted;
use App\Notifications\Orders\OrderReceived;
use App\Notifications\Orders\OrderShipped;
use App\Notifications\Orders\OrdersPlaced;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Orders\PlacesOrders;
use Tests\TestCase;

/**
 * Who is told what, as an order moves (ADR 0035).
 *
 * The rule under all of it: whoever did not act is told. The buyer who pressed
 * a button is looking at the result already; the other side is not.
 */
final class OrderNotificationsTest extends TestCase
{
    use PlacesOrders;
    use RefreshDatabase;

    private User $buyer;

    private User $shopOwner;

    private Seller $shop;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->buyer = User::factory()->create();
        $this->shopOwner = User::factory()->create();
        $this->shop = $this->approvedShop($this->shopOwner);
    }

    /**
     * One mail to the buyer for the whole checkout, because they pressed one
     * button - and one to each shop, about its own order only.
     */
    public function test_a_checkout_tells_the_buyer_once_and_each_shop_about_its_own_order(): void
    {
        $other = $this->approvedShop(User::factory()->create());
        $address = Address::factory()->for($this->buyer)->create();

        foreach ([$this->publishedVariant($this->shop), $this->publishedVariant($other)] as $variant) {
            $this->actingAs($this->buyer)
                ->fromFrontend()
                ->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => 1])
                ->assertOk();
        }

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson('/api/v1/checkout', ['address_id' => $address->id])
            ->assertCreated();

        Notification::assertSentToTimes($this->buyer, OrdersPlaced::class, 1);
        Notification::assertSentTo(
            $this->buyer,
            OrdersPlaced::class,
            static fn (OrdersPlaced $notification): bool => $notification->orders->count() === 2,
        );

        foreach ([$this->shop, $other] as $shop) {
            Notification::assertSentTo(
                $shop,
                OrderReceived::class,
                static fn (OrderReceived $notification): bool => $notification->order->seller_id === $shop->id,
            );
        }
    }

    public function test_the_shop_accepting_tells_the_buyer(): void
    {
        $order = $this->order();

        $this->asShop('acceptance', $order);

        Notification::assertSentTo($this->buyer, OrderAccepted::class);
    }

    public function test_the_shop_sending_it_tells_the_buyer_when_it_completes_on_its_own(): void
    {
        $order = $this->order();

        $this->asShop('acceptance', $order);
        $this->asShop('shipment', $order);

        Notification::assertSentTo(
            $this->buyer,
            OrderShipped::class,
            fn (OrderShipped $notification): bool => str_contains(
                implode(' ', $notification->toMail($this->buyer)->introLines),
                'it completes on its own on',
            ),
        );
    }

    public function test_a_buyer_cancelling_tells_the_shop_and_not_the_buyer(): void
    {
        $order = $this->order();

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson("/api/v1/orders/{$order->reference}/cancellation")
            ->assertOk();

        Notification::assertSentTo(
            $this->shop,
            OrderCancelled::class,
            static fn (OrderCancelled $notification): bool => $notification->reader === OrderParty::Seller,
        );
        Notification::assertNotSentTo($this->buyer, OrderCancelled::class);
    }

    public function test_a_shop_cancelling_tells_the_buyer_why(): void
    {
        $order = $this->order();

        $this->asShop('cancellation', $order, ['reason' => 'The last one sold in the shop this morning.']);

        Notification::assertSentTo(
            $this->buyer,
            OrderCancelled::class,
            fn (OrderCancelled $notification): bool => in_array(
                'Their reason: The last one sold in the shop this morning.',
                $notification->toMail($this->buyer)->introLines,
                true,
            ),
        );
        Notification::assertNotSentTo($this->shop, OrderCancelled::class);
    }

    /**
     * The mail template renders Markdown, and a reason is typed by a shop and
     * read by somebody else. A link written into it arrives as text, not as
     * something to click.
     */
    public function test_a_reason_cannot_put_a_link_in_somebody_elses_inbox(): void
    {
        $order = $this->order();

        $this->asShop('cancellation', $order, ['reason' => '[Claim your refund](https://evil.test/refund)']);

        Notification::assertSentTo(
            $this->buyer,
            OrderCancelled::class,
            function (OrderCancelled $notification): bool {
                $html = (string) $notification->toMail($this->buyer)->render();

                $this->assertStringNotContainsString('href="https://evil.test/refund"', $html);
                $this->assertStringContainsString('Claim your refund', $html);

                return true;
            },
        );
    }

    /** Nobody chose it, so both sides hear it. */
    public function test_an_order_nobody_accepted_in_time_tells_both(): void
    {
        $this->order();

        // Both clocks pushed past now, so the order is stale whether or not it
        // was paid for (ADR 0042).
        app(ExpireStaleOrders::class)->handle(now()->addMinute(), now()->addMinute(), 100);

        Notification::assertSentTo(
            $this->buyer,
            OrderCancelled::class,
            static fn (OrderCancelled $notification): bool => $notification->reader === OrderParty::Buyer,
        );
        Notification::assertSentTo(
            $this->shop,
            OrderCancelled::class,
            static fn (OrderCancelled $notification): bool => $notification->reader === OrderParty::Seller,
        );
    }

    public function test_confirming_arrival_tells_the_shop_and_not_the_buyer(): void
    {
        $order = $this->sentOrder();

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson("/api/v1/orders/{$order->reference}/completion")
            ->assertOk();

        Notification::assertSentTo($this->shop, OrderCompleted::class);
        Notification::assertNotSentTo($this->buyer, OrderCompleted::class);
    }

    /**
     * The marketplace concluding a parcel arrived is exactly what a buyer needs
     * to hear about, so a deadline completing an order tells them as well.
     */
    public function test_a_deadline_completing_an_order_tells_both(): void
    {
        $this->sentOrder();

        app(AutoCompleteShippedOrders::class)->handle(now()->addDays(60), 100);

        Notification::assertSentTo($this->shop, OrderCompleted::class);
        Notification::assertSentTo(
            $this->buyer,
            OrderCompleted::class,
            fn (OrderCompleted $notification): bool => str_contains(
                implode(' ', $notification->toMail($this->buyer)->introLines),
                'completed on its own',
            ),
        );
    }

    public function test_asking_for_more_time_tells_the_shop(): void
    {
        $order = $this->sentOrder();

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson("/api/v1/orders/{$order->reference}/completion-extension")
            ->assertOk();

        Notification::assertSentTo($this->shop, CompletionExtended::class);
    }

    /** A refused transition changed nothing, so there is nothing to tell. */
    public function test_a_refused_step_tells_nobody(): void
    {
        $order = $this->order();

        $this->actingAs($this->shopOwner)
            ->fromFrontend()
            ->postJson("/api/v1/seller/orders/{$order->reference}/shipment")
            ->assertConflict();

        Notification::assertNotSentTo($this->buyer, OrderShipped::class);
    }

    /**
     * Mail about the shop goes to the address it gave to be contacted at, and
     * every link in it goes to the web application, never to this one.
     */
    public function test_a_shops_mail_goes_to_its_contact_address_and_links_to_the_site(): void
    {
        $order = $this->order();

        $this->assertSame($this->shop->contact_email, $this->shop->routeNotificationFor('mail'));

        Notification::assertSentTo(
            $this->shop,
            OrderReceived::class,
            function (OrderReceived $notification) use ($order): bool {
                $mail = $notification->toMail($this->shop);

                $this->assertSame("New order {$order->reference}", $mail->subject);
                // The shop's own page for the order (ADR 0036).
                $this->assertSame("http://localhost:3000/seller/orders/{$order->reference}", $mail->actionUrl);
                $this->assertContains('Total: '.$order->currency->format($order->total_minor), $mail->introLines);

                return true;
            },
        );
    }

    public function test_the_buyers_mail_links_to_their_order_on_the_site(): void
    {
        $order = $this->order();

        $this->asShop('acceptance', $order);

        Notification::assertSentTo(
            $this->buyer,
            OrderAccepted::class,
            fn (OrderAccepted $notification): bool => $notification->toMail($this->buyer)->actionUrl
                === "http://localhost:3000/account/orders/{$order->reference}",
        );
    }

    private function order(): Order
    {
        return $this->placeOrder($this->buyer, $this->publishedVariant($this->shop));
    }

    private function sentOrder(): Order
    {
        $order = $this->order();

        $this->asShop('acceptance', $order);
        $this->asShop('shipment', $order);

        return $order;
    }

    /**
     * @param  array<string, string>  $body
     */
    private function asShop(string $step, Order $order, array $body = []): void
    {
        $this->actingAs($this->shopOwner)
            ->fromFrontend()
            ->postJson("/api/v1/seller/orders/{$order->reference}/{$step}", $body)
            ->assertOk();
    }
}
