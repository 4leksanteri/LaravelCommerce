<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Http\Middleware\RequireSellerProfile;
use App\Models\Seller;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Reads back the shop the `seller` middleware resolved.
 *
 * The middleware has already refused the request if there is no shop, so this
 * cannot fail in practice. The check is here to give the value a type rather
 * than to guard anything - and it fails loudly if a route is ever given this
 * trait's controller without the middleware, which is the mistake worth
 * catching.
 */
trait ResolvesCurrentSeller
{
    protected function currentSeller(Request $request): Seller
    {
        $seller = $request->attributes->get(RequireSellerProfile::ATTRIBUTE);

        if (! $seller instanceof Seller) {
            throw new RuntimeException(
                'No current seller on the request. This route is missing the `seller` middleware.',
            );
        }

        return $seller;
    }
}
