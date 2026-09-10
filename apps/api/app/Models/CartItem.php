<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CartItemAvailability;
use Database\Factories\CartItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A variant, and how many of it.
 *
 * Nothing here is fillable. Every column is either derived from the variant or
 * decided by an action, and a cart line built from a request body is a cart
 * line whose price came from the browser.
 *
 * The `added_price_minor`, `product_name` and `variant_name` columns are a
 * snapshot, and it is worth being precise about what they are for. They are
 * **not** the price: what a line costs is read from the variant every time it
 * is shown. They exist so the cart can say "this was 24.99 when you added it"
 * and so a line whose variant has since been deleted is still recognisable.
 * ADR 0010 has the reasoning.
 *
 * @property-read Cart $cart
 * @property-read Seller $seller
 * @property-read ProductVariant|null $purchasableVariant
 */
class CartItem extends Model
{
    /** @use HasFactory<CartItemFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'added_price_minor' => 'integer',
        ];
    }

    /** @return BelongsTo<Cart, $this> */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * The shop this line will be bought from.
     *
     * Read from the line's own column rather than through the variant, because
     * the variant may be gone and the line still has to be grouped and priced
     * in the right currency.
     *
     * @return BelongsTo<Seller, $this>
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    /**
     * The variant, **if it can still be bought**.
     *
     * The constraint is what makes this relation worth having. It is null both
     * when the variant row is gone and when its listing is no longer for sale -
     * a draft, a deleted product, a suspended shop - so availability is one
     * null check rather than a walk up to the product and the shop with a
     * second copy of "what the storefront shows" written along the way.
     *
     * `Product::scopePublic()` stays the only definition of that, here as in
     * the storefront.
     *
     * @return BelongsTo<ProductVariant, $this>
     */
    public function purchasableVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')
            ->whereHas('product', self::sellableProduct(...));
    }

    /**
     * Through a named method rather than an inline closure, so the builder can
     * be typed as the Product's. The same reason `Product::approvedSeller`
     * exists.
     *
     * @param  Builder<Product>  $products
     */
    private static function sellableProduct(Builder $products): void
    {
        $products->public();
    }

    /**
     * What one of these costs **now**.
     *
     * Read from the variant, not from the snapshot, because the catalogue is
     * the source of truth until an order is written. The snapshot is the
     * fallback only when there is no variant left to ask, and in that case the
     * line cannot be bought anyway - the number is there so the cart still
     * shows a figure beside a name rather than a blank.
     */
    public function unitPriceMinor(): int
    {
        $variant = $this->purchasableVariant;

        return $variant instanceof ProductVariant ? $variant->price_minor : $this->added_price_minor;
    }

    /**
     * What this line costs at today's price.
     *
     * The API computes money and the frontend formats it (root CLAUDE.md
     * section 7). Note this is the line's own arithmetic and it is reported
     * whatever the line's availability - a shop subtotal is the narrower
     * figure, and only counts lines that can actually be bought.
     */
    public function lineTotalMinor(): int
    {
        return $this->unitPriceMinor() * $this->quantity;
    }

    /** Whether the price moved while this sat in the cart. */
    public function priceChanged(): bool
    {
        return $this->unitPriceMinor() !== $this->added_price_minor;
    }

    public function availability(): CartItemAvailability
    {
        $variant = $this->purchasableVariant;

        if (! $variant instanceof ProductVariant) {
            return CartItemAvailability::NoLongerForSale;
        }

        if (! $variant->isInStock()) {
            return CartItemAvailability::OutOfStock;
        }

        if ($variant->stock < $this->quantity) {
            return CartItemAvailability::InsufficientStock;
        }

        return CartItemAvailability::Available;
    }

    public function isAvailable(): bool
    {
        return $this->availability()->isAvailable();
    }

    /**
     * How many of these could actually be had, when that is fewer than this
     * line asks for. Null otherwise.
     *
     * Deliberately not "the stock level". ADR 0009 refuses to publish an exact
     * count to shoppers, and this stays inside that rule by answering only when
     * the shopper has to be told something in order to fix their cart.
     */
    public function availableQuantity(): ?int
    {
        return $this->availability() === CartItemAvailability::InsufficientStock
            ? $this->purchasableVariant?->stock
            : null;
    }
}
