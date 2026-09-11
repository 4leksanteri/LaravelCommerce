<?php

declare(strict_types=1);

namespace App\Http\Resources;

/**
 * A page of somebody's order history.
 *
 * Named rather than anonymous so the generated contract says what is in it. See
 * ProductCollection for the whole reason.
 *
 * **This used to be the checkout response as well**, on the grounds that the
 * orders a checkout just created and a page of past orders are the same shape.
 * They stopped being the same shape the day a page grew a `meta`: one is a
 * window onto a list that continues, and the other is all of it. Checkout
 * returns PlacedOrderCollection now, and the contract says which is which.
 *
 * There is no total across these. Each one is in its own shop's currency.
 */
final class OrderCollection extends PaginatedCollection
{
    /** @var class-string */
    public $collects = OrderResource::class;
}
