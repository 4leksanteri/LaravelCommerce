<?php

declare(strict_types=1);

namespace App\Actions\Products;

use App\Exceptions\TooManyProductImagesException;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Exceptions\DecoderException;
use Intervention\Image\Interfaces\ImageManagerInterface;

/**
 * Takes whatever a seller uploaded and stores exactly one thing: a WebP.
 *
 * **The original is never kept**, and that is four decisions at once:
 *
 * - **One format.** Every consumer gets WebP, so nothing downstream has to
 *   branch on what a seller happened to have.
 * - **A size cap.** A twelve megabyte photograph off a phone becomes a couple
 *   of hundred kilobytes, which is the difference between a catalogue that
 *   loads and one that does not.
 * - **Orientation is applied, not described.** A phone photograph is landscape
 *   bytes plus an EXIF tag saying "rotate". Anything that ignores the tag shows
 *   it on its side, so it is baked in here and the tag discarded.
 * - **EXIF is stripped, and this one matters.** A photograph taken on a phone
 *   carries GPS coordinates. A seller listing something from their kitchen
 *   table would otherwise publish their home address with it.
 *
 * Re-encoding is not made redundant by Next's image optimiser. That derives
 * display sizes from a source; this decides what the source is.
 */
final class StoreProductImage
{
    public function __construct(private readonly ImageManagerInterface $images) {}

    /**
     * @throws TooManyProductImagesException
     */
    public function handle(Product $product, UploadedFile $file, ?string $altText = null): ProductImage
    {
        return DB::transaction(function () use ($product, $file, $altText): ProductImage {
            // Locked, so two uploads at once cannot both see the last free slot
            // and both take it.
            Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            $limit = (int) config('images.max_per_product');
            $existing = $product->images()->count();

            if ($existing >= $limit) {
                throw TooManyProductImagesException::limitOf($limit);
            }

            /*
             * Belt to the form request's braces.
             *
             * `mimes` resolves the type from the file's own bytes, so junk
             * named `.jpg` is already refused. What it cannot catch is a file
             * whose header is a real JPEG and whose body is truncated - that
             * passes validation and dies in here. A 500 for a half-uploaded
             * photograph is a bug report; a 422 is an answer.
             */
            try {
                $image = $this->images->decodePath($file->getRealPath());
            } catch (DecoderException $exception) {
                throw ValidationException::withMessages([
                    'image' => 'That file could not be read as an image. It may be damaged or incomplete.',
                ]);
            }

            // Applies the EXIF rotation rather than describing it. A phone
            // photograph is landscape bytes plus a tag saying "turn this", and
            // anything that ignores the tag renders it on its side.
            $image->orient();

            // Scales down and never up. A small photograph stays its own size
            // rather than being blown up into a blurry large one.
            $image->scaleDown(
                width: (int) config('images.max_edge'),
                height: (int) config('images.max_edge'),
            );

            // `strip: true` discards the metadata, and it is the setting that
            // matters most here. A photograph taken on a phone carries GPS
            // coordinates; a seller listing something from their kitchen table
            // would otherwise publish their home address with it.
            $encoded = $image->encode(new WebpEncoder(
                quality: (int) config('images.quality'),
                strip: true,
            ));

            $disk = (string) config('images.disk');
            $uuid = (string) Str::uuid();
            $path = "{$product->id}/{$uuid}.webp";

            Storage::disk($disk)->put($path, (string) $encoded);

            $stored = new ProductImage;

            $stored->forceFill([
                'uuid' => $uuid,
                'product_id' => $product->id,
                'disk' => $disk,
                'path' => $path,
                'width' => $image->width(),
                'height' => $image->height(),
                'byte_size' => strlen((string) $encoded),
                'alt_text' => $altText,
                // Appended, so uploading a fifth photograph does not become the
                // one shown in a grid.
                'position' => $existing,
            ])->save();

            return $stored;
        });
    }
}
