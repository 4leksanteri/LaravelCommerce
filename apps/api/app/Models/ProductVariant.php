<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The thing that is actually bought, and the only place a price lives.
 *
 * `price_minor` is an integer number of minor units and is never divided,
 * summed or formatted here (ADR 0004). What those units are worth is the
 * currency's business, and the currency belongs to the shop.
 *
 * @property-read Product $product
 */
#[Fillable(['name', 'price_minor', 'stock', 'position'])]
class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'stock' => 'integer',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Whether a shopper could buy this right now.
     *
     * Stock only. Whether the *product* is on sale is the product's question,
     * and combining the two here would give two objects an opinion about the
     * same thing.
     */
    public function isInStock(): bool
    {
        return $this->stock > 0;
    }
}
