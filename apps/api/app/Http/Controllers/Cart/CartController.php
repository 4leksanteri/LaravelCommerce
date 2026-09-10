<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cart;

use App\Http\Controllers\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Concerns\ResolvesCart;
use App\Http\Controllers\Controller;
use App\Http\Resources\CartResource;
use App\Models\Cart;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in shopper's cart.
 *
 * A singleton - one cart per account (ADR 0010) - so there is no id in the
 * path, and no id anywhere for a caller to substitute for somebody else's.
 *
 * `GET` never creates a row. A cart nobody has put anything in is renderable
 * without existing, and a read that writes would insert one per visit.
 */
final class CartController extends Controller
{
    use ResolvesAuthenticatedUser;
    use ResolvesCart;

    public function show(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);

        return (new CartResource($this->cartLines($user)))->response();
    }

    /**
     * Empties the cart, keeping it.
     *
     * Idempotent, and answers 200 with the emptied cart rather than 204. Every
     * cart mutation returns the whole cart for the same reason: the totals and
     * the availability of every remaining line are recomputed by any change, so
     * a client that got 204 would have to immediately fetch what it just
     * changed.
     */
    public function destroy(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $cart = $user->cart;

        if ($cart instanceof Cart) {
            $cart->items()->delete();
            $cart->touch();
        }

        return (new CartResource($this->cartLines($user)))->response();
    }
}
