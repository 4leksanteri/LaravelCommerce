<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sellers;

use App\Http\Controllers\Concerns\ResolvesCurrentSeller;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaginatedCollection;
use App\Http\Resources\TransferCollection;
use App\Models\Order;
use App\Models\Payment;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\Request;

/**
 * What this shop has actually been paid (ADR 0043).
 *
 * The payouts page had the account and nothing that came through it: a seller
 * could see Stripe was ready to receive money and had no way to see any
 * arriving. This is that list.
 *
 * **Only transfers that happened.** A payment being held is not a payout and
 * is not in here - it is on the order it belongs to, where a seller can see
 * why it has not moved yet.
 */
final class SellerTransferController extends Controller
{
    use ResolvesCurrentSeller;

    #[QueryParameter('page', PaginatedCollection::PAGE_PARAMETER, type: 'int', default: 1)]
    public function index(Request $request): TransferCollection
    {
        $sellerId = $this->currentSeller($request)->id;

        /*
         * Started from the model rather than through a relation, for the reason
         * `SellerOrderController` gives at length: a scope or a filter reached
         * through `__call` is something the OpenAPI generator cannot follow,
         * and the endpoint gets published without its `meta`.
         *
         * The shop is reached through the order because that is where the
         * ownership lives - a payment belongs to an order, and an order belongs
         * to a shop - so another shop's payouts are not refused, they are never
         * in the query (ADR 0008).
         *
         * A subquery rather than `whereHas`, and not for performance: inside
         * `whereHas` the closure receives a `Builder<Model>`, which the analyser
         * cannot check `seller_id` against. Starting from `Order::query()` gives
         * both halves a model to be checked against.
         */
        $transfers = Payment::query()
            ->whereNotNull('transferred_at')
            ->whereIn('order_id', Order::query()->where('seller_id', $sellerId)->select('id'))
            ->with('order')
            ->latest('transferred_at')
            ->paginate(20);

        return new TransferCollection($transfers);
    }
}
