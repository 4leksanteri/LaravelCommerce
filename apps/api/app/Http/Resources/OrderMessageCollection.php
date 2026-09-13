<?php

declare(strict_types=1);

namespace App\Http\Resources;

/**
 * A page of one order's conversation.
 *
 * Named rather than anonymous so the generated contract says what is in it. See
 * ProductCollection for the whole reason.
 */
final class OrderMessageCollection extends PaginatedCollection
{
    /** @var class-string */
    public $collects = OrderMessageResource::class;
}
