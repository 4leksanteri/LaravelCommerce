<?php

declare(strict_types=1);

namespace App\Http\Requests\Products;

use App\Enums\ProductStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A shop's own catalogue, narrowed to drafts or to what is on sale.
 *
 * The third queue narrowed the same way, after the shop's orders (ADR 0036)
 * and the review queue (ADR 0037): one status at a time, and a status that
 * does not exist is a 422 rather than the whole catalogue.
 */
final class ListShopProductsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::enum(ProductStatus::class)],
        ];
    }

    public function status(): ?ProductStatus
    {
        return $this->enum('status', ProductStatus::class);
    }
}
