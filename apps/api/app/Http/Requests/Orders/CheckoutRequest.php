<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The first request body checkout has ever taken.
 *
 * ADR 0011 said checkout takes none, and the claim it actually made was
 * narrower and still holds: **nothing a client sends contributes a figure to
 * what somebody is charged.** An address is not a figure. The cart, the prices
 * and the totals are still read from the server under lock, and there is still
 * no field here that could change one.
 */
final class CheckoutRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            /*
             * An id from the buyer's own address book rather than the address
             * itself, so there is one path that creates an address and one
             * shape it can be in. A checkout that could invent one inline would
             * be a second, less validated way to make the same row.
             *
             * There is no `exists` rule: ownership is what matters, not
             * existence, and `PlaceOrders` resolves it through the buyer's own
             * relation. Somebody else's id and a made-up one get the same
             * answer, which is the point.
             */
            'address_id' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'address_id.required' => 'Choose where this should be sent.',
        ];
    }
}
