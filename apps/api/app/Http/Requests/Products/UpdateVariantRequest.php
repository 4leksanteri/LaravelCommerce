<?php

declare(strict_types=1);

namespace App\Http\Requests\Products;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateVariantRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $product = $this->route('product');
        $variant = $this->route('variant');

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('product_variants', 'name')
                    ->where(fn ($query) => $query->where(
                        'product_id',
                        $product instanceof Product ? $product->id : null,
                    ))
                    // Ignoring itself, or renaming a variant to the name it
                    // already has would collide with its own row.
                    ->ignore($variant instanceof ProductVariant ? $variant->id : null),
            ],
            'price_minor' => ['sometimes', 'required', 'integer', 'min:0'],
            'stock' => ['sometimes', 'required', 'integer', 'min:0'],
            'position' => ['sometimes', 'required', 'integer', 'min:0'],
        ];
    }
}
