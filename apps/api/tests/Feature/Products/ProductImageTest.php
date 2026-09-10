<?php

declare(strict_types=1);

namespace Tests\Feature\Products;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Photographs of a listing.
 *
 * The interesting assertions are about what is **stored**, not what was
 * uploaded: everything that gets in is decoded, re-oriented, downscaled and
 * re-encoded as WebP, and the original is never kept (ADR 0016).
 */
final class ProductImageTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private Seller $shop;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('products');

        $this->seller = User::factory()->create();
        $this->shop = Seller::factory()->for($this->seller)->approved()->create();
        $this->product = Product::factory()->for($this->shop, 'seller')->withVariant()->create();
    }

    public function test_a_seller_can_add_a_photograph_to_their_listing(): void
    {
        $response = $this->upload()->assertCreated();

        $image = ProductImage::query()->firstOrFail();

        $response
            ->assertJsonPath('data.id', $image->uuid)
            ->assertJsonPath('data.url', "/api/v1/images/{$image->uuid}")
            ->assertJsonPath('data.position', 0);

        Storage::disk('products')->assertExists($image->path);
    }

    /**
     * The point of the whole action. A JPEG goes in; a WebP is what exists
     * afterwards, and the JPEG is not kept anywhere.
     */
    public function test_whatever_is_uploaded_is_stored_as_webp(): void
    {
        $this->upload(UploadedFile::fake()->image('photo.jpg', 800, 600))->assertCreated();

        $image = ProductImage::query()->firstOrFail();

        $this->assertStringEndsWith('.webp', $image->path);

        $bytes = Storage::disk('products')->get($image->path);

        $this->assertNotNull($bytes);

        // The WebP container: "RIFF" then four bytes of length then "WEBP".
        $this->assertSame('RIFF', substr($bytes, 0, 4));
        $this->assertSame('WEBP', substr($bytes, 8, 4));
    }

    public function test_a_large_photograph_is_scaled_down_to_the_long_edge(): void
    {
        $this->upload(UploadedFile::fake()->image('huge.jpg', 4000, 3000))->assertCreated();

        $image = ProductImage::query()->firstOrFail();

        $this->assertSame(1600, $image->width);
        $this->assertSame(1200, $image->height, 'The aspect ratio is kept.');
    }

    /**
     * Scaled **down**, never up. A small photograph blown up to the cap would
     * be a blurry large one where a sharp small one was uploaded.
     */
    public function test_a_small_photograph_keeps_its_own_size(): void
    {
        $this->upload(UploadedFile::fake()->image('small.png', 320, 240))->assertCreated();

        $image = ProductImage::query()->firstOrFail();

        $this->assertSame(320, $image->width);
        $this->assertSame(240, $image->height);
    }

    public function test_the_dimensions_are_published_so_a_client_can_reserve_space(): void
    {
        $this->upload(UploadedFile::fake()->image('photo.jpg', 800, 600))
            ->assertCreated()
            ->assertJsonPath('data.width', 800)
            ->assertJsonPath('data.height', 600);
    }

    public function test_photographs_are_appended_rather_than_promoted(): void
    {
        $this->upload()->assertCreated()->assertJsonPath('data.position', 0);
        $this->upload()->assertCreated()->assertJsonPath('data.position', 1);
        $this->upload()->assertCreated()->assertJsonPath('data.position', 2);
    }

    public function test_a_listing_can_only_hold_so_many(): void
    {
        foreach (range(1, 8) as $ignored) {
            $this->upload()->assertCreated();
        }

        $this->upload()
            ->assertStatus(409)
            ->assertJsonPath('limit', 8)
            ->assertJsonPath('message', 'A listing can have 8 images. Remove one before adding another.');

        $this->assertSame(8, ProductImage::query()->count());
    }

    // --- What is refused ------------------------------------------------------

    /**
     * The type comes from the file's own contents, not from the Content-Type a
     * client attached - that is a string anybody can write.
     */
    public function test_something_that_is_not_an_image_is_refused(): void
    {
        $file = UploadedFile::fake()->create('invoice.pdf', 40, 'application/pdf');

        $this->upload($file)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');

        $this->assertSame(0, ProductImage::query()->count());
    }

    /**
     * A file whose header says JPEG and whose body does not follow gets past
     * `mimes`, because that reads the header. It must not then take the process
     * down - a 500 for a half-uploaded photograph is a bug report, a 422 is an
     * answer.
     */
    public function test_a_file_that_cannot_be_decoded_is_refused_rather_than_fatal(): void
    {
        $file = UploadedFile::fake()->create('photo.jpg', 40, 'image/jpeg');

        $this->upload($file)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');

        $this->assertSame(0, ProductImage::query()->count());
    }

    public function test_an_oversized_upload_is_refused(): void
    {
        $file = UploadedFile::fake()->image('enormous.jpg', 800, 600)->size(4096);

        $this->upload($file)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');
    }

    // --- Who may --------------------------------------------------------------

    public function test_another_shop_cannot_add_a_photograph_to_this_listing(): void
    {
        $stranger = User::factory()->create();
        Seller::factory()->for($stranger)->approved()->create();

        $this->actingAs($stranger)
            ->fromFrontend()
            ->post("/api/v1/seller/products/{$this->product->id}/images", [
                'image' => UploadedFile::fake()->image('photo.jpg', 400, 300),
            ])
            ->assertForbidden();

        $this->assertSame(0, ProductImage::query()->count());
    }

    /**
     * `scopeBindings()` doing its job: without it `{image}` resolves globally
     * and this would delete somebody else's photograph.
     */
    public function test_a_photograph_of_another_listing_cannot_be_deleted_through_this_one(): void
    {
        $other = Product::factory()->withVariant()->create();
        $theirs = ProductImage::factory()->for($other)->create();

        $this->actingAs($this->seller)
            ->fromFrontend()
            ->deleteJson("/api/v1/seller/products/{$this->product->id}/images/{$theirs->uuid}")
            ->assertNotFound();

        $this->assertSame(1, ProductImage::query()->count());
    }

    // --- Changing and removing ------------------------------------------------

    public function test_a_seller_can_reorder_and_describe_a_photograph(): void
    {
        $this->upload()->assertCreated();
        $image = ProductImage::query()->firstOrFail();

        $this->actingAs($this->seller)
            ->fromFrontend()
            ->patchJson("/api/v1/seller/products/{$this->product->id}/images/{$image->uuid}", [
                'position' => 3,
                'alt_text' => 'A dark rye loaf, sliced.',
            ])
            ->assertOk()
            ->assertJsonPath('data.position', 3)
            ->assertJsonPath('data.alt_text', 'A dark rye loaf, sliced.');
    }

    /**
     * A hard delete, and the file goes with it. Nothing references a photograph
     * the way order history references a listing, so an orphaned file on a disk
     * nobody can reach would be pure cost.
     */
    public function test_removing_a_photograph_removes_the_file(): void
    {
        $this->upload()->assertCreated();
        $image = ProductImage::query()->firstOrFail();

        Storage::disk('products')->assertExists($image->path);

        $this->actingAs($this->seller)
            ->fromFrontend()
            ->deleteJson("/api/v1/seller/products/{$this->product->id}/images/{$image->uuid}")
            ->assertNoContent();

        Storage::disk('products')->assertMissing($image->path);
        $this->assertSame(0, ProductImage::query()->count());
    }

    // --- What clients see -----------------------------------------------------

    public function test_the_storefront_publishes_photographs(): void
    {
        $this->upload()->assertCreated();
        $this->product->forceFill(['status' => 'published', 'published_at' => now()])->save();

        $image = ProductImage::query()->firstOrFail();

        $this->getJson("/api/v1/shops/{$this->shop->slug}/products/{$this->product->slug}")
            ->assertOk()
            ->assertJsonCount(1, 'data.images')
            ->assertJsonPath('data.images.0.url', "/api/v1/images/{$image->uuid}");
    }

    /**
     * Public, unauthenticated, and cached hard. The bytes at a key never change
     * - a re-encode makes a new row with a new key - so without this every
     * thumbnail on a catalogue page would be a PHP process.
     */
    public function test_a_photograph_is_served_to_anybody_with_its_key(): void
    {
        $this->upload()->assertCreated();
        $image = ProductImage::query()->firstOrFail();

        // The upload above left the seller on this test's guard. Forget it, or
        // "anybody" below means "the seller who uploaded it".
        Auth::forgetGuards();
        $this->assertGuest();

        $response = $this->get("/api/v1/images/{$image->uuid}");

        $response->assertOk();
        $this->assertSame('image/webp', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('immutable', (string) $response->headers->get('Cache-Control'));
    }

    public function test_an_unknown_key_is_a_404(): void
    {
        $this->get('/api/v1/images/'.fake()->uuid())->assertNotFound();
    }

    /** @return TestResponse<Response> */
    private function upload(?UploadedFile $file = null): TestResponse
    {
        return $this->actingAs($this->seller)
            ->fromFrontend()
            ->post("/api/v1/seller/products/{$this->product->id}/images", [
                'image' => $file ?? UploadedFile::fake()->image('photo.jpg', 400, 300),
            ], ['Accept' => 'application/json']);
    }
}
