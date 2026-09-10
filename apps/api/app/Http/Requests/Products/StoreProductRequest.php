<?php

declare(strict_types=1);

namespace App\Http\Requests\Products;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class StoreProductRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],

            // At least one, because a product with no variant has no price and
            // cannot be bought. Requiring it here is what makes that invariant
            // true from the first row rather than eventually.
            'variants' => ['required', 'array', 'min:1', 'max:50'],
            'variants.*.name' => ['required', 'string', 'max:100'],

            // Integer minor units (ADR 0004). `integer` and not `numeric`: a
            // price of 24.99 is a caller sending major units, and accepting it
            // silently would list the product at 24 minor units.
            'variants.*.price_minor' => ['required', 'integer', 'min:0'],

            'variants.*.stock' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'variants.required' => 'A product needs at least one variant, which is what carries its price.',
            'variants.min' => 'A product needs at least one variant, which is what carries its price.',
            'variants.*.price_minor.integer' => 'Prices are whole numbers of minor units - 2499 for 24.99.',
        ];
    }

    /**
     * Two variants called "Small" would give a shopper the same choice twice.
     * Caught here rather than only by the unique index, so the seller gets a
     * field error instead of a 500.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $variants = $this->input('variants');

            if (! is_array($variants)) {
                return;
            }

            $names = array_map(
                static fn ($variant): string => is_array($variant) && is_string($variant['name'] ?? null)
                    ? mb_strtolower(trim($variant['name']))
                    : '',
                $variants,
            );

            if (count($names) !== count(array_unique($names))) {
                $validator->errors()->add('variants', 'Each variant needs a different name.');
            }
        });
    }
}
