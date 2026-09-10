<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sellers;

use App\Actions\Products\RemoveProductVariant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Products\StoreVariantRequest;
use App\Http\Requests\Products\UpdateVariantRequest;
use App\Http\Resources\ProductVariantResource;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * The ways a product can be bought: price and stock live here and nowhere
 * else.
 *
 * Nested under the product, and the routes use `scopeBindings()`. That is not
 * cosmetic: without it, `{variant}` resolves globally and a seller could edit
 * another shop's variant by putting its id after their own product's path. The
 * binding makes the variant belong to the product before the controller runs.
 *
 * Authorization is asked against the **product**, because a variant has no
 * owner of its own - it belongs to whoever the product belongs to.
 */
final class ProductVariantController extends Controller
{
    public function store(StoreVariantRequest $request, Product $product): JsonResponse
    {
        $this->authorize('update', $product);

        $variant = $product->variants()->create([
            'name' => $request->string('name')->toString(),
            'price_minor' => $request->integer('price_minor'),
            'stock' => $request->integer('stock', 0),
            // Appended by default, so adding "XL" puts it after "L" rather
            // than at the front.
            'position' => $request->integer('position', $product->variants()->count()),
        ]);

        return (new ProductVariantResource($variant))->response()->setStatusCode(201);
    }

    public function update(UpdateVariantRequest $request, Product $product, ProductVariant $variant): JsonResponse
    {
        $this->authorize('update', $product);

        $variant->fill($request->safe()->only(['name', 'price_minor', 'stock', 'position']))->save();

        return (new ProductVariantResource($variant))->response();
    }

    /**
     * Removing the last variant raises CannotRemoveLastVariantException, which
     * renders as 409: a product with no variants has no price and cannot be
     * bought, which is not a state anything downstream is written to handle.
     */
    public function destroy(Product $product, ProductVariant $variant, RemoveProductVariant $remove): Response
    {
        $this->authorize('update', $product);

        $remove->handle($variant);

        return response()->noContent();
    }
}
