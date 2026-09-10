<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sellers;

use App\Actions\Products\StoreProductImage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Products\StoreProductImageRequest;
use App\Http\Requests\Products\UpdateProductImageRequest;
use App\Http\Resources\ProductImageResource;
use App\Models\Product;
use App\Models\ProductImage;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * A listing's photographs.
 *
 * Nested under the product and using `scopeBindings()`, for the reason the
 * variant routes do: without it `{image}` resolves globally and a seller could
 * delete another shop's photograph by putting its key after a path they own.
 *
 * Authorization is asked against the **product**, because an image has no owner
 * of its own - it belongs to whoever the product belongs to.
 */
final class ProductImageController extends Controller
{
    private const string CONFLICT = 'The listing already has as many images as it may have.';

    private const string CONFLICT_BODY = 'array{message: string, limit: int}';

    #[Response(status: 409, description: self::CONFLICT, type: self::CONFLICT_BODY)]
    public function store(
        StoreProductImageRequest $request,
        Product $product,
        StoreProductImage $storeImage,
    ): JsonResponse {
        $this->authorize('update', $product);

        $file = $request->file('image');

        if (! $file instanceof UploadedFile) {
            // Unreachable: the form request required it. The branch proves the
            // type rather than promising it (root CLAUDE.md section 11).
            throw new RuntimeException('A validated image upload arrived without a file.');
        }

        $image = $storeImage->handle($product, $file, $request->string('alt_text')->value() ?: null);

        return (new ProductImageResource($image))->response()->setStatusCode(201);
    }

    /**
     * Reordering, and the text a screen reader will read.
     *
     * Position is a plain integer rather than a move-up/move-down, because a
     * gallery is dragged rather than nudged and the client knows where it
     * dropped something.
     */
    public function update(
        UpdateProductImageRequest $request,
        Product $product,
        ProductImage $image,
    ): JsonResponse {
        $this->authorize('update', $product);

        $image->forceFill($request->safe()->only(['position', 'alt_text']))->save();

        return (new ProductImageResource($image))->response();
    }

    /**
     * Removes the row **and** the file.
     *
     * A hard delete, unlike a product's. Nothing references a photograph the
     * way order history references a listing, and an orphaned file on a disk
     * nobody can reach is just cost.
     */
    public function destroy(Product $product, ProductImage $image): JsonResponse
    {
        $this->authorize('update', $product);

        Storage::disk($image->disk)->delete($image->path);

        $image->delete();

        return response()->json(null, 204);
    }
}
