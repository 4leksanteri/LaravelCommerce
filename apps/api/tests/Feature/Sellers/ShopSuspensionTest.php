<?php

declare(strict_types=1);

namespace Tests\Feature\Sellers;

use App\Enums\SellerStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Seller;
use App\Models\User;
use App\Notifications\Sellers\ShopReinstated;
use App\Notifications\Sellers\ShopSuspended;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Stopping a shop that has been trading, and letting it start again
 * (ADR 0052).
 *
 * The claim worth testing is that **one enum case does all of it**.
 * `Seller::scopePublic()` asks for `status = approved` rather than for "not
 * rejected", so suspension removes the shop from the storefront, its listings
 * from browse and search, and its ability to publish - none of which is written
 * anywhere as a suspension rule. These tests are what make that true rather
 * than merely likely.
 */
final class ShopSuspensionTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $owner;

    private Seller $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::factory()->staff()->create();
        $this->owner = User::factory()->create();
        $this->shop = Seller::factory()->for($this->owner)->approved()->create([
            'shop_name' => 'Second Hand Time',
        ]);
    }

    // --- Stopping one --------------------------------------------------------

    public function test_staff_suspend_a_trading_shop_and_it_is_told_why(): void
    {
        Notification::fake();

        $this->actingAs($this->staff)
            ->fromFrontend()
            ->postJson($this->suspensionUrl(), ['reason' => 'Three disputes decided against it.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.suspension_reason', 'Three disputes decided against it.')
            ->assertJsonPath('data.is_public', false);

        Notification::assertSentTo($this->shop, ShopSuspended::class);
    }

    /**
     * **The whole point of the design.** Nothing below is a suspension rule:
     * each falls out of `scopePublic()` asking for approved.
     */
    public function test_a_suspended_shop_and_its_listings_leave_the_storefront(): void
    {
        $variant = $this->publishedListing();
        $product = $variant->product;

        // Visible while it is trading.
        $this->getJson("/api/v1/shops/{$this->shop->slug}")->assertOk();
        $this->getJson("/api/v1/shops/{$this->shop->slug}/products/{$product->slug}")->assertOk();

        $this->suspend();

        // The shop's page, its listing and the shop's own product list all go.
        $this->getJson("/api/v1/shops/{$this->shop->slug}")->assertNotFound();
        $this->getJson("/api/v1/shops/{$this->shop->slug}/products/{$product->slug}")
            ->assertNotFound();

        // And it is gone from search, which reads through the same scope.
        $this->getJson('/api/v1/search?q='.urlencode($product->name))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_suspended_shop_cannot_publish_anything(): void
    {
        $variant = $this->publishedListing();
        $draft = Product::factory()->for($this->shop, 'seller')->create();

        $this->suspend();

        $this->actingAs($this->owner)
            ->fromFrontend()
            ->postJson("/api/v1/seller/products/{$draft->id}/publication")
            ->assertStatus(409);

        // The one already published is not unpublished - it is simply not
        // public, because its shop is not.
        $this->assertTrue($variant->product->refresh()->isPublished());
    }

    /**
     * Suspension stops new trade and touches nothing already agreed. A shop
     * still owes what it has sold, and the `seller` middleware never checked
     * approval.
     */
    public function test_a_suspended_shop_can_still_work_the_orders_it_has(): void
    {
        $this->suspend();

        $this->actingAs($this->owner)
            ->getJson('/api/v1/seller/orders')
            ->assertOk();

        $this->actingAs($this->owner)
            ->getJson('/api/v1/seller')
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');
    }

    // --- The way out that must not exist -------------------------------------

    /**
     * **The escalation this chapter had to close.** `resubmit()` sets a shop
     * back to pending and clears the decision, and a suspended shop is not
     * public - so without the guard it would have fallen straight through and
     * put itself back in the queue.
     */
    public function test_a_suspended_shop_cannot_apply_again(): void
    {
        $this->suspend();

        $this->actingAs($this->owner)
            ->fromFrontend()
            ->postJson('/api/v1/seller/application', [
                'shop_name' => 'Second Hand Time',
                'description' => 'Trying to start again.',
                'contact_email' => 'shop@example.test',
                'currency' => 'EUR',
            ])
            ->assertStatus(409);

        $this->assertSame(SellerStatus::Suspended, $this->shop->refresh()->status);
    }

    /** And the frontend is told why the form is not offered. */
    public function test_the_blocker_says_the_shop_is_suspended(): void
    {
        $this->suspend();

        $this->actingAs($this->owner)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.shop_application_blocker', 'suspended');
    }

    // --- Letting it start again ----------------------------------------------

    public function test_reinstating_puts_it_back(): void
    {
        Notification::fake();

        $variant = $this->publishedListing();
        $this->suspend();

        $this->actingAs($this->staff)
            ->fromFrontend()
            ->deleteJson($this->suspensionUrl())
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.suspension_reason', null)
            ->assertJsonPath('data.is_public', true);

        // The listing is on sale again without anything touching it.
        $this->getJson("/api/v1/shops/{$this->shop->slug}/products/{$variant->product->slug}")
            ->assertOk();

        Notification::assertSentTo($this->shop, ShopReinstated::class);
    }

    public function test_only_a_trading_shop_can_be_suspended(): void
    {
        $pending = Seller::factory()->for(User::factory())->create();

        $this->actingAs($this->staff)
            ->fromFrontend()
            ->postJson("/api/v1/admin/sellers/{$pending->id}/suspension", [
                'reason' => 'It has not even opened yet.',
            ])
            ->assertStatus(409)
            ->assertJsonPath('status', 'pending');
    }

    public function test_only_a_suspended_shop_can_be_reinstated(): void
    {
        $this->actingAs($this->staff)
            ->fromFrontend()
            ->deleteJson($this->suspensionUrl())
            ->assertStatus(409)
            ->assertJsonPath('status', 'approved');
    }

    // --- Who may -------------------------------------------------------------

    public function test_a_shopper_cannot_suspend_a_shop(): void
    {
        $this->actingAs(User::factory()->create())
            ->fromFrontend()
            ->postJson($this->suspensionUrl(), ['reason' => 'I do not like it.'])
            ->assertForbidden();
    }

    /** Staff do not get to stop their own competition. */
    public function test_staff_cannot_suspend_their_own_shop(): void
    {
        $staffOwner = User::factory()->staff()->create();
        $theirs = Seller::factory()->for($staffOwner)->approved()->create();

        $this->actingAs($staffOwner)
            ->fromFrontend()
            ->postJson("/api/v1/admin/sellers/{$theirs->id}/suspension", [
                'reason' => 'Suspending my own shop.',
            ])
            ->assertForbidden();
    }

    public function test_a_reason_is_required_and_has_to_say_something(): void
    {
        foreach (['', 'no'] as $reason) {
            $this->actingAs($this->staff)
                ->fromFrontend()
                ->postJson($this->suspensionUrl(), ['reason' => $reason])
                ->assertStatus(422)
                ->assertJsonValidationErrors('reason');
        }
    }

    // --- Fixtures -------------------------------------------------------------

    private function suspend(): void
    {
        $this->actingAs($this->staff)
            ->fromFrontend()
            ->postJson($this->suspensionUrl(), ['reason' => 'Three disputes decided against it.'])
            ->assertOk();

        $this->shop->refresh();
    }

    private function publishedListing(): ProductVariant
    {
        $product = Product::factory()->for($this->shop, 'seller')->published()->create();

        return ProductVariant::factory()->for($product)->create([
            'name' => 'Default',
            'price_minor' => 2499,
            'stock' => 5,
            'position' => 0,
        ]);
    }

    private function suspensionUrl(): string
    {
        return "/api/v1/admin/sellers/{$this->shop->id}/suspension";
    }
}
