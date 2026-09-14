<?php

declare(strict_types=1);

namespace App\Http\Resources;

/**
 * A page of the platform's moderation queue.
 *
 * Named rather than anonymous so the generated contract says what is in it. See
 * ProductCollection for the whole reason.
 */
final class ReportCollection extends PaginatedCollection
{
    /** @var class-string */
    public $collects = ReportResource::class;
}
