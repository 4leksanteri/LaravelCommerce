<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Seller;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Requires that the caller has a shop, and resolves it once.
 *
 * Applied as `seller` to every endpoint that only makes sense for somebody who
 * sells. Products, orders and payouts will all sit behind it; today it guards
 * editing a shop.
 *
 * It answers **403**, not 404. The endpoint exists and the caller is
 * authenticated: what they lack is the standing to use it. Nothing is
 * disclosed either way, because the only account being described is their own.
 *
 * Note what this does **not** check: whether the shop is approved. That is a
 * different question with a different answer - a pending seller may still edit
 * their own shop while they wait - and it gets its own middleware on the day
 * something needs an approved shop to work. Do not quietly widen this one.
 *
 * The resolved shop is put on the request so that the controller does not look
 * it up again. `ResolvesCurrentSeller` reads it back with a type.
 */
final class RequireSellerProfile
{
    /** The request attribute the resolved shop is stored under. */
    public const string ATTRIBUTE = 'current_seller';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $seller = $user instanceof User ? $user->seller()->first() : null;

        if (! $seller instanceof Seller) {
            throw new HttpException(403, 'This account does not have a shop.');
        }

        $request->attributes->set(self::ATTRIBUTE, $seller);

        return $next($request);
    }
}
