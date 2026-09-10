<?php

declare(strict_types=1);

namespace Tests\Feature\Products;

use App\Models\Category;
use App\Models\Product;
use App\Models\Seller;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Browsing the marketplace by what things are.
 *
 * These are the first public reads that do not need a shop slug the caller
 * already has, which is what a shopper arriving at the front door needs
 * (ADR 0017).
 */
final class CategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_navigation_is_a_tree_and_needs_no_account(): void
    {
        $food = Category::factory()->create(['name' => 'Food', 'slug' => 'food', 'position' => 0]);
        Category::factory()->under($food)->create(['name' => 'Bread', 'slug' => 'bread', 'position' => 0]);
        Category::factory()->under($food)->create(['name' => 'Coffee', 'slug' => 'coffee', 'position' => 1]);
        Category::factory()->create(['name' => 'Home', 'slug' => 'home', 'position' => 1]);

        $this->assertGuest();

        $this->getJson('/api/v1/categories')
            ->assertOk()
            // Roots only at the top level; children are nested rather than flat.
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.slug', 'food')
            ->assertJsonPath('data.0.parent_slug', null)
            ->assertJsonCount(2, 'data.0.children')
            ->assertJsonPath('data.0.children.0.slug', 'bread')
            ->assertJsonPath('data.0.children.0.parent_slug', 'food')
            ->assertJsonPath('data.1.slug', 'home');
    }

    public function test_the_navigation_is_ordered_by_position_not_by_name(): void
    {
        Category::factory()->create(['name' => 'Zithers', 'slug' => 'zithers', 'position' => 0]);
        Category::factory()->create(['name' => 'Apples', 'slug' => 'apples', 'position' => 1]);

        $this->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'zithers')
            ->assertJsonPath('data.1.slug', 'apples');
    }

    // --- Browsing a category --------------------------------------------------

    public function test_a_category_lists_published_products_from_every_shop(): void
    {
        $bread = Category::factory()->create(['slug' => 'bread']);

        $first = Seller::factory()->approved()->create(['slug' => 'aalto', 'shop_name' => 'Aalto']);
        $second = Seller::factory()->approved()->create(['slug' => 'bergman', 'shop_name' => 'Bergman']);

        Product::factory()->for($first, 'seller')->for($bread)->withVariant()->published()->create();
        Product::factory()->for($second, 'seller')->for($bread)->withVariant()->published()->create();

        $response = $this->getJson('/api/v1/categories/bread/products')->assertOk();

        $response->assertJsonCount(2, 'data');

        // A card on a category page has to say whose shop it is - this is the
        // first place listings from different shops sit side by side.
        $shops = array_column((array) $response->json('data'), 'shop_slug');
        sort($shops);

        $this->assertSame(['aalto', 'bergman'], $shops);
    }

    /**
     * Somebody browsing "Food" expects the bread as well. A parent whose own
     * page is empty because everything hangs off its children is a navigation
     * that punishes using it.
     */
    public function test_a_parent_category_includes_what_is_underneath_it(): void
    {
        $food = Category::factory()->create(['slug' => 'food']);
        $bread = Category::factory()->under($food)->create(['slug' => 'bread']);

        $shop = Seller::factory()->approved()->create();

        Product::factory()->for($shop, 'seller')->for($food)->withVariant()->published()->create();
        Product::factory()->for($shop, 'seller')->for($bread)->withVariant()->published()->create();

        $this->getJson('/api/v1/categories/food/products')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/categories/bread/products')->assertOk()->assertJsonCount(1, 'data');
    }

    /**
     * `Product::scopePublic()` still decides what a shopper may see. A category
     * page is not a way around approval.
     */
    public function test_a_category_shows_nothing_from_an_unapproved_shop(): void
    {
        $bread = Category::factory()->create(['slug' => 'bread']);
        $pending = Seller::factory()->create();

        Product::factory()->for($pending, 'seller')->for($bread)->withVariant()->published()->create();

        $this->getJson('/api/v1/categories/bread/products')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_category_shows_no_drafts(): void
    {
        $bread = Category::factory()->create(['slug' => 'bread']);
        $shop = Seller::factory()->approved()->create();

        Product::factory()->for($shop, 'seller')->for($bread)->withVariant()->create();

        $this->getJson('/api/v1/categories/bread/products')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_an_unknown_category_is_a_404(): void
    {
        $this->getJson('/api/v1/categories/no-such-thing/products')->assertNotFound();
    }

    // --- Choosing one ---------------------------------------------------------

    public function test_a_seller_chooses_a_category_when_creating_a_listing(): void
    {
        $user = User::factory()->create();
        Seller::factory()->for($user)->approved()->create();
        $category = Category::factory()->create(['slug' => 'bread', 'name' => 'Bread']);

        $this->actingAs($user)
            ->fromFrontend()
            ->postJson('/api/v1/seller/products', [
                'name' => 'Rye Sourdough',
                'category_id' => $category->id,
                'variants' => [['name' => 'Small', 'price_minor' => 650, 'stock' => 4]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.category.slug', 'bread')
            ->assertJsonPath('data.category.name', 'Bread');
    }

    public function test_a_listing_can_be_drafted_without_one(): void
    {
        $user = User::factory()->create();
        Seller::factory()->for($user)->approved()->create();

        $this->actingAs($user)
            ->fromFrontend()
            ->postJson('/api/v1/seller/products', [
                'name' => 'Rye Sourdough',
                'variants' => [['name' => 'Small', 'price_minor' => 650, 'stock' => 4]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.category', null)
            ->assertJsonPath('data.status', 'draft');
    }

    /**
     * The rule that makes categories load-bearing rather than decorative. A
     * listing nobody can find is not on sale in any useful sense.
     *
     * 409, not 422: nothing about the request is wrong - the listing is not
     * ready (ADR 0008).
     */
    public function test_a_listing_without_a_category_cannot_be_published(): void
    {
        $user = User::factory()->create();
        $shop = Seller::factory()->for($user)->approved()->create();
        $product = Product::factory()->for($shop, 'seller')->withVariant()->create();

        $this->actingAs($user)
            ->fromFrontend()
            ->postJson("/api/v1/seller/products/{$product->id}/publication")
            ->assertStatus(409)
            ->assertJsonPath(
                'message',
                'Choose a category before publishing. A listing without one cannot be found.',
            );

        $this->assertSame('draft', $product->refresh()->status->value);
    }

    public function test_choosing_one_then_publishing_works(): void
    {
        $user = User::factory()->create();
        $shop = Seller::factory()->for($user)->approved()->create();
        $product = Product::factory()->for($shop, 'seller')->withVariant()->create();
        $category = Category::factory()->create(['slug' => 'bread']);

        $this->actingAs($user)
            ->fromFrontend()
            ->patchJson("/api/v1/seller/products/{$product->id}", ['category_id' => $category->id])
            ->assertOk()
            ->assertJsonPath('data.category.slug', 'bread');

        $this->actingAs($user)
            ->fromFrontend()
            ->postJson("/api/v1/seller/products/{$product->id}/publication")
            ->assertOk()
            ->assertJsonPath('data.status', 'published');
    }

    /**
     * `null` is a valid value for the column - it is what every draft has - so
     * the form request cannot refuse it. The database does, and without a
     * translation the seller got a constraint violation rendered as a 500.
     */
    public function test_a_published_listing_cannot_have_its_category_removed(): void
    {
        $user = User::factory()->create();
        $shop = Seller::factory()->for($user)->approved()->create();
        $product = Product::factory()->for($shop, 'seller')->withVariant()
            ->for(Category::factory())->published()->create();

        $this->actingAs($user)
            ->fromFrontend()
            ->patchJson("/api/v1/seller/products/{$product->id}", ['category_id' => null])
            ->assertStatus(409)
            ->assertJsonPath(
                'message',
                'A published listing needs a category. Choose a different one, or unpublish it first.',
            );

        $this->assertNotNull($product->refresh()->category_id);
    }

    public function test_a_draft_can_have_its_category_removed(): void
    {
        $user = User::factory()->create();
        $shop = Seller::factory()->for($user)->approved()->create();
        $product = Product::factory()->for($shop, 'seller')->withVariant()
            ->for(Category::factory())->create();

        $this->actingAs($user)
            ->fromFrontend()
            ->patchJson("/api/v1/seller/products/{$product->id}", ['category_id' => null])
            ->assertOk()
            ->assertJsonPath('data.category', null);
    }

    public function test_a_category_that_does_not_exist_is_a_validation_error(): void
    {
        $user = User::factory()->create();
        Seller::factory()->for($user)->approved()->create();

        $this->actingAs($user)
            ->fromFrontend()
            ->postJson('/api/v1/seller/products', [
                'name' => 'Rye Sourdough',
                'category_id' => 999_999,
                'variants' => [['name' => 'Small', 'price_minor' => 650, 'stock' => 4]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category_id');
    }

    /**
     * The seeder is the admin panel for now (ADR 0017), so it has to be safe to
     * run more than once - a deploy that reseeds must not double the
     * navigation.
     */
    public function test_the_seeder_is_idempotent(): void
    {
        $this->seed(CategorySeeder::class);
        $first = Category::query()->count();

        $this->seed(CategorySeeder::class);

        $this->assertSame($first, Category::query()->count());
        $this->assertGreaterThan(0, $first);
    }
}
