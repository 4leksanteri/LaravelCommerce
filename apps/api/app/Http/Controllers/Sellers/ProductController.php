<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sellers;

use App\Actions\Products\CreateProduct;
use App\Actions\Products\UpdateProductDetails;
use App\Enums\ProductStatus;
use App\Http\Controllers\Concerns\ResolvesCurrentSeller;
use App\Http\Controllers\Controller;
use App\Http\Requests\Products\ListShopProductsRequest;
use App\Http\Requests\Products\StoreProductRequest;
use App\Http\Requests\Products\UpdateProductRequest;
use App\Http\Resources\PaginatedCollection;
use App\Http\Resources\ProductCollection;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Dedoc\Scramble\Attributes\QueryParameter;
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

    #[QueryParameter('page', PaginatedCollection::PAGE_PARAMETER, type: 'int', default: 1)]
    public function index(ListShopProductsRequest $request): ProductCollection
    {
        $products = $this->currentSeller($request)
            ->products()
            ->with(['variants', 'images', 'category'])
            ->latest('id');

        // Drafts, or what is on sale. The same narrowing the shop's order queue
        // and the review queue have (ADR 0038).
        $status = $request->status();

        if ($status instanceof ProductStatus) {
            $products->where('status', $status);
        }

        return new ProductCollection($products->paginate(25));
    }

    public function store(StoreProductRequest $request, CreateProduct $create): JsonResponse
    {
        /** @var list<array{name: string, price_minor: int, stock?: int}> $variants */
        $variants = $request->validated('variants');

        /** @var array{name: string, description?: string|null, category_id?: int|null} $attributes */
        $attributes = $request->safe()->only(['name', 'description', 'category_id']);

        $product = $create->handle($this->currentSeller($request), $attributes, $variants);

        return (new ProductResource($product))->response()->setStatusCode(201);
    }

    public function show(Request $request, Product $product): JsonResponse
    {
        $this->authorize('update', $product);

        return (new ProductResource($product->load(['variants', 'images', 'category'])))->response();
    }

    public function update(UpdateProductRequest $request, Product $product, UpdateProductDetails $update): JsonResponse
    {
        $this->authorize('update', $product);

        return (new ProductResource(
            $update->handle($product, $request->safe()->only(['name', 'description', 'category_id']))
                ->load(['variants', 'images', 'category'])
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
