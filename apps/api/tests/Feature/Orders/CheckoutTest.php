<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Enums\Currency;
use App\Enums\ProductStatus;
use App\Enums\SellerStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Turning a cart into orders.
 *
 * The two halves worth testing are that what gets written is right, and that
 * when it is refused **nothing** gets written - no order, no stock taken, and a
 * cart exactly as it was.
 */
final class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;

    private Seller $bakery;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buyer = User::factory()->create();

        $this->bakery = Seller::factory()->approved()->create([
            'shop_name' => 'Aalto Bakery',
            'slug' => 'aalto-bakery',
            'currency' => Currency::EUR,
        ]);
    }

    // --- Who may -------------------------------------------------------------

    public function test_checkout_requires_a_signed_in_shopper(): void
    {
        $this->fromFrontend()->postJson('/api/v1/checkout')->assertUnauthorized();
    }

    /**
     * `verified` is here and deliberately not on the cart. Filling a basket is
     * browsing; the confirmation, the receipt and everything about a dispute go
     * to an address, and this is where one that nobody has confirmed becomes a
     * problem.
     */
    public function test_checkout_requires_a_verified_address(): void
    {
        $unverified = User::factory()->unverified()->create();
        $variant = $this->publishedVariant();

        $this->actingAs($unverified)
            ->fromFrontend()
            ->postJson('/api/v1/cart/items', ['variant_id' => $variant->id])
            ->assertOk();

        $this->actingAs($unverified)
            ->fromFrontend()
            ->postJson('/api/v1/checkout')
            ->assertForbidden();

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_an_empty_cart_cannot_be_checked_out(): void
    {
        $this->checkout()
            ->assertStatus(409)
            ->assertJsonPath('message', 'There is nothing in your cart.')
            ->assertJsonCount(0, 'items');

        $this->assertDatabaseCount('orders', 0);
    }

    // --- What gets written ---------------------------------------------------

    public function test_a_cart_becomes_an_order_with_a_server_side_total(): void
    {
        $this->add($this->publishedVariant(priceMinor: 650, stock: 10), 2);
        $this->add($this->publishedVariant(priceMinor: 1250, stock: 10), 1);

        $response = $this->checkout()->assertCreated();

        $response
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.shop_slug', 'aalto-bakery')
            ->assertJsonPath('data.0.currency', 'EUR')
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.0.item_count', 3)
            // 2 x 650 + 1 x 1250
            ->assertJsonPath('data.0.total_minor', 2550)
            ->assertJsonCount(2, 'data.0.items');

        $order = Order::query()->firstOrFail();

        $this->assertSame(2550, $order->total_minor);
        $this->assertSame(
            $order->total_minor,
            $order->recalculatedTotalMinor(),
            'A stored total that has drifted from the lines it totals is a defect nothing else would notice.',
        );
    }

    /**
     * The rule that has been coming since ADR 0004. Two shops means two
     * agreements, in two currencies, settled separately - so two orders, and no
     * figure anywhere that spans them.
     */
    public function test_a_cart_spanning_two_shops_becomes_one_order_each(): void
    {
        $roastery = Seller::factory()->approved()->create([
            'shop_name' => 'Bergman Coffee',
            'slug' => 'bergman-coffee',
            'currency' => Currency::SEK,
        ]);

        $this->add($this->publishedVariant(priceMinor: 650, stock: 10), 2);
        $this->add($this->publishedVariant(shop: $roastery, priceMinor: 12900, stock: 10), 1);

        $response = $this->checkout()->assertCreated();

        $response
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.shop_slug', 'aalto-bakery')
            ->assertJsonPath('data.0.currency', 'EUR')
            ->assertJsonPath('data.0.total_minor', 1300)
            ->assertJsonPath('data.1.shop_slug', 'bergman-coffee')
            ->assertJsonPath('data.1.currency', 'SEK')
            ->assertJsonPath('data.1.total_minor', 12900);

        $this->assertSame(2, Order::query()->count());
    }

    public function test_the_cart_is_emptied_only_after_the_orders_exist(): void
    {
        $this->add($this->publishedVariant(stock: 10), 2);

        $this->checkout()->assertCreated();

        $this->assertDatabaseCount('cart_items', 0);

        // The cart itself survives. It is the account's, not the purchase's.
        $this->assertDatabaseCount('carts', 1);

        $this->actingAs($this->buyer)
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.item_count', 0);
    }

    public function test_checking_out_twice_does_not_place_the_same_order_again(): void
    {
        $this->add($this->publishedVariant(stock: 10), 1);

        $this->checkout()->assertCreated();
        $this->checkout()->assertStatus(409);

        $this->assertSame(1, Order::query()->count());
    }

    public function test_stock_is_taken_when_the_order_is_placed(): void
    {
        $variant = $this->publishedVariant(stock: 10);
        $this->add($variant, 3);

        $this->checkout()->assertCreated();

        $this->assertSame(7, $variant->refresh()->stock);
    }

    public function test_a_reference_is_generated_rather_than_exposing_the_id(): void
    {
        $this->add($this->publishedVariant(stock: 10), 1);

        $reference = (string) $this->checkout()->assertCreated()->json('data.0.reference');

        $this->assertMatchesRegularExpression('/^[A-Z2-9]{10}$/', $reference);
        $this->assertStringNotContainsString('0', $reference, 'Ambiguous characters are excluded.');
    }

    // --- The snapshot --------------------------------------------------------

    /**
     * The other side of ADR 0010's line. A cart reads its price from the
     * catalogue every time it is shown; an order never does again.
     */
    public function test_an_order_does_not_move_when_the_catalogue_does(): void
    {
        $variant = $this->publishedVariant(priceMinor: 650, stock: 10);
        $this->add($variant, 2);

        $reference = (string) $this->checkout()->assertCreated()->json('data.0.reference');

        $variant->forceFill(['price_minor' => 990, 'name' => 'Renamed Variant'])->save();
        $variant->product->forceFill(['name' => 'Renamed Product'])->save();

        $this->actingAs($this->buyer)
            ->getJson("/api/v1/orders/{$reference}")
            ->assertOk()
            ->assertJsonPath('data.total_minor', 1300)
            ->assertJsonPath('data.items.0.unit_price_minor', 650)
            ->assertJsonPath('data.items.0.line_total_minor', 1300)
            ->assertJsonPath('data.items.0.quantity', 2);
    }

    /**
     * What it is called **now**, not what the cart remembered from when it was
     * added. The cart shows the current name too, so this is the name the buyer
     * was looking at when they pressed the button.
     */
    public function test_the_name_snapshotted_is_the_one_at_checkout(): void
    {
        $variant = $this->publishedVariant(stock: 10);
        $this->add($variant, 1);

        $variant->forceFill(['name' => 'Large'])->save();
        $variant->product->forceFill(['name' => 'Rye Sourdough'])->save();

        $this->checkout()->assertCreated()
            ->assertJsonPath('data.0.items.0.product_name', 'Rye Sourdough')
            ->assertJsonPath('data.0.items.0.variant_name', 'Large');
    }

    // --- Refused, and nothing written ----------------------------------------

    public function test_a_line_that_sold_out_refuses_the_whole_checkout(): void
    {
        $available = $this->publishedVariant(priceMinor: 650, stock: 10);
        $soldOut = $this->publishedVariant(priceMinor: 900, stock: 5);

        $this->add($available, 1);
        $this->add($soldOut, 2);

        $soldOut->forceFill(['stock' => 0])->save();

        $this->checkout()
            ->assertStatus(409)
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.availability', 'out_of_stock')
            ->assertJsonPath('items.0.available', null);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('cart_items', 2);
        $this->assertSame(10, $available->refresh()->stock, 'No stock is taken when checkout is refused.');
    }

    public function test_a_line_short_of_stock_says_how_many_can_be_had(): void
    {
        $variant = $this->publishedVariant(stock: 10);
        $this->add($variant, 6);

        $variant->forceFill(['stock' => 4])->save();

        $this->checkout()
            ->assertStatus(409)
            ->assertJsonPath('items.0.availability', 'insufficient_stock')
            ->assertJsonPath('items.0.available', 4);

        $this->assertDatabaseCount('orders', 0);
    }

    /**
     * All or nothing, across shops. The buyer pressed one button under one
     * basket, and quietly buying the half that still works would leave them to
     * work out which half that was.
     */
    public function test_one_bad_line_refuses_the_other_shops_order_too(): void
    {
        $roastery = Seller::factory()->approved()->create(['currency' => Currency::SEK]);

        $fine = $this->publishedVariant(stock: 10);
        $doomed = $this->publishedVariant(shop: $roastery, stock: 10);

        $this->add($fine, 1);
        $this->add($doomed, 1);

        $doomed->product->forceFill(['status' => ProductStatus::Draft, 'published_at' => null])->save();

        $this->checkout()
            ->assertStatus(409)
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.availability', 'no_longer_for_sale');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertSame(10, $fine->refresh()->stock);
    }

    public function test_a_line_whose_shop_stopped_being_approved_refuses_checkout(): void
    {
        $this->add($this->publishedVariant(stock: 10), 1);

        $this->bakery->forceFill([
            'status' => SellerStatus::Rejected,
            'rejection_reason' => 'Trading under a name they do not own.',
            'reviewed_at' => now(),
        ])->save();

        $this->checkout()
            ->assertStatus(409)
            ->assertJsonPath('items.0.availability', 'no_longer_for_sale');

        $this->assertDatabaseCount('orders', 0);
    }

    // --- Helpers -------------------------------------------------------------

    private function publishedVariant(
        ?Seller $shop = null,
        int $priceMinor = 2499,
        int $stock = 10,
    ): ProductVariant {
        $product = Product::factory()->for($shop ?? $this->bakery, 'seller')->published()->create();

        return ProductVariant::factory()->for($product)->create([
            'name' => 'Default',
            'price_minor' => $priceMinor,
            'stock' => $stock,
            'position' => 0,
        ]);
    }

    private function add(ProductVariant $variant, int $quantity = 1): void
    {
        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson('/api/v1/cart/items', [
                'variant_id' => $variant->id,
                'quantity' => $quantity,
            ])
            ->assertOk();
    }

    /**
     * @return TestResponse<Response>
     */
    private function checkout(): TestResponse
    {
        return $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson('/api/v1/checkout');
    }
}
