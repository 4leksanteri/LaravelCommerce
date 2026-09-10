<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\PublicShopResource;
use App\Models\Seller;
use Illuminate\Http\JsonResponse;

/**
 * A shop as the marketplace shows it. No authentication.
 *
 * This is what approval is *for*: the `public()` scope is the only thing
 * standing between a pending application and the open internet, which is why
 * the lookup goes through it rather than fetching by slug and checking the
 * status afterwards. The check that is part of the query cannot be forgotten.
 */
final class PublicShopController extends Controller
{
    public function __invoke(string $slug): JsonResponse
    {
        // 404, not 403, for a shop that exists but is not approved. Saying
        // "this shop is pending review" would tell anybody who guessed a slug
        // that somebody applied under it, and an application is not public
        // information.
        $seller = Seller::query()->public()->where('slug', $slug)->firstOrFail();

        return (new PublicShopResource($seller))->response();
    }
}
