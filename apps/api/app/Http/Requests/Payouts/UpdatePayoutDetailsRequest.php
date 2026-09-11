<?php

declare(strict_types=1);

namespace App\Http\Requests\Payouts;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * What a seller tells Stripe about themselves, through this API.
 *
 * PATCH: every field is optional, and one that is absent is left as Stripe has
 * it. Which of them Stripe still needs is the resource's `due`, and the names
 * here are PayoutField's, so a field in `due` is a key in this body.
 *
 * Validation is about shape. Whether a name, an ID number or an IBAN is real is
 * Stripe's to decide, and Stripe says so beside the field (UpdatePayoutDetails).
 */
final class UpdatePayoutDetailsRequest extends FormRequest
{
    private const array FIELDS = [
        'first_name', 'last_name', 'email', 'phone', 'date_of_birth', 'address', 'id_number', 'iban', 'terms',
    ];

    /**
     * An IBAN is printed in groups of four and typed however somebody likes.
     * Spaces and case are presentation, so they come out before the shape is
     * checked rather than being blamed on whoever copied it from their bank.
     */
    protected function prepareForValidation(): void
    {
        $iban = $this->input('iban');

        if (is_string($iban)) {
            $this->merge(['iban' => strtoupper((string) preg_replace('/\s+/', '', $iban))]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'required', 'string', 'max:100'],
            'last_name' => ['sometimes', 'required', 'string', 'max:100'],
            'email' => ['sometimes', 'required', 'email', 'max:254'],

            // Stripe checks the format, country by country, rather than a
            // pattern here that would be right for some of them.
            'phone' => ['sometimes', 'required', 'string', 'max:32'],

            'date_of_birth' => ['sometimes', 'required', 'date_format:Y-m-d', 'before:today'],

            // The seller's home rather than the shop's, in the address book's
            // shape and optional where that is, for the same reasons (ADR 0021).
            // No country: it is the account's.
            'address' => ['sometimes', 'required', 'array:line1,line2,city,postal_code,state'],
            'address.line1' => ['required_with:address', 'string', 'max:200'],
            'address.line2' => ['nullable', 'string', 'max:200'],
            'address.city' => ['required_with:address', 'string', 'max:120'],
            'address.postal_code' => ['nullable', 'string', 'max:20'],
            'address.state' => ['nullable', 'string', 'max:120'],

            'id_number' => ['sometimes', 'required', 'string', 'max:64'],

            // The shape of an IBAN and nothing more. Whether its check digits
            // add up and the bank exists is Stripe's to say.
            'iban' => ['sometimes', 'required', 'string', 'regex:/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/'],

            // Stripe's terms again, when Stripe has changed them and asks.
            'terms' => ['sometimes', 'accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'iban.regex' => 'That does not look like an IBAN. One starts with two letters and two digits, like FI21 1234 5600 0007 85.',
        ];
    }

    /**
     * An empty PATCH is refused rather than sent to Stripe as a request that
     * changes nothing.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->hasAny(self::FIELDS)) {
                    $validator->errors()->add('details', 'Send at least one detail.');
                }
            },
        ];
    }
}
