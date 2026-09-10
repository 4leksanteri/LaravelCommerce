<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Models\Address;
use App\Models\Order;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Somewhere to send a parcel.
 *
 * The decision worth testing is that an order **freezes** where it went rather
 * than pointing at an address book entry (ADR 0021). Somebody who moves house
 * must not rewrite where last year's parcels were sent.
 */
final class AddressTest extends TestCase
{
    use PlacesOrders;
    use RefreshDatabase;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buyer = User::factory()->create();
    }

    // --- The address book -----------------------------------------------------

    public function test_an_address_book_needs_a_signed_in_shopper(): void
    {
        $this->getJson('/api/v1/addresses')->assertUnauthorized();
    }

    public function test_a_shopper_can_add_an_address(): void
    {
        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson('/api/v1/addresses', [
                'name' => 'Jussi Koskela',
                'line1' => 'Mannerheimintie 12 A 4',
                'city' => 'Helsinki',
                'postal_code' => '00100',
                'country' => 'FI',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Jussi Koskela')
            ->assertJsonPath('data.country', 'FI')
            ->assertJsonPath('data.region', null);

        $this->assertSame(1, $this->buyer->addresses()->count());
    }

    /**
     * The owner comes from the session, never from the body. A payload carrying
     * `user_id` must not be able to file an address under somebody else.
     */
    public function test_the_owner_cannot_be_set_by_the_payload(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson('/api/v1/addresses', [
                'user_id' => $stranger->id,
                'name' => 'Jussi Koskela',
                'line1' => 'Mannerheimintie 12',
                'city' => 'Helsinki',
                'country' => 'FI',
            ])
            ->assertCreated();

        $this->assertSame(1, $this->buyer->addresses()->count());
        $this->assertSame(0, $stranger->addresses()->count());
    }

    /**
     * Plenty of countries have no state worth recording and several have no
     * postal codes at all - Ireland had none until 2015, the UAE still does
     * not. Requiring either teaches somebody to type "N/A" onto a parcel.
     */
    public function test_a_region_and_a_postal_code_are_both_optional(): void
    {
        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson('/api/v1/addresses', [
                'name' => 'Aoife Ni Bhriain',
                'line1' => ' 5 Corrig Avenue',
                'city' => 'Dun Laoghaire',
                'country' => 'IE',
            ])
            ->assertCreated()
            ->assertJsonPath('data.postal_code', null)
            ->assertJsonPath('data.region', null);
    }

    public function test_a_country_has_to_be_a_two_letter_code(): void
    {
        foreach (['United Kingdom', 'gb', 'FIN', ''] as $country) {
            $this->actingAs($this->buyer)
                ->fromFrontend()
                ->postJson('/api/v1/addresses', [
                    'name' => 'Jussi Koskela',
                    'line1' => 'Mannerheimintie 12',
                    'city' => 'Helsinki',
                    'country' => $country,
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('country');
        }
    }

    public function test_a_shopper_sees_only_their_own_addresses(): void
    {
        Address::factory()->for($this->buyer)->count(2)->create();
        Address::factory()->count(3)->create();

        $this->actingAs($this->buyer)
            ->getJson('/api/v1/addresses')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    /** 404 rather than 403: a 403 confirms the id names a real address. */
    public function test_another_shoppers_address_cannot_be_touched(): void
    {
        $theirs = Address::factory()->create();

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->patchJson("/api/v1/addresses/{$theirs->id}", ['city' => 'Nowhere'])
            ->assertNotFound();

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->deleteJson("/api/v1/addresses/{$theirs->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('addresses', ['id' => $theirs->id, 'city' => $theirs->city]);
    }

    public function test_a_shopper_can_correct_and_remove_an_address(): void
    {
        $address = Address::factory()->for($this->buyer)->create(['city' => 'Helsinki']);

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->patchJson("/api/v1/addresses/{$address->id}", ['city' => 'Espoo'])
            ->assertOk()
            ->assertJsonPath('data.city', 'Espoo');

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->deleteJson("/api/v1/addresses/{$address->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('addresses', ['id' => $address->id]);
    }

    // --- What an order remembers ----------------------------------------------

    public function test_checkout_needs_somewhere_to_send_it(): void
    {
        $shop = $this->approvedShop(User::factory()->create());
        $variant = $this->publishedVariant($shop);

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson('/api/v1/cart/items', ['variant_id' => $variant->id])
            ->assertOk();

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson('/api/v1/checkout')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('address_id');

        $this->assertDatabaseCount('orders', 0);
    }

    /**
     * Ownership is what matters, not existence. Somebody else's id and one that
     * was never issued give the same answer, which is the point.
     */
    public function test_checkout_refuses_an_address_that_is_not_the_buyers(): void
    {
        $theirs = Address::factory()->create();
        $shop = $this->approvedShop(User::factory()->create());
        $variant = $this->publishedVariant($shop);

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson('/api/v1/cart/items', ['variant_id' => $variant->id])
            ->assertOk();

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson('/api/v1/checkout', ['address_id' => $theirs->id])
            ->assertNotFound();

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson('/api/v1/checkout', ['address_id' => 999_999])
            ->assertNotFound();

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('cart_items', 1);
    }

    /**
     * **The decision this whole thing rests on.** Editing the address book must
     * not rewrite where a parcel already went, and deleting it must not erase
     * the record either.
     */
    public function test_an_order_keeps_where_it_went_when_the_address_book_changes(): void
    {
        $shop = $this->approvedShop(User::factory()->create());
        $address = Address::factory()->for($this->buyer)->create([
            'name' => 'Jussi Koskela',
            'line1' => 'Mannerheimintie 12 A 4',
            'city' => 'Helsinki',
        ]);

        $order = $this->placeOrder($this->buyer, $this->publishedVariant($shop));

        $this->assertSame('Mannerheimintie 12 A 4', $order->shipping_line1);

        // Moved house, and then tidied up.
        $address->forceFill(['line1' => 'Rantatie 8', 'city' => 'Espoo'])->save();
        $address->delete();

        $this->actingAs($this->buyer)
            ->getJson("/api/v1/orders/{$order->reference}")
            ->assertOk()
            ->assertJsonPath('data.shipping_address.line1', 'Mannerheimintie 12 A 4')
            ->assertJsonPath('data.shipping_address.city', 'Helsinki')
            ->assertJsonPath('data.shipping_address.name', 'Jussi Koskela');
    }

    /** A seller cannot post a parcel without knowing where it goes. */
    public function test_the_seller_sees_where_to_send_it(): void
    {
        $owner = User::factory()->create();
        $shop = $this->approvedShop($owner);

        Address::factory()->for($this->buyer)->create(['line1' => 'Mannerheimintie 12 A 4']);
        $order = $this->placeOrder($this->buyer, $this->publishedVariant($shop));

        $this->actingAs($owner)
            ->getJson("/api/v1/seller/orders/{$order->reference}")
            ->assertOk()
            ->assertJsonPath('data.shipping_address.line1', 'Mannerheimintie 12 A 4');
    }

    /**
     * One basket, one destination, however many parcels. Every order from a
     * multi-shop checkout is sent to the same place.
     */
    public function test_every_order_in_one_checkout_goes_to_the_same_address(): void
    {
        $first = $this->approvedShop(User::factory()->create());
        $second = $this->approvedShop(User::factory()->create());

        $address = Address::factory()->for($this->buyer)->create(['line1' => 'Rantatie 8']);

        foreach ([$first, $second] as $shop) {
            $this->actingAs($this->buyer)
                ->fromFrontend()
                ->postJson('/api/v1/cart/items', ['variant_id' => $this->publishedVariant($shop)->id])
                ->assertOk();
        }

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson('/api/v1/checkout', ['address_id' => $address->id])
            ->assertCreated()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.shipping_address.line1', 'Rantatie 8')
            ->assertJsonPath('data.1.shipping_address.line1', 'Rantatie 8');

        $this->assertSame(2, Order::query()->whereNotNull('shipping_line1')->count());
    }

    private function approvedShop(User $owner): Seller
    {
        return Seller::factory()->for($owner)->approved()->create();
    }
}
