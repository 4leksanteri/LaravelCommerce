<?php

declare(strict_types=1);

namespace App\Http\Requests\Products;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateProductImageRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'position' => ['sometimes', 'integer', 'min:0', 'max:'.(int) config('images.max_per_product')],

            // Nullable rather than absent-or-string: clearing alternative text
            // is a thing somebody may legitimately want to do.
            'alt_text' => ['sometimes', 'nullable', 'string', 'max:200'],
        ];
    }
}
