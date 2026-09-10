<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Checkout was refused, and nothing was written.
 *
 * **409, for the reason ADR 0008 gives**: the caller is entitled to check out
 * and sent nothing invalid - a cart is not a payload - and what is in the way
 * is the state of the catalogue. It is not a 422, because there is no field to
 * put an error beside.
 *
 * `$items` names the lines that blocked it, so the frontend can mark them in
 * place rather than showing "something went wrong" over a cart of nine things.
 * It is empty when the cart itself is.
 */
final class CheckoutBlockedException extends RuntimeException
{
    /**
     * @param  list<array{id: int, product_name: string, variant_name: string, availability: string, available: int|null}>  $items
     */
    private function __construct(string $message, public readonly array $items)
    {
        parent::__construct($message);
    }

    public static function emptyCart(): self
    {
        return new self('There is nothing in your cart.', []);
    }

    /**
     * @param  list<array{id: int, product_name: string, variant_name: string, availability: string, available: int|null}>  $items
     */
    public static function unavailable(array $items): self
    {
        return new self(
            'Some of these can no longer be bought. Nothing has been ordered.',
            $items,
        );
    }
}
