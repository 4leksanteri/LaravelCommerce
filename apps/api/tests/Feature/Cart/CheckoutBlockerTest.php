<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Models\Address;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The cart's answer to "can this be checked out, and if not, what first".
 *
 * The answer is only worth sending if it agrees with what checkout then does,
 * so the last test asks both questions of the same basket: whatever the cart
 * says is in the way is what POST /checkout refuses with (ADR 0030).
 */
final class CheckoutBlockerTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_empty_basket_has_nothing_to_check_out(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.checkout_blocker', 'empty');
    }

    public function test_a_basket_that_can_be_checked_out_has_nothing_in_the_way(): void
    {
        $buyer = User::factory()->create();
        $this->add($buyer, $this->variant());

        $this->actingAs($buyer)
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.checkout_blocker', null);
    }

    public function test_a_line_that_can_no_longer_be_bought_blocks_checkout(): void
    {
        $buyer = User::factory()->create();
        $variant = $this->variant();
        $this->add($buyer, $variant);

        $variant->forceFill(['stock' => 0])->save();

        $this->actingAs($buyer)
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.checkout_blocker', 'unavailable_items');
    }

    /**
     * Checkout's `verified` middleware runs before the basket is revalidated,
     * so an unconfirmed address is the first answer even when a line is also
     * unavailable - the order a person would meet the refusals in.
     */
    public function test_an_unconfirmed_address_comes_before_an_unavailable_line(): void
    {
        $buyer = User::factory()->unverified()->create();
        $variant = $this->variant();
        $this->add($buyer, $variant);

        $variant->forceFill(['stock' => 0])->save();

        $this->actingAs($buyer)
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.checkout_blocker', 'unverified_email');
    }

    /**
     * **The answer and the rule are the same rule.** For each basket, the cart
     * says what is in the way and checkout refuses with exactly that - or, when
     * the cart says nothing is, places the orders.
     */
    public function test_the_answer_agrees_with_what_checkout_does(): void
    {
        $cases = [
            'unverified_email' => [User::factory()->unverified()->create(), false, 403],
            'unavailable_items' => [User::factory()->create(), true, 409],
            'nothing' => [User::factory()->create(), false, 201],
        ];

        foreach ($cases as $label => [$buyer, $sellOut, $status]) {
            $variant = $this->variant();
            $this->add($buyer, $variant);

            if ($sellOut) {
                $variant->forceFill(['stock' => 0])->save();
            }

            $this->actingAs($buyer)
                ->getJson('/api/v1/cart')
                ->assertJsonPath('data.checkout_blocker', $label === 'nothing' ? null : $label);

            $this->actingAs($buyer)
                ->fromFrontend()
                ->postJson('/api/v1/checkout', ['address_id' => Address::factory()->for($buyer)->create()->id])
                ->assertStatus($status);
        }
    }

    private function variant(): ProductVariant
    {
        $product = Product::factory()
            ->for(Seller::factory()->approved(), 'seller')
            ->published()
            ->withVariant(stock: 5)
            ->create();

        return $product->variants()->firstOrFail();
    }

    private function add(User $buyer, ProductVariant $variant): void
    {
        $this->actingAs($buyer)
            ->fromFrontend()
            ->postJson('/api/v1/cart/items', ['variant_id' => $variant->id])
            ->assertOk();
    }
}
