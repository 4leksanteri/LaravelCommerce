<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * The orders one checkout just created. One per shop in the basket.
 *
 * **Deliberately not paginated, and that is the whole reason it is a separate
 * class from OrderCollection.** This is not a window onto a list that continues
 * somewhere: it is every order that came out of the button the buyer pressed,
 * and a client that received half of them and had to ask for the rest would
 * have no way to show what was bought. A basket spans a handful of shops, so
 * there is no size for a page to protect against.
 *
 * Sharing OrderCollection published a `meta` the response does not have.
 *
 * The response to placing orders is the orders, so nothing needs a second
 * request to render a confirmation. There is no total across them - each is in
 * its own shop's currency (ADR 0011).
 */
final class PlacedOrderCollection extends ResourceCollection
{
    /** @var class-string */
    public $collects = OrderResource::class;
}
