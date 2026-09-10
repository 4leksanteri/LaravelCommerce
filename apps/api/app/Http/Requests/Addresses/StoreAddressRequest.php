<?php

declare(strict_types=1);

namespace App\Http\Requests\Addresses;

use Illuminate\Foundation\Http\FormRequest;

final class StoreAddressRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // The recipient, not the account holder. People send things to
            // their partner, their office, their parents.
            'name' => ['required', 'string', 'max:120'],

            'line1' => ['required', 'string', 'max:200'],
            'line2' => ['nullable', 'string', 'max:200'],
            'city' => ['required', 'string', 'max:120'],

            /*
             * Optional, both, and this is where naive address forms go wrong.
             * Plenty of countries have no state worth recording and several
             * have no postal codes at all - Ireland had none until 2015, the
             * UAE still does not. Requiring either teaches somebody to type
             * "N/A" and puts that on a parcel.
             */
            'region' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:20'],

            /*
             * ISO 3166-1 alpha-2, upper case, which is what Stripe takes.
             *
             * It is not checked against the register - see ADR 0021 - so `ZZ`
             * gets through. The rule is here to stop "United Kingdom" and "gb ",
             * which are the mistakes that actually happen.
             */
            'country' => ['required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],

            'phone' => ['nullable', 'string', 'max:32'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'country.regex' => 'Use a two-letter country code, like FI or GB.',
            'country.size' => 'Use a two-letter country code, like FI or GB.',
        ];
    }
}
