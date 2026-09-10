<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\CategoryCollection;
use App\Http\Resources\PublicProductCollection;
use App\Models\Category;
use App\Models\Product;

/**
 * Browsing the marketplace by what things are.
 *
 * Public, unauthenticated, and the first endpoints that answer a question
 * without already knowing which shop is being asked about. Everything else
 * public needs a shop slug; a shopper arriving at the front door has none.
 *
 * There is no endpoint here that writes a category. Staff own the list and
 * there is no admin panel yet - they come from `CategorySeeder` (ADR 0017).
 */
final class CategoryController extends Controller
{
    /**
     * The whole navigation, as a tree.
     *
     * Two levels deep and eager-loaded, so this is two queries rather than one
     * per branch. Not paginated: a navigation that arrives a page at a time is
     * not a navigation.
     */
    public function index(): CategoryCollection
    {
        return new CategoryCollection(
            Category::query()->roots()->with('children')->get(),
        );
    }

    /**
     * Published listings in a category, across every approved shop.
     *
     * **Including everything underneath it.** Somebody browsing "Food" expects
     * the bread as well, and a parent category whose own page is empty because
     * all the listings hang off its children is a navigation that punishes
     * using it.
     *
     * `Product::scopePublic()` still decides what a shopper may see, so an
     * unapproved shop's listings are absent here exactly as they are on its own
     * storefront.
     */
    public function products(Category $category): PublicProductCollection
    {
        $products = Product::query()
            ->public()
            ->whereIn('category_id', $category->withDescendantIds())
            ->with(['variants', 'images', 'seller', 'category'])
            ->orderByDesc('published_at')
            ->paginate(24);

        return new PublicProductCollection($products);
    }
}
