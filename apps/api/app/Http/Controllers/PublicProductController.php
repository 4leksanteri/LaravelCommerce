<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\PublicProductCollection;
use App\Http\Resources\PublicProductResource;
use App\Models\Seller;
use Illuminate\Http\JsonResponse;

/**
 * The storefront. No authentication.
 *
 * Every query here goes through `Seller::scopePublic()` and
 * `Product::scopePublic()`, which between them require an approved shop and a
 * published listing. Both checks are inside the query, so neither is one
 * somebody can forget - and a shop that has not been reviewed cannot put a
 * single product in front of a shopper.
 */
final class PublicProductController extends Controller
{
    public function index(string $shopSlug): PublicProductCollection
    {
        $seller = Seller::query()->public()->where('slug', $shopSlug)->firstOrFail();

        $products = $seller->products()
            ->public()
            ->with(['variants', 'images', 'seller', 'category'])
            ->orderByDesc('published_at')
            ->paginate(24);

        return new PublicProductCollection($products);
    }

    public function show(string $shopSlug, string $productSlug): JsonResponse
    {
        $seller = Seller::query()->public()->where('slug', $shopSlug)->firstOrFail();

        // 404, not 403, for a draft or a deleted listing. Saying "this product
        // is not published" would confirm that the seller has one under that
        // name, and a draft is nobody's business but the shop's.
        $product = $seller->products()
            ->public()
            ->with(['variants', 'images', 'seller', 'category'])
            ->where('slug', $productSlug)
            ->firstOrFail();

        return (new PublicProductResource($product))->response();
    }
}
