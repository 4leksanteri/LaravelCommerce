<?php

declare(strict_types=1);

namespace App\Http\Requests\Products;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateProductRequest extends FormRequest
{
    /**
     * `sometimes` throughout, because this is a PATCH: an absent field is left
     * alone rather than cleared.
     *
     * No `slug`, no `status`, no `price_minor`, no `stock`. The first two are
     * not the seller's to set directly; the last two belong to variants and
     * have their own endpoints.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
