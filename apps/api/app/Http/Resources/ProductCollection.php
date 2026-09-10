<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * A page of products.
 *
 * **This class exists so that the generated API contract is correct**, which
 * is a better reason than tidiness.
 *
 * `ProductResource::collection($products)` returns Laravel's
 * `AnonymousResourceCollection` - unnamed, because there is no class to name.
 * Scramble cannot see through it, so every list endpoint was published to the
 * frontend as:
 *
 * ```json
 * { "data": { "type": "array", "items": { "type": "string" } } }
 * ```
 *
 * An array of strings. The pagination envelope was right and the contents were
 * a lie, and TypeScript believed it.
 *
 * With a named collection the same endpoint generates
 * `data: ProductResource[]`.
 *
 * So: **a list endpoint returns a named ResourceCollection.** It carries no
 * behaviour and is not meant to - if one ever needs collection-level data, a
 * total or an aggregate, this is where it goes.
 */
final class ProductCollection extends ResourceCollection
{
    /** @var class-string */
    public $collects = ProductResource::class;
}
