<?php

declare(strict_types=1);

namespace App\Http\Requests\Cart;

use Illuminate\Foundation\Http\FormRequest;

final class SetCartItemQuantityRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            /*
             * `min:1`, so zero is a 422 rather than a quiet delete. A quantity
             * of nothing is not a quantity, and `DELETE /cart/items/{item}`
             * already removes a line - two ways to do one thing is how they
             * end up behaving differently.
             */
            'quantity' => ['required', 'integer', 'min:1', 'max:'.AddCartItemRequest::MAX_QUANTITY],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quantity.min' => 'To remove this, delete the line rather than setting it to zero.',
        ];
    }
}
