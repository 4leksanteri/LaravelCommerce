<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One way of buying a product, as its seller sees it.
 */
final class ProductVariantResource extends JsonResource
{
    public function __construct(private readonly ProductVariant $variant)
    {
        parent::__construct($variant);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->variant->id,
            'name' => $this->variant->name,

            // Integer minor units, sent as an integer and never formatted
            // here. What they are worth is the currency's business, and the
            // currency is on the product (ADR 0004).
            'price_minor' => $this->variant->price_minor,

            'stock' => $this->variant->stock,
            'position' => $this->variant->position,
            'in_stock' => $this->variant->isInStock(),
        ];
    }
}
