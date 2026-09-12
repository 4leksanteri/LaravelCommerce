<?php

declare(strict_types=1);

namespace App\Http\Resources;

/**
 * A page of what a shop has been paid.
 *
 * Named rather than anonymous so the generated contract says what is in it. See
 * ProductCollection for the whole reason.
 */
final class TransferCollection extends PaginatedCollection
{
    /** @var class-string */
    public $collects = TransferResource::class;
}
