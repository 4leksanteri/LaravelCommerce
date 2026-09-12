<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Enums\Currency;
use App\Models\Address;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Seller;
use App\Models\User;

/**
 * Fixtures for the order tests.
 *
 * `placeOrder()` goes through the cart and checkout endpoints rather than
 * building rows, so what these tests assert against is what the application
 * actually writes - including the stock it took, which several of them then
 * check comes back.
 */
trait PlacesOrders
{
    private function approvedShop(User $owner, Currency $currency = Currency::EUR): Seller
    {
        return Seller::factory()->for($owner)->approved()->create(['currency' => $currency]);
    }

    private function publishedVariant(Seller $shop, int $priceMinor = 650, int $stock = 10): ProductVariant
    {
        $product = Product::factory()->for($shop, 'seller')->published()->create();

        return ProductVariant::factory()->for($product)->create([
            'name' => 'Default',
            'price_minor' => $priceMinor,
            'stock' => $stock,
            'position' => 0,
        ]);
    }

    /**
     * A buyer's address, made once and reused.
     *
     * Checkout needs somewhere to send the parcel (ADR 0021), and every test
     * that places an order needs one whether or not it is what the test is
     * about.
     */
    private function addressFor(User $buyer): Address
    {
        return $buyer->addresses()->first() ?? Address::factory()->for($buyer)->create();
    }

    /**
     * A placed order, paid for - which is what every order a shop can act on
     * is (ADR 0042). A shop's queue never shows an unpaid one, and accepting
     * one is refused.
     *
     * The payment is **written rather than taken**. These tests are about what
     * happens to an order; driving a card through Stripe to assert on a shop
     * accepting one would test the wrong thing.
     *
     * Checkout's own attempt to open an intent fails against the fake that
     * `TestCase` installs for every test, and is reported rather than raised
     * (ADR 0040). So an order reaches here with no payment row and this writes
     * the only one - which is exactly what stopped being true on the day the
     * suite could reach Stripe for real. `CheckoutPaymentTest` covers the
     * taking.
     */
    private function placeOrder(User $buyer, ProductVariant $variant, int $quantity = 2): Order
    {
        $order = $this->placeUnpaidOrder($buyer, $variant, $quantity);

        Payment::factory()->forOrder($order)->paid()->create();

        return $order->refresh();
    }

    /**
     * The same order, left unpaid: invisible to its shop, and on the short
     * clock rather than the long one (ADR 0042).
     */
    private function placeUnpaidOrder(User $buyer, ProductVariant $variant, int $quantity = 2): Order
    {
        $this->actingAs($buyer)
            ->fromFrontend()
            ->postJson('/api/v1/cart/items', [
                'variant_id' => $variant->id,
                'quantity' => $quantity,
            ])
            ->assertOk();

        $this->actingAs($buyer)
            ->fromFrontend()
            ->postJson('/api/v1/checkout', ['address_id' => $this->addressFor($buyer)->id])
            ->assertCreated();

        return Order::query()
            ->where('user_id', $buyer->id)
            ->where('seller_id', $variant->product->seller_id)
            ->latest('id')
            ->firstOrFail();
    }
}
