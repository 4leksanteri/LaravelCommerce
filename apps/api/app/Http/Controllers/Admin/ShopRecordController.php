<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaginatedCollection;
use App\Http\Resources\PlatformDecisionCollection;
use App\Models\Dispute;
use App\Models\PlatformDecision;
use App\Models\Product;
use App\Models\Seller;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\Request;

/**
 * What the platform has decided about one shop (ADR 0060).
 *
 * **Newest first, which is the opposite of every queue here.** A queue is work
 * to do and the longest wait goes first; this is a record, read by somebody
 * about to decide something, and what happened most recently matters most.
 *
 * **Its own controller rather than another method on `SellerReviewController`,**
 * which is the review queue and its two decisions. Reading a shop's history is
 * not reviewing an application, and the policy question is different: `view`
 * rather than `review`.
 *
 * There is no endpoint that writes one. Rows are written by the actions that
 * take the decisions, inside their own transactions, and nothing edits or
 * deletes one - a record somebody can revise is not a record.
 */
final class ShopRecordController extends Controller
{
    #[QueryParameter('page', PaginatedCollection::PAGE_PARAMETER, type: 'int', default: 1)]
    public function index(Request $request, Seller $seller): PlatformDecisionCollection
    {
        $this->authorize('view', $seller);

        $decisions = PlatformDecision::query()
            ->where('seller_id', $seller->id)
            ->orderByDesc('id')
            ->paginate(25);

        /*
         * The resource summarises each subject and reaches for the shop to
         * build a listing's link, and for the order to name a dispute. Left to
         * lazy loading those fire per row.
         *
         * A MorphTo cannot be loaded through in one query, so the nested
         * relations are named per type. Reached through `getCollection()`
         * because the paginator only forwards `loadMorph` by `__call`, which
         * the analyser cannot follow.
         */
        $decisions->getCollection()->loadMorph('subject', [
            Seller::class => [],
            Product::class => ['seller'],
            Dispute::class => ['order'],
        ]);

        return new PlatformDecisionCollection($decisions);
    }
}
