<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Orders.
 *
 * Named rather than anonymous so the generated contract says what is in it. See
 * ProductCollection for the whole reason - an anonymous collection is published
 * to the frontend as an array of strings.
 *
 * Used both for a buyer's order history and for the set checkout just created,
 * which are the same shape and deliberately so: the response to placing orders
 * is the orders, and a client needs no second request to render them.
 *
 * There is no total across these. Each one is in its own shop's currency.
 */
final class OrderCollection extends ResourceCollection
{
    /** @var class-string */
    public $collects = OrderResource::class;
}
