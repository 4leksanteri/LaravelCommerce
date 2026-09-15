<?php

declare(strict_types=1);

namespace App\Http\Resources;

/**
 * A page of one shop's record.
 *
 * Named rather than anonymous so the generated contract says what is in it. See
 * ProductCollection for the whole reason.
 */
final class PlatformDecisionCollection extends PaginatedCollection
{
    /** @var class-string */
    public $collects = PlatformDecisionResource::class;
}
