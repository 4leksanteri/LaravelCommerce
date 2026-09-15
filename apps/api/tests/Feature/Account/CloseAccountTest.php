<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Models\Address;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Review;
use App\Models\Seller;
use App\Models\User;
use App\Notifications\Account\AccountClosed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Orders\PlacesOrders;
use Tests\TestCase;

/**
 * Closing an account (ADR 0058).
 *
 * **It is not a delete and the database would not allow one.**
 * `orders.user_id` is `restrictOnDelete`, so a receipt outlives the account
 * that paid it - which is what ADR 0011 decided long before anybody tried to
 * delete anything. What happens instead is anonymisation, and the two halves
 * worth testing are what goes and what deliberately stays.
 */
final class CloseAccountTest extends TestCase
{
    use PlacesOrders;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'name' => 'Aino Virtanen',
            'email' => 'aino@example.com',
        ]);
    }

    // --- Who may --------------------------------------------------------------

    public function test_closing_needs_somebody_signed_in(): void
    {
        $this->fromFrontend()
            ->deleteJson('/api/v1/account', ['current_password' => 'password'])
            ->assertUnauthorized();
    }

    /**
     * The same guard the address and the password have, and with the strongest
     * claim to it: those two can be undone from an inbox and this cannot be
     * undone at all (ADR 0034).
     */
    public function test_it_needs_the_current_password(): void
    {
        $this->close(password: 'not-the-password')
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        $this->assertNull($this->user->refresh()->closed_at);
    }

    // --- What goes ------------------------------------------------------------

    public function test_it_stops_naming_anybody(): void
    {
        $this->user->forceFill(['stripe_customer_id' => 'cus_1Aino'])->save();

        $this->close()->assertNoContent();

        $closed = $this->user->refresh();

        $this->assertNotNull($closed->closed_at);
        $this->assertSame('Closed account', $closed->name);
        $this->assertNull($closed->stripe_customer_id);
        $this->assertNull($closed->email_verified_at);

        // Unique per account, because `users.email` is unique and a second
        // closure would otherwise collide with the first. `.invalid` is
        // reserved so that it can never be delivered to.
        $this->assertSame("closed-{$closed->id}@deleted.invalid", $closed->email);
    }

    public function test_the_old_password_no_longer_signs_in(): void
    {
        $this->close()->assertNoContent();

        /*
         * **`forgetGuards()` is not enough here**, which is worth writing down.
         *
         * `actingAs` on a route behind `auth:sanctum` switches the *default*
         * guard to Sanctum's, and forgetting guards only discards the resolved
         * instances - the default survives. `LoginController` then calls
         * `Auth::attempt()`, which resolves that default to a `RequestGuard`
         * and dies with "Method attempt does not exist": a missing framework
         * method, rather than anything about this test signing in.
         *
         * `LoginTest` never meets it because it never signs anybody in first.
         * Restoring the default is the fix, and `config/auth.php` defines
         * exactly one guard to restore it to.
         */
        Auth::shouldUse('web');

        $this->fromFrontend()
            ->postJson('/api/v1/auth/login', [
                'email' => 'aino@example.com',
                'password' => 'password',
            ])
            ->assertStatus(422);
    }

    public function test_the_address_book_the_basket_and_the_sessions_go(): void
    {
        Address::factory()->for($this->user)->create();

        $variant = $this->publishedVariant($this->approvedShop(User::factory()->create()));

        $this->actingAs($this->user)
            ->fromFrontend()
            ->postJson('/api/v1/cart/items', ['variant_id' => $variant->id])
            ->assertOk();

        DB::table('sessions')->insert([
            'id' => 'a-session-somewhere-else',
            'user_id' => $this->user->id,
            'payload' => '',
            'last_activity' => time(),
        ]);

        $this->close()->assertNoContent();

        $this->assertDatabaseCount('addresses', 0);
        $this->assertDatabaseCount('carts', 0);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $this->user->id)->count());
    }

    /**
     * To the address on the way out, which is the only place somebody who did
     * not close the account would find out that somebody else did (ADR 0035).
     */
    public function test_the_address_being_closed_is_told(): void
    {
        Notification::fake();

        $this->close()->assertNoContent();

        Notification::assertSentOnDemand(
            AccountClosed::class,
            static fn ($notification, $channels, $notifiable): bool => $notifiable->routes['mail'] === 'aino@example.com',
        );
    }

    // --- What stays -----------------------------------------------------------

    /**
     * **A receipt belongs to the shop as much as to the buyer.** The order
     * survives, and the shop still sees who it shipped to - now under the
     * anonymised name rather than a blank, which is why this is not
     * `SoftDeletes`: a global scope would have made the buyer null here.
     */
    public function test_orders_are_kept_and_still_name_their_buyer(): void
    {
        $shopOwner = User::factory()->create();
        $shop = $this->approvedShop($shopOwner);
        $order = $this->finishedOrder($shop);

        $this->close()->assertNoContent();

        $this->assertDatabaseCount('orders', 1);

        $this->actingAs($shopOwner)
            ->getJson("/api/v1/seller/orders/{$order->reference}")
            ->assertOk()
            ->assertJsonPath('data.buyer_name', 'Closed account');
    }

    /**
     * ADR 0047 refused review deletion outright, because a shop whose worst
     * review can be argued away is a shop whose ratings mean nothing. Letting a
     * closure take one would be a back door through that decision, quietly
     * rewriting the rating of whichever shop it was about.
     */
    public function test_reviews_stay_and_lose_their_author(): void
    {
        $shop = $this->approvedShop(User::factory()->create());
        $variant = $this->publishedVariant($shop);

        Review::factory()->rated(2)->create([
            'product_id' => $variant->product_id,
            'user_id' => $this->user->id,
        ]);

        $this->close()->assertNoContent();

        $this->assertDatabaseCount('reviews', 1);

        $this->getJson("/api/v1/shops/{$shop->slug}/products/{$variant->product->slug}/reviews")
            ->assertOk()
            ->assertJsonPath('data.0.rating', 2)

            // Not "Closed a.", which is what the first-word-and-initial
            // shortening would otherwise make of it.
            ->assertJsonPath('data.0.author', 'A former customer');
    }

    // --- What has to finish first ---------------------------------------------

    public function test_an_open_order_refuses_it(): void
    {
        $this->placeOrder($this->user, $this->publishedVariant($this->approvedShop(User::factory()->create())));

        $this->close()
            ->assertStatus(409)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'not finished'));

        $this->assertNull($this->user->refresh()->closed_at);
    }

    public function test_an_open_dispute_refuses_it(): void
    {
        $shop = $this->approvedShop(User::factory()->create());

        $order = Order::factory()->for($this->user)->for($shop)->completed()->create();
        Payment::factory()->forOrder($order)->paid()->create();
        Dispute::factory()->for($order)->create();

        $this->close()
            ->assertStatus(409)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'dispute'));
    }

    /** Money owed back, which closing would strand. */
    public function test_a_refund_that_has_not_arrived_refuses_it(): void
    {
        $shop = $this->approvedShop(User::factory()->create());

        $order = Order::factory()->for($this->user)->for($shop)->cancelled()->create();
        Payment::factory()->forOrder($order)->paid()->create();

        $this->close()
            ->assertStatus(409)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'refund'));
    }

    /**
     * **The case the naive rule got wrong.** A completed order whose shop has
     * no active payout account stays paid-and-untransferred until a settlement
     * run collects it, and refusing on that would trap every buyer in a stack
     * where no shop has one - which is every buyer here.
     */
    public function test_a_completed_order_awaiting_its_transfer_does_not_refuse_it(): void
    {
        $this->finishedOrder($this->approvedShop(User::factory()->create()));

        $this->close()->assertNoContent();
    }

    /**
     * A shop cannot be abandoned, and this action deliberately cannot close
     * one: `sellers_suspension_is_whole` wants a member of staff against any
     * stopped shop, and the owner is not one.
     */
    public function test_a_shop_refuses_it(): void
    {
        $this->approvedShop($this->user);

        $this->close()
            ->assertStatus(409)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'shop'));
    }

    // --- Fixtures -------------------------------------------------------------

    /** Completed and paid, with the money still on the platform. */
    private function finishedOrder(Seller $shop): Order
    {
        $order = Order::factory()->for($this->user)->for($shop)->completed()->create();

        Payment::factory()->forOrder($order)->paid()->create();

        return $order->refresh();
    }

    /**
     * @return TestResponse<Response>
     */
    private function close(string $password = 'password'): TestResponse
    {
        return $this->actingAs($this->user)
            ->fromFrontend()
            ->deleteJson('/api/v1/account', ['current_password' => $password]);
    }
}
