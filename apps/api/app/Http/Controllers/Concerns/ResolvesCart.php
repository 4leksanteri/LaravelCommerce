<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Reads a shopper's cart, scoped to them by construction.
 *
 * Every lookup here starts from the authenticated user, so there is no id in
 * any of it that a caller could substitute. That is the rule ADR 0008 states as
 * "let the query carry it": a check that is part of the query cannot be
 * forgotten, and this one cannot even be written wrongly, because another
 * person's cart is never in the query to begin with.
 *
 * It is also why there is no `CartPolicy`. A policy method here would have no
 * decision to make.
 */
trait ResolvesCart
{
    /**
     * This person's cart lines, loaded for display.
     *
     * An account that has never added anything has no cart row, and that is not
     * an error or an empty-state to special-case: their cart is empty, which is
     * a perfectly good cart. Nothing is created by reading.
     *
     * @return Collection<int, CartItem>
     */
    protected function cartLines(User $user): Collection
    {
        $cart = $user->cart;

        if (! $cart instanceof Cart) {
            /** @var Collection<int, CartItem> $empty */
            $empty = new Collection;

            return $empty;
        }

        return $cart->lines();
    }

    /**
     * One line of this person's cart.
     *
     * Resolved through the cart rather than by id, so somebody else's line is
     * a **404** - not a 403, which would confirm that the id names something.
     * There is deliberately no route model binding on these routes: implicit
     * binding resolves globally, and `{item}` would then be any line in the
     * database.
     *
     * @throws ModelNotFoundException<CartItem>
     */
    protected function cartLine(User $user, string $id): CartItem
    {
        $cart = $user->cart;

        if (! $cart instanceof Cart) {
            throw (new ModelNotFoundException)->setModel(CartItem::class, [$id]);
        }

        return $cart->items()->findOrFail((int) $id);
    }
}
