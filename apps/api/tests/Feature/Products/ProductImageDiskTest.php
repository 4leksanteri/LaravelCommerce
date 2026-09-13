<?php

declare(strict_types=1);

namespace Tests\Feature\Products;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * The application does not care which disk an image is on (ADR 0048).
 *
 * That claim is what the move to object storage rests on, and it was made by
 * ADR 0016 long before there was a bucket: every write goes through
 * `Storage::disk()`, every row records the disk it was written to, and
 * `ProductImage::url()` is the one place a row becomes a URL. Nothing reaches
 * for a local path.
 *
 * So this drives the whole life of a photograph - stored, served, deleted -
 * against the bucket disk rather than the local one, and asserts the behaviour
 * is identical. It does not talk to Google: `Storage::fake()` replaces whatever
 * disk it is given, which is exactly the point being made.
 *
 * **Rows from before the move keep answering.** A marketplace that changed disk
 * still has to serve everything uploaded before it did, which is why the column
 * exists, and the last test here is the one that would fail if a config value
 * were ever read instead of the row.
 */
final class ProductImageDiskTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        // What production writes to, faked. The local disk stays configured,
        // because rows written before the move still point at it.
        Storage::fake('products_bucket');
        Storage::fake('products');

        config(['images.disk' => 'products_bucket']);

        $this->seller = User::factory()->create();
        $shop = Seller::factory()->for($this->seller)->approved()->create();
        $this->product = Product::factory()->for($shop, 'seller')->withVariant()->published()->create();
    }

    public function test_a_photograph_is_written_to_the_configured_disk_and_the_row_records_it(): void
    {
        $this->upload()->assertCreated();

        $image = ProductImage::query()->firstOrFail();

        $this->assertSame('products_bucket', $image->disk);

        Storage::disk('products_bucket')->assertExists($image->path);
        Storage::disk('products')->assertMissing($image->path);
    }

    /** The same bytes, over the same route, from a bucket rather than a folder. */
    public function test_it_is_served_by_the_same_route(): void
    {
        $this->upload()->assertCreated();

        $image = ProductImage::query()->firstOrFail();

        $response = $this->get("/api/v1/images/{$image->uuid}");

        $response->assertOk()->assertHeader('Content-Type', 'image/webp');

        $this->assertSame('RIFF', substr($response->streamedContent(), 0, 4));
    }

    public function test_deleting_it_removes_it_from_the_disk_it_was_written_to(): void
    {
        $this->upload()->assertCreated();

        $image = ProductImage::query()->firstOrFail();

        $this->actingAs($this->seller)
            ->fromFrontend()
            ->deleteJson("/api/v1/seller/products/{$this->product->id}/images/{$image->uuid}")
            ->assertNoContent();

        Storage::disk('products_bucket')->assertMissing($image->path);
    }

    /**
     * The move is additive, and this is the test that says so. A row written
     * before it keeps its own disk and is still served, which a config value
     * read at serving time could not do.
     */
    public function test_a_row_from_before_the_move_is_still_served_from_its_own_disk(): void
    {
        Storage::disk('products')->put('legacy/old.webp', 'RIFF0000WEBPold');

        $image = new ProductImage;

        $image->forceFill([
            'uuid' => (string) Str::uuid(),
            'product_id' => $this->product->id,
            'disk' => 'products',
            'path' => 'legacy/old.webp',
            'width' => 10,
            'height' => 10,
            'byte_size' => 15,
            'position' => 0,
        ])->save();

        $response = $this->get("/api/v1/images/{$image->uuid}");

        $response->assertOk();
        $this->assertSame('RIFF0000WEBPold', $response->streamedContent());
    }

    /** @return TestResponse<Response> */
    private function upload(): TestResponse
    {
        return $this->actingAs($this->seller)
            ->fromFrontend()
            ->post(
                "/api/v1/seller/products/{$this->product->id}/images",
                ['image' => UploadedFile::fake()->image('photo.jpg', 800, 600)],
            );
    }
}
