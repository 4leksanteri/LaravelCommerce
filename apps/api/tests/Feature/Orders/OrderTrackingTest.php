<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Enums\Carrier;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Seller;
use App\Models\User;
use App\Notifications\Orders\OrderShipped;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Who is carrying the parcel, and where to follow it (ADR 0049).
 *
 * ADR 0019 listed shipping as one of four things the design export showed with
 * no domain behind it. This is the smallest useful version of it: the shop says
 * who has the parcel and under what number, and everything else follows from
 * those two nullable columns.
 *
 * **Both are optional and that is the design.** A seller posting an untracked
 * letter must still be able to mark an order sent - requiring a number would
 * either stop them or teach them to invent one, and an invented number is worse
 * than none.
 */
final class OrderTrackingTest extends TestCase
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

        Notification::fake();

        $this->buyer = User::factory()->create();
        $this->shopOwner = User::factory()->create();
        $this->shop = $this->approvedShop($this->shopOwner);
        $this->variant = $this->publishedVariant($this->shop, stock: 20);
    }

    public function test_a_shop_sends_it_with_a_carrier_and_a_number(): void
    {
        $order = $this->accepted();

        $this->ship($order, ['carrier' => 'posti', 'tracking_number' => 'JJFI1234567890'])
            ->assertOk()
            ->assertJsonPath('data.carrier', 'posti')
            ->assertJsonPath('data.tracking_number', 'JJFI1234567890');

        $order->refresh();

        $this->assertSame(Carrier::Posti, $order->carrier);
        $this->assertSame('JJFI1234567890', $order->tracking_number);
    }

    /**
     * The whole reason a carrier is a list rather than free text: a number the
     * buyer has to paste into a search engine is most of the way to useless.
     */
    public function test_the_buyer_is_given_somewhere_to_follow_it(): void
    {
        $order = $this->accepted();

        $this->ship($order, ['carrier' => 'posti', 'tracking_number' => 'JJFI1234567890']);

        $url = (string) $this->actingAs($this->buyer)
            ->getJson("/api/v1/orders/{$order->reference}")
            ->assertOk()
            ->json('data.tracking_url');

        $this->assertStringContainsString('posti.fi', $url);
        $this->assertStringContainsString('JJFI1234567890', $url);
    }

    /** A number in a URL is a string somebody typed. */
    public function test_a_tracking_number_is_encoded_into_the_link(): void
    {
        $order = $this->accepted();

        $this->ship($order, ['carrier' => 'ups', 'tracking_number' => 'A B/C']);

        $this->assertStringContainsString('A%20B%2FC', (string) $order->refresh()->trackingUrl());
    }

    public function test_an_untracked_parcel_is_still_sent(): void
    {
        $order = $this->accepted();

        $this->ship($order)
            ->assertOk()
            ->assertJsonPath('data.carrier', null)
            ->assertJsonPath('data.tracking_number', null)
            ->assertJsonPath('data.tracking_url', null);

        $this->assertNotNull($order->refresh()->shipped_at);
    }

    /**
     * A carrier alone would publish a link to a search for nothing, so it is
     * refused - beside the field, because what was sent is what is wrong.
     */
    public function test_a_carrier_without_a_number_is_refused(): void
    {
        $order = $this->accepted();

        $this->ship($order, ['carrier' => 'posti'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tracking_number');

        $this->assertNull($order->refresh()->shipped_at, 'Nothing was sent.');
    }

    /**
     * The other way round is allowed: somebody using a courier this
     * marketplace cannot link to still has a number worth quoting.
     */
    public function test_a_number_without_a_carrier_is_kept_and_shown_as_text(): void
    {
        $order = $this->accepted();

        $this->ship($order, ['tracking_number' => 'LOCAL-99'])
            ->assertOk()
            ->assertJsonPath('data.tracking_number', 'LOCAL-99')
            ->assertJsonPath('data.carrier', null)

            // Nowhere to send anybody, and the number still says something.
            ->assertJsonPath('data.tracking_url', null);
    }

    public function test_a_carrier_nobody_has_heard_of_is_refused(): void
    {
        $order = $this->accepted();

        $this->ship($order, ['carrier' => 'owls', 'tracking_number' => 'HEDWIG-1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('carrier');
    }

    /**
     * The mail is where "where is my parcel" is actually asked, so the link
     * goes in it rather than only on the page.
     */
    public function test_the_mail_carries_the_carrier_and_the_link(): void
    {
        $order = $this->accepted();

        $this->ship($order, ['carrier' => 'dhl', 'tracking_number' => 'JD01420000']);

        Notification::assertSentTo(
            $this->buyer,
            OrderShipped::class,
            function (OrderShipped $notification): bool {
                $mail = $notification->toMail($this->buyer);

                $this->assertStringContainsString('DHL, tracking number JD01420000.', implode(' ', $mail->introLines));
                $this->assertSame('Track your parcel', $mail->actionText);

                return true;
            },
        );
    }

    /** The shop's own form needs the list, and reads it off the order. */
    public function test_the_shop_is_given_the_carriers_it_may_choose(): void
    {
        $order = $this->accepted();

        $this->actingAs($this->shopOwner)
            ->getJson("/api/v1/seller/orders/{$order->reference}")
            ->assertOk()
            ->assertJsonPath('data.carriers.0.value', 'postnord')
            ->assertJsonPath('data.carriers.0.label', 'PostNord')
            ->assertJsonCount(count(Carrier::cases()), 'data.carriers');
    }

    /** An order the shop has accepted, which is the only thing it may send. */
    private function accepted(): Order
    {
        $order = $this->placeOrder($this->buyer, $this->variant);

        $this->actingAs($this->shopOwner)
            ->fromFrontend()
            ->postJson("/api/v1/seller/orders/{$order->reference}/acceptance")
            ->assertOk();

        return $order->refresh();
    }

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    private function ship(Order $order, array $body = []): TestResponse
    {
        return $this->actingAs($this->shopOwner)
            ->fromFrontend()
            ->postJson("/api/v1/seller/orders/{$order->reference}/shipment", $body);
    }
}
