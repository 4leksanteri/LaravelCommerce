<?php

declare(strict_types=1);

namespace App\Http\Resources;

/**
 * A page of a listing's reviews.
 *
 * Named rather than anonymous so the generated contract says what is in it. See
 * ProductCollection for the whole reason.
 */
final class ReviewCollection extends PaginatedCollection
{
    /** @var class-string */
    public $collects = ReviewResource::class;
}
