<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CartItem;
use App\Models\Seller;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * The part of a cart that belongs to one shop.
 *
 * **This grouping is the money rule made structural.** Sellers price in their
 * own currency, so a basket spanning three shops is three subtotals in three
 * currencies, and a single figure across them does not exist (ADR 0004). A flat
 * list of lines with one total at the bottom would have made that total the
 * obvious thing to add, and it would have been wrong the first time somebody
 * bought from two shops.
 *
 * Each of these will become one order and one payment.
 *
 * Aggregates over a group live with the group, which is what `ProductCollection`
 * says a collection class is for.
 */
final class CartShopResource extends JsonResource
{
    /**
     * @param  Collection<int, CartItem>  $lines
     */
    public function __construct(
        private readonly Seller $shop,
        private readonly Collection $lines,
    ) {
        parent::__construct($lines);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'shop_slug' => $this->shop->slug,
            'shop_name' => $this->shop->shop_name,

            // The shop's, fixed when it applied and never editable (ADR 0007).
            // Every figure below is denominated in it.
            'currency' => $this->shop->currency,

            'subtotal_minor' => $this->subtotalMinor(),
            'has_unavailable_items' => $this->hasUnavailableItems(),

            'items' => CartItemResource::collection($this->lines),
        ];
    }

    /**
     * What this shop's part of the cart would cost, at today's prices.
     *
     * **Available lines only.** A sold-out line contributes nothing, because
     * this is the number a shopper is about to be charged rather than a sum of
     * everything in the box. The lines themselves each report their own total
     * and their own availability, so nothing is hidden by the omission.
     */
    private function subtotalMinor(): int
    {
        // Written as a loop rather than a `sum()` over a closure so that the
        // running figure is an integer at every step. Money is integer minor
        // units all the way down (ADR 0004), and a helper that returns
        // int|float is not the place to keep it.
        $subtotal = 0;

        foreach ($this->lines as $line) {
            if ($line->isAvailable()) {
                $subtotal += $line->lineTotalMinor();
            }
        }

        return $subtotal;
    }

    private function hasUnavailableItems(): bool
    {
        return $this->lines->contains(static fn (CartItem $line): bool => ! $line->isAvailable());
    }
}
