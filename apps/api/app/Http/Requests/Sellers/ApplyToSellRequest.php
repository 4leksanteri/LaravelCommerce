<?php

declare(strict_types=1);

namespace App\Http\Requests\Sellers;

use App\Enums\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ApplyToSellRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'shop_name' => ['required', 'string', 'min:2', 'max:120'],

            'description' => ['nullable', 'string', 'max:2000'],

            'contact_email' => ['required', 'string', 'email:rfc', 'max:255'],

            // Validated against the enum, so an unsupported code is a field
            // error rather than a Currency::from() throwing further in. The
            // database has the same set as a CHECK constraint; this is the
            // layer that produces a readable message.
            'currency' => ['required', 'string', Rule::enum(Currency::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('contact_email'))) {
            $this->merge([
                'contact_email' => mb_strtolower(trim($this->string('contact_email')->toString())),
            ]);
        }

        if (is_string($this->input('currency'))) {
            // ISO 4217 codes are upper case. Accepting "eur" and storing "EUR"
            // is a kindness that costs one line.
            $this->merge(['currency' => mb_strtoupper(trim($this->string('currency')->toString()))]);
        }
    }
}
