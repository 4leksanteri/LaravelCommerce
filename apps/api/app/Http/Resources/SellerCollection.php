<?php

declare(strict_types=1);

namespace App\Http\Resources;

/**
 * A page of shops, for the review queue.
 *
 * Named rather than anonymous so the generated contract says what is in it.
 * See ProductCollection for the whole reason.
 */
final class SellerCollection extends PaginatedCollection
{
    /** @var class-string */
    public $collects = SellerResource::class;
}
