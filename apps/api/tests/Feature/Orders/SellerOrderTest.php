<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Enums\Currency;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a shop sees of what has been bought from it.
 *
 * Every query starts from `$seller->orders()`, so another shop's orders are not
 * merely refused - they are never in it. That is the whole of the ownership
 * rule here (ADR 0008), and it is why there is still no `OrderPolicy`.
 */
final class SellerOrderTest extends TestCase
{
    use PlacesOrders;
    use RefreshDatabase;

    private User $shopOwner;

    private Seller $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shopOwner = User::factory()->create();
        $this->shop = $this->approvedShop($this->shopOwner);
    }

    public function test_the_seller_order_list_needs_a_shop(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/seller/orders')
            ->assertForbidden();
    }

    public function test_it_requires_a_signed_in_caller(): void
    {
        $this->getJson('/api/v1/seller/orders')->assertUnauthorized();
    }

    public function test_a_seller_sees_only_orders_placed_with_their_shop(): void
    {
        $mine = $this->publishedVariant($this->shop);
        $this->placeOrder(User::factory()->create(), $mine);
        $this->placeOrder(User::factory()->create(), $mine);

        $otherShop = $this->approvedShop(User::factory()->create());
        $this->placeOrder(User::factory()->create(), $this->publishedVariant($otherShop));

        $this->actingAs($this->shopOwner)
            ->getJson('/api/v1/seller/orders')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_another_shops_order_is_a_404(): void
    {
        $otherShop = $this->approvedShop(User::factory()->create());
        $theirs = $this->placeOrder(User::factory()->create(), $this->publishedVariant($otherShop));

        $this->actingAs($this->shopOwner)
            ->getJson("/api/v1/seller/orders/{$theirs->reference}")
            ->assertNotFound();
    }

    /**
     * A shop owner buys from other shops with the same account (ADR 0007). What
     * they bought is not what they sold, and the two lists must not mix.
     */
    public function test_a_shop_owners_own_purchases_are_not_in_their_sales(): void
    {
        $otherShop = $this->approvedShop(User::factory()->create());
        $this->placeOrder($this->shopOwner, $this->publishedVariant($otherShop));

        $this->actingAs($this->shopOwner)
            ->getJson('/api/v1/seller/orders')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // It is in their own order history, because they bought it.
        $this->actingAs($this->shopOwner)
            ->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /**
     * A different allowlist from the buyer's view, and the two differences are
     * the reason `SellerOrderResource` exists rather than a flag on the other
     * one.
     *
     * `buyer_name` is here: a seller has to know who they are sending to.
     * `checkout_reference` is not: it would tell them this purchase had other
     * parts, and by implication that their buyer was shopping elsewhere at that
     * moment (ADR 0011).
     */
    public function test_the_seller_view_publishes_a_fixed_shape(): void
    {
        $buyer = User::factory()->create(['name' => 'Sofia Lindqvist']);
        $order = $this->placeOrder($buyer, $this->publishedVariant($this->shop));

        $response = $this->actingAs($this->shopOwner)
            ->getJson("/api/v1/seller/orders/{$order->reference}")
            ->assertOk();

        $this->assertSame(
            [
                'reference',
                'status',
                'buyer_name',
                'currency',
                'total_minor',
                'shipping_address',
                'item_count',
                'items',
                'placed_at',
                'accepted_at',
                'shipped_at',
                'completed_at',
                'cancelled_at',
                'auto_complete_at',
                'can_accept',
                'can_ship',
                'can_cancel',
            ],
            array_keys((array) $response->json('data')),
        );

        $response->assertJsonPath('data.buyer_name', 'Sofia Lindqvist');
    }

    public function test_a_seller_sees_the_orders_own_currency(): void
    {
        $swedish = $this->approvedShop(User::factory()->create(), Currency::SEK);
        $owner = $swedish->user;

        $order = $this->placeOrder(
            User::factory()->create(),
            $this->publishedVariant($swedish, priceMinor: 12900),
            1,
        );

        $this->actingAs($owner)
            ->getJson("/api/v1/seller/orders/{$order->reference}")
            ->assertOk()
            ->assertJsonPath('data.currency', 'SEK')
            ->assertJsonPath('data.total_minor', 12900);
    }

    public function test_the_listing_is_newest_first(): void
    {
        $variant = $this->publishedVariant($this->shop);
        $older = $this->placeOrder(User::factory()->create(), $variant);
        $newer = $this->placeOrder(User::factory()->create(), $variant);

        $this->actingAs($this->shopOwner)
            ->getJson('/api/v1/seller/orders')
            ->assertOk()
            ->assertJsonPath('data.0.reference', $newer->reference)
            ->assertJsonPath('data.1.reference', $older->reference);
    }
}
