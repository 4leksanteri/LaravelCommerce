<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sellers;

use App\Actions\Products\PublishProduct;
use App\Actions\Products\UnpublishProduct;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;

/**
 * Putting a listing on sale, and taking it off.
 *
 * Its own resource rather than a `status` field on PATCH, for the reason
 * ADR 0008 gives about the review endpoints: a status a client can set is a
 * status a client can set to anything, and publishing has a precondition that
 * editing a description does not.
 *
 * POST creates the publication; DELETE removes it. The listing survives both.
 */
final class ProductPublicationController extends Controller
{
    public function store(Product $product, PublishProduct $publish): JsonResponse
    {
        $this->authorize('publish', $product);

        // An unapproved shop raises ProductNotPublishableException, which
        // bootstrap/app.php renders as 409. Not caught here: a controller that
        // catches a domain exception to rethrow it as HTTP is doing the
        // handler's job.
        return (new ProductResource($publish->handle($product)->load('variants')))->response();
    }

    public function destroy(Product $product, UnpublishProduct $unpublish): JsonResponse
    {
        $this->authorize('publish', $product);

        return (new ProductResource($unpublish->handle($product)->load('variants')))->response();
    }
}
