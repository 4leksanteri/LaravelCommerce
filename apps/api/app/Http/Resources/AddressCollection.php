<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Somebody's address book.
 *
 * Named rather than anonymous so the generated contract says what is in it -
 * see ProductCollection for the whole reason.
 *
 * Not paginated. Nobody has a hundred addresses, and a checkout that had to page
 * through them to find the right one would be a worse checkout.
 */
final class AddressCollection extends ResourceCollection
{
    /** @var class-string */
    public $collects = AddressResource::class;
}
