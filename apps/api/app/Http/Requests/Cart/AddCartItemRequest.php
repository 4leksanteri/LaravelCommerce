<?php

declare(strict_types=1);

namespace App\Http\Requests\Cart;

use Illuminate\Foundation\Http\FormRequest;

final class AddCartItemRequest extends FormRequest
{
    /**
     * A sanity bound, not the stock check.
     *
     * Stock is what actually limits a line and it is checked in the action,
     * against the variant, at the moment of the write. This only stops a
     * payload asking for two billion of something from reaching that check as
     * an arithmetic problem.
     */
    public const int MAX_QUANTITY = 999;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            /*
             * A variant, never a product. A listing with two sizes has two
             * prices and two stock counts, so "this product, quantity 2" does
             * not name anything that can be bought (ADR 0009).
             *
             * There is deliberately **no `exists` rule**. It would answer 422
             * for an id that does not exist while an unpublished one answers
             * 404 from the action, and the difference between the two would
             * tell somebody guessing which ids are real. One answer for both:
             * not found.
             */
            'variant_id' => ['required', 'integer', 'min:1'],

            'quantity' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_QUANTITY],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quantity.min' => 'Add at least one.',
        ];
    }
}
