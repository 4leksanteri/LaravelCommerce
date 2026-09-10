<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sellers;

use App\Actions\Products\CreateProduct;
use App\Actions\Products\UpdateProductDetails;
use App\Http\Controllers\Concerns\ResolvesCurrentSeller;
use App\Http\Controllers\Controller;
use App\Http\Requests\Products\StoreProductRequest;
use App\Http\Requests\Products\UpdateProductRequest;
use App\Http\Resources\ProductCollection;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A seller's own catalogue.
 *
 * Behind `seller`, so the caller has a shop and the controller reads it back
 * without a lookup.
 *
 * The listing is scoped by the query - `$seller->products()` - so another
 * shop's products are never in it. The single-product methods take a
 * route-bound model and ask `ProductPolicy`, which answers **403** for
 * somebody else's product.
 *
 * 403 rather than 404 is a deliberate choice, and it does disclose that an id
 * exists. That is acceptable here: almost every product is public anyway, and
 * one mechanism for ownership across the whole API is worth more than hiding
 * which integers are taken. See ADR 0008.
 */
final class ProductController extends Controller
{
    use ResolvesCurrentSeller;

    public function index(Request $request): ProductCollection
    {
        $products = $this->currentSeller($request)
            ->products()
            ->with('variants')
            ->latest('id')
            ->paginate(25);

        return new ProductCollection($products);
    }

    public function store(StoreProductRequest $request, CreateProduct $create): JsonResponse
    {
        /** @var list<array{name: string, price_minor: int, stock?: int}> $variants */
        $variants = $request->validated('variants');

        /** @var array{name: string, description?: string|null} $attributes */
        $attributes = $request->safe()->only(['name', 'description']);

        $product = $create->handle($this->currentSeller($request), $attributes, $variants);

        return (new ProductResource($product))->response()->setStatusCode(201);
    }

    public function show(Request $request, Product $product): JsonResponse
    {
        $this->authorize('update', $product);

        return (new ProductResource($product->load('variants')))->response();
    }

    public function update(UpdateProductRequest $request, Product $product, UpdateProductDetails $update): JsonResponse
    {
        $this->authorize('update', $product);

        return (new ProductResource(
            $update->handle($product, $request->safe()->only(['name', 'description']))->load('variants')
        ))->response();
    }

    /**
     * A soft delete. The row stays, so the order history that will eventually
     * reference it keeps something to point at, and a shopper stops seeing it
     * immediately because every public query excludes trashed rows.
     */
    public function destroy(Request $request, Product $product): Response
    {
        $this->authorize('delete', $product);

        $product->delete();

        return response()->noContent();
    }
}
