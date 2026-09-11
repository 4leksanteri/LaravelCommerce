<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SearchRequest;
use App\Http\Resources\PaginatedCollection;
use App\Http\Resources\PublicProductCollection;
use App\Models\Category;
use App\Models\Product;
use Dedoc\Scramble\Attributes\QueryParameter;

/**
 * Looking for something across the whole marketplace.
 *
 * The one endpoint that answers without being told where to look. Everything
 * else public needs a shop slug or a category, and somebody who knows the model
 * of the camera they want has neither.
 *
 * `Product::scopePublic()` still decides what is visible, so a draft or an
 * unapproved shop's listing is as absent here as it is everywhere else. Search
 * is not a way around approval.
 *
 * It returns the same shape as a category page, on purpose: a result card and a
 * browse card show the same things, and two resources would drift.
 */
final class SearchController extends Controller
{
    #[QueryParameter('page', PaginatedCollection::PAGE_PARAMETER, type: 'int', default: 1)]
    public function __invoke(SearchRequest $request): PublicProductCollection
    {
        $term = trim((string) $request->string('q'));

        $products = Product::query()->public();

        if ($term !== '') {
            $products->matching($term);
        }

        $categorySlug = trim((string) $request->string('category'));

        if ($categorySlug !== '') {
            // Including everything underneath it, for the reason the category
            // page does: somebody narrowing to "Cameras and optics" expects the
            // lenses as well.
            $category = Category::query()->where('slug', $categorySlug)->firstOrFail();

            $products->whereIn('category_id', $category->withDescendantIds());
        }

        /*
         * Relevance when there is something to be relevant to, newest
         * otherwise. Ranking an unfiltered listing by `ts_rank` would order it
         * by a score every row shares.
         */
        if ($term !== '') {
            $products->byRelevance($term);
        }

        // Always the tiebreak, and always last. `ts_rank` produces plenty of
        // ties, and without this their order is whatever the planner returns -
        // which can differ between two requests for the same page.
        $products->orderByDesc('published_at')->orderByDesc('id');

        return new PublicProductCollection(
            $products->with(['variants', 'images', 'seller', 'category'])->paginate(24),
        );
    }
}
