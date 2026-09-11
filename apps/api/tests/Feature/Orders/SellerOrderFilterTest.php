<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A shop's order queue, narrowed to one status (ADR 0036).
 *
 * The shop's page asks this for "to accept" and "to send". The narrowing
 * happens inside the shop's own orders, so it cannot widen what a shop sees.
 */
final class SellerOrderFilterTest extends TestCase
{
    use PlacesOrders;
    use RefreshDatabase;

    public function test_the_queue_narrows_to_one_status(): void
    {
        $owner = User::factory()->create();
        $shop = $this->approvedShop($owner);
        $buyer = User::factory()->create();

        $waiting = $this->placeOrder($buyer, $this->publishedVariant($shop));
        $accepted = $this->placeOrder($buyer, $this->publishedVariant($shop));

        $this->actingAs($owner)
            ->fromFrontend()
            ->postJson("/api/v1/seller/orders/{$accepted->reference}/acceptance")
            ->assertOk();

        $this->actingAs($owner)
            ->getJson('/api/v1/seller/orders?status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', $waiting->reference)
            ->assertJsonPath('meta.total', 1);

        $this->actingAs($owner)
            ->getJson('/api/v1/seller/orders?status=accepted')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', $accepted->reference);

        $this->actingAs($owner)
            ->getJson('/api/v1/seller/orders')
            ->assertJsonPath('meta.total', 2);
    }

    /** Another shop's pending order stays out of this shop's "to accept". */
    public function test_narrowing_never_reaches_another_shops_orders(): void
    {
        $owner = User::factory()->create();
        $shop = $this->approvedShop($owner);
        $other = $this->approvedShop(User::factory()->create());
        $buyer = User::factory()->create();

        $this->placeOrder($buyer, $this->publishedVariant($other));
        $mine = $this->placeOrder($buyer, $this->publishedVariant($shop));

        $this->actingAs($owner)
            ->getJson('/api/v1/seller/orders?status=pending')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', $mine->reference);
    }

    /** A mistyped link says so, rather than showing an empty queue. */
    public function test_a_status_that_does_not_exist_is_refused(): void
    {
        $owner = User::factory()->create();
        $this->approvedShop($owner);

        $this->actingAs($owner)
            ->getJson('/api/v1/seller/orders?status=lost')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }
}
