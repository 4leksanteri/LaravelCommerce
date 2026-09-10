<?php

declare(strict_types=1);

namespace App\Http\Requests\Products;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreVariantRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $product = $this->route('product');

        return [
            'name' => [
                'required',
                'string',
                'max:100',
                // Unique within this product, matching the table's own index.
                // Scoped by hand because the parent is in the path rather than
                // in the payload.
                Rule::unique('product_variants', 'name')->where(
                    fn ($query) => $query->where(
                        'product_id',
                        $product instanceof Product ? $product->id : null,
                    ),
                ),
            ],
            'price_minor' => ['required', 'integer', 'min:0'],
            'stock' => ['sometimes', 'integer', 'min:0'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
