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

            /*
             * What posting this group will cost, and what it comes to
             * (ADR 0057).
             *
             * **Charged once, not once per line.** One order per shop is one
             * parcel (ADR 0011), so the group pays the dearest thing in it -
             * the item that decides what the box has to be. Adding three
             * postages for three things going in one box would overcharge
             * exactly the shopper a marketplace most wants.
             *
             * `total_minor` here is this shop's, and there is still no figure
             * across shops: that is the rule this class exists to make
             * structural (ADR 0004).
             */
            'shipping_minor' => $this->shippingMinor(),
            'total_minor' => $this->subtotalMinor() + $this->shippingMinor(),

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

    /**
     * What it costs to post this shop's part of the basket (ADR 0057).
     *
     * The dearest postage among the lines that can actually be bought, and
     * nought when none of them can - the same rule `subtotalMinor()` follows,
     * because a group with nothing buyable in it is not about to be charged for
     * a parcel either.
     *
     * A line whose listing has been deleted reports nought rather than a stale
     * figure: it cannot be bought, so it is not in this sum at all.
     */
    private function shippingMinor(): int
    {
        $shipping = 0;

        foreach ($this->lines as $line) {
            if ($line->isAvailable()) {
                $shipping = max($shipping, $line->shippingMinor());
            }
        }

        return $shipping;
    }

    private function hasUnavailableItems(): bool
    {
        return $this->lines->contains(static fn (CartItem $line): bool => ! $line->isAvailable());
    }
}
