<?php

declare(strict_types=1);

namespace App\Http\Requests\Sellers;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateShopRequest extends FormRequest
{
    /**
     * `sometimes` throughout, because this is a PATCH: a field that is absent
     * means "leave it alone", and a field that is present is validated. Using
     * `required` here would turn every partial edit into a full replacement.
     *
     * There is no `slug`, no `currency` and no `status`. All three are absent
     * on purpose - see UpdateShopDetails.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'shop_name' => ['sometimes', 'required', 'string', 'min:2', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'contact_email' => ['sometimes', 'required', 'string', 'email:rfc', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('contact_email'))) {
            $this->merge([
                'contact_email' => mb_strtolower(trim($this->string('contact_email')->toString())),
            ]);
        }
    }
}
