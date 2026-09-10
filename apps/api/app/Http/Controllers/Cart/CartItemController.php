<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cart;

use App\Actions\Cart\AddToCart;
use App\Actions\Cart\SetCartItemQuantity;
use App\Http\Controllers\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Concerns\ResolvesCart;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cart\AddCartItemRequest;
use App\Http\Requests\Cart\SetCartItemQuantityRequest;
use App\Http\Resources\CartResource;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The lines of the signed-in shopper's cart.
 *
 * Every method answers with the **whole cart**, because every one of them
 * changes it: a subtotal is recomputed, and a line that was fine may now be
 * short of stock. Returning the affected line alone would leave the client to
 * fetch the rest, and it would render a stale total in the meantime.
 *
 * `{item}` is resolved through the caller's own cart (see `ResolvesCart`), not
 * by route model binding. Implicit binding resolves globally, and this route
 * would then hand any line in the database to whoever guessed its id.
 */
final class CartItemController extends Controller
{
    use ResolvesAuthenticatedUser;
    use ResolvesCart;

    /**
     * The 409, stated at the HTTP boundary.
     *
     * `VariantNotPurchasableException` is a domain exception and deliberately
     * knows nothing about status codes (`apps/api/CLAUDE.md` section 10), so
     * the generator cannot infer that it becomes a 409 - it is
     * `bootstrap/app.php` that decides. Without these annotations the published
     * contract says these endpoints cannot answer 409, and the frontend's
     * generated types would say so too, which is exactly the drift the whole
     * pipeline exists to prevent.
     */
    private const string CONFLICT = 'For sale, but not in the quantity asked for - or no longer for sale at all.';

    /** `available` is how many can be had, and is null when the reason is not stock. */
    private const string CONFLICT_BODY = 'array{message: string, available: int|null}';

    /**
     * Adds a variant, or adds to what is already there.
     *
     * 200 rather than 201: adding something already in the cart raises its
     * quantity instead of creating a line, so a created status would be wrong
     * about half the time. What is being changed is the cart, and the cart
     * already existed or did not depending on nothing the caller said.
     */
    #[Response(status: 409, description: self::CONFLICT, type: self::CONFLICT_BODY)]
    public function store(AddCartItemRequest $request, AddToCart $add): JsonResponse
    {
        $user = $this->authenticatedUser($request);

        $add->handle(
            user: $user,
            variantId: $request->integer('variant_id'),
            quantity: $request->integer('quantity', 1),
        );

        return (new CartResource($this->cartLines($user)))->response();
    }

    #[Response(status: 409, description: self::CONFLICT, type: self::CONFLICT_BODY)]
    public function update(
        SetCartItemQuantityRequest $request,
        string $item,
        SetCartItemQuantity $setQuantity,
    ): JsonResponse {
        $user = $this->authenticatedUser($request);

        $setQuantity->handle($this->cartLine($user, $item), $request->integer('quantity'));

        return (new CartResource($this->cartLines($user)))->response();
    }

    public function destroy(Request $request, string $item): JsonResponse
    {
        $user = $this->authenticatedUser($request);

        $line = $this->cartLine($user, $item);
        $line->delete();
        $line->cart->touch();

        return (new CartResource($this->cartLines($user)))->response();
    }
}
