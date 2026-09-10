<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * The navigation.
 *
 * Named rather than anonymous so the generated contract says what is in it -
 * see ProductCollection for the whole reason.
 *
 * Not paginated, and that is a decision rather than an omission: a category
 * list is a navigation, and a navigation that arrives a page at a time is not
 * one. It stays that way because staff own the list and there will not be
 * thousands.
 */
final class CategoryCollection extends ResourceCollection
{
    /** @var class-string */
    public $collects = CategoryResource::class;
}
