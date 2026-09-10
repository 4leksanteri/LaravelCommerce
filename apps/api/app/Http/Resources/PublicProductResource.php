<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A listing as a shopper sees it.
 *
 * A shorter allowlist than `ProductResource`, and the omissions are the point:
 * no status, no publication date, no `can_*`. Whether something is a draft is
 * not a shopper's business, and by construction nothing here is one.
 *
 * **Exact stock counts are not published either.** A shopper needs to know
 * whether they can buy the thing, not that four remain: a live count is a
 * competitor's inventory report, and it invites a race the checkout has to win
 * anyway. `in_stock` is the honest answer to the question actually being
 * asked.
 */
final class PublicProductResource extends JsonResource
{
    public function __construct(private readonly Product $product)
    {
        parent::__construct($product);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->product->slug,
            'name' => $this->product->name,
            'description' => $this->product->description,
            'currency' => $this->product->currency(),

            'images' => ProductImageResource::collection($this->product->images),
            'variants' => $this->product->variants
                ->map(static fn (ProductVariant $variant): array => [
                    'id' => $variant->id,
                    'name' => $variant->name,
                    'price_minor' => $variant->price_minor,
                    'in_stock' => $variant->isInStock(),
                ])
                ->all(),
        ];
    }
}
