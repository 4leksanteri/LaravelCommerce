<?php

declare(strict_types=1);

namespace Tests\Feature\Products;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Who can fetch a photograph, and of what.
 *
 * **The threat model, stated so it can be argued with.**
 *
 * An image of a *published* listing is public: no signature, no session, cached
 * for a year. There is nothing to protect - it is on a storefront.
 *
 * An image of anything else - a draft, an unapproved shop, a deleted listing -
 * is served only against a valid, expiring signature. The signed URL appears
 * only inside `ProductResource`, which only that listing's own seller can
 * fetch, so having a working URL already implies authorization.
 *
 * What is deliberately **not** claimed: that unpublishing retracts anything. An
 * image that was public has been cached under an immutable URL by browsers and
 * by any CDN, and no header recalls those copies.
 */
final class ProductImageAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private Seller $shop;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('products');

        $this->seller = User::factory()->create();
        $this->shop = Seller::factory()->for($this->seller)->approved()->create();
    }

    // --- Public listings ------------------------------------------------------

    public function test_an_image_of_a_published_listing_needs_no_signature(): void
    {
        $image = $this->imageOn($this->publishedProduct());

        Auth::forgetGuards();
        $this->assertGuest();

        $response = $this->get("/api/v1/images/{$image->uuid}")->assertOk();

        $this->assertStringContainsString('immutable', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
    }

    public function test_a_published_listing_publishes_an_unsigned_url(): void
    {
        $image = $this->imageOn($this->publishedProduct());

        $this->assertSame("/api/v1/images/{$image->uuid}", $image->refresh()->url());
    }

    // --- Everything else ------------------------------------------------------

    public function test_a_draft_publishes_a_signed_url_and_refuses_an_unsigned_one(): void
    {
        $image = $this->imageOn($this->draftProduct());

        $url = $image->refresh()->url();

        $this->assertStringContainsString('signature=', $url);
        $this->assertStringContainsString('expires=', $url);

        Auth::forgetGuards();

        // The bare path, which is what a leaked uuid would give somebody.
        $this->get("/api/v1/images/{$image->uuid}")->assertNotFound();

        // The signed one works, and is never cached.
        $response = $this->get($url)->assertOk();

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('immutable', (string) $response->headers->get('Cache-Control'));
    }

    /**
     * The seller's own session is not what makes it work, and must not be. An
     * `<img>` request may arrive without an Origin or Referer, so Sanctum would
     * not treat it as stateful - authenticating images on a header a referrer
     * policy can strip is how they stop loading for some people and not others.
     */
    public function test_the_owners_session_does_not_open_an_unsigned_draft_image(): void
    {
        $image = $this->imageOn($this->draftProduct());

        $this->actingAs($this->seller)
            ->get("/api/v1/images/{$image->uuid}")
            ->assertNotFound();
    }

    public function test_a_tampered_signature_is_refused(): void
    {
        $image = $this->imageOn($this->draftProduct());
        $url = $image->refresh()->url();

        Auth::forgetGuards();

        // Flip one character of the signature.
        $tampered = preg_replace('/signature=(.)/', 'signature='.'z', $url);

        $this->get((string) $tampered)->assertNotFound();
    }

    public function test_an_expired_signature_is_refused(): void
    {
        $image = $this->imageOn($this->draftProduct());
        $url = $image->refresh()->url();

        Auth::forgetGuards();

        $this->get($url)->assertOk();

        $this->travel(61)->minutes();

        $this->get($url)->assertNotFound();
    }

    /**
     * A signature covers the whole path, so one minted for a listing somebody
     * does own cannot be pointed at one they do not.
     */
    public function test_a_signature_for_one_image_does_not_open_another(): void
    {
        $mine = $this->imageOn($this->draftProduct());

        $strangerShop = Seller::factory()->approved()->create();
        $theirs = $this->imageOn(
            Product::factory()->for($strangerShop, 'seller')->withVariant()->create()
        );

        $url = $mine->refresh()->url();
        $swapped = str_replace($mine->uuid, $theirs->uuid, $url);

        Auth::forgetGuards();

        $this->get($swapped)->assertNotFound();
    }

    /**
     * The rule is the listing's visibility, not its status alone. A shop that
     * has not been approved is not a storefront, so its photographs are not
     * public either - the same pair `Product::isPublic()` holds everywhere.
     */
    public function test_an_unapproved_shops_image_is_not_public(): void
    {
        $pending = Seller::factory()->create();

        $product = Product::factory()->for($pending, 'seller')->withVariant()
            ->for(Category::factory())->published()->create();

        $image = $this->imageOn($product);

        Auth::forgetGuards();

        $this->get("/api/v1/images/{$image->uuid}")->assertNotFound();
    }

    public function test_unpublishing_closes_the_unsigned_url(): void
    {
        $product = $this->publishedProduct();
        $image = $this->imageOn($product);

        Auth::forgetGuards();
        $this->get("/api/v1/images/{$image->uuid}")->assertOk();

        $product->forceFill(['status' => 'draft', 'published_at' => null])->save();

        $this->get("/api/v1/images/{$image->uuid}")->assertNotFound();
    }

    public function test_a_deleted_listings_image_is_not_public(): void
    {
        $product = $this->publishedProduct();
        $image = $this->imageOn($product);

        $product->delete();

        Auth::forgetGuards();

        $this->get("/api/v1/images/{$image->uuid}")->assertNotFound();
    }

    // --- The cost of asking ---------------------------------------------------

    /**
     * `Product::images()` chaperones, so asking each image whether its listing
     * is public costs nothing on a page that already loaded the listings.
     *
     * Pinned with a count rather than described, because the failure mode is
     * silent: without it a page of 24 listings issues 24 extra queries and
     * everything still works.
     */
    public function test_a_storefront_page_does_not_query_per_image(): void
    {
        $this->addListingsWithImages(5);
        $forFive = $this->countStorefrontQueries(5);

        $this->addListingsWithImages(5);
        $forTen = $this->countStorefrontQueries(10);

        // **Scale-invariance rather than a magic number.** A fixed count would
        // need updating whenever an eager load is added and would say nothing
        // about the thing that matters. Twice the listings and twice the
        // photographs costing the same number of queries is exactly the claim.
        $this->assertSame(
            $forFive,
            $forTen,
            "Ten listings cost {$forTen} queries where five cost {$forFive}. Something loads per row.",
        );
    }

    /**
     * The category page renders the same resource from its own eager-load
     * list, so it can drift independently. It did: `category` was missing from
     * both until this test found it on the storefront.
     */
    public function test_a_category_page_does_not_query_per_image(): void
    {
        $category = Category::factory()->create(['slug' => 'bread']);

        $this->addListingsWithImages(5, $category);
        $forFive = $this->countCategoryQueries('bread', 5);

        $this->addListingsWithImages(5, $category);
        $forTen = $this->countCategoryQueries('bread', 10);

        $this->assertSame(
            $forFive,
            $forTen,
            "Ten listings cost {$forTen} queries where five cost {$forFive}. Something loads per row.",
        );
    }

    private function countCategoryQueries(string $slug, int $expected): int
    {
        Auth::forgetGuards();

        // Each measurement's listener closes over its own counter, so the one
        // left behind by a previous call increments a variable nobody reads.
        $queries = 0;

        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->getJson("/api/v1/categories/{$slug}/products")
            ->assertOk()
            ->assertJsonCount($expected, 'data');

        return $queries;
    }

    private function addListingsWithImages(int $count, ?Category $category = null): void
    {
        $products = Product::factory()->for($this->shop, 'seller')->withVariant()
            ->for($category ?? Category::factory())->published()->count($count)->create();

        foreach ($products as $product) {
            ProductImage::factory()->for($product)->create();
            ProductImage::factory()->for($product)->create(['position' => 1]);
        }
    }

    private function countStorefrontQueries(int $expected): int
    {
        Auth::forgetGuards();

        // Each measurement's listener closes over its own counter, so the one
        // left behind by a previous call increments a variable nobody reads.
        $queries = 0;

        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->getJson("/api/v1/shops/{$this->shop->slug}/products")
            ->assertOk()
            ->assertJsonCount($expected, 'data');

        return $queries;
    }

    // --- Helpers --------------------------------------------------------------

    private function publishedProduct(): Product
    {
        return Product::factory()->for($this->shop, 'seller')->withVariant()
            ->for(Category::factory())->published()->create();
    }

    private function draftProduct(): Product
    {
        return Product::factory()->for($this->shop, 'seller')->withVariant()->create();
    }

    private function imageOn(Product $product): ProductImage
    {
        // As whoever owns the listing. The policy is right to refuse anybody
        // else, which is what made this helper fail for another shop's product.
        $this->actingAs($product->seller->user)
            ->fromFrontend()
            ->post("/api/v1/seller/products/{$product->id}/images", [
                'image' => UploadedFile::fake()->image('photo.jpg', 400, 300),
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        return ProductImage::query()->where('product_id', $product->id)->latest('id')->firstOrFail();
    }
}
