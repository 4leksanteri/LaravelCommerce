<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\CheckoutBlocker;
use App\Models\CartItem;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * A cart, grouped by shop.
 *
 * **There is no grand total, and there will not be one.** Each shop prices in
 * its own currency (ADR 0004, ADR 0007), so a figure spanning two of them is
 * not a number - it is two numbers added together by mistake. The response
 * shape is what keeps that from being an easy mistake to make: there is nowhere
 * obvious to put the wrong total, and each group carries its own.
 *
 * This is built from the **lines** rather than from a `Cart` model, because a
 * shopper who has never added anything has no cart row and their cart is still
 * a perfectly good thing to render. `GET /cart` does not create one; a GET that
 * writes would insert a row for every visit.
 */
final class CartResource extends JsonResource
{
    /**
     * @param  Collection<int, CartItem>  $lines
     */
    public function __construct(private readonly Collection $lines)
    {
        parent::__construct($lines);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // Units, not lines: three of one thing is a cart of three, which is
            // what the number beside a cart icon means.
            'item_count' => $this->itemCount(),

            // The answer a checkout button needs, rather than the lines for the
            // browser to scan.
            'has_unavailable_items' => $this->hasUnavailableItems(),

            'checkout_blocker' => $this->checkoutBlocker($request),

            'shops' => $this->shops(),
        ];
    }

    /**
     * @return array<int, CartShopResource>
     */
    private function shops(): array
    {
        $shops = [];

        // Sorted before grouping, so the groups come out in a stable order that
        // does not depend on which shop the shopper happened to visit first.
        $grouped = $this->lines
            ->sortBy(static fn (CartItem $line): string => $line->seller->shop_name)
            ->groupBy('seller_id');

        foreach ($grouped as $lines) {
            $first = $lines->first();

            // groupBy never produces an empty group. The branch is here to
            // prove that to the analyser rather than to promise it - see root
            // CLAUDE.md section 11.
            if (! $first instanceof CartItem) {
                continue;
            }

            $shops[] = new CartShopResource($first->seller, $lines);
        }

        return $shops;
    }

    private function itemCount(): int
    {
        $count = 0;

        foreach ($this->lines as $line) {
            $count += $line->quantity;
        }

        return $count;
    }

    private function hasUnavailableItems(): bool
    {
        return $this->lines->contains(static fn (CartItem $line): bool => ! $line->isAvailable());
    }

    /**
     * What stands between this basket and a checkout, or nothing (ADR 0030).
     *
     * An empty basket first, because there is then nothing to check out at all.
     * After that, **in the order checkout itself refuses**: the `verified`
     * middleware runs before PlaceOrders revalidates the basket, so an
     * unconfirmed address is the answer even when a line is also unavailable.
     * CheckoutBlockerTest asserts that each answer matches what checkout then
     * does, so the two cannot drift apart.
     *
     * For the viewer, read from the request, as the `can_*` fields elsewhere
     * are. The cart endpoints all require a session, so there is always a user;
     * the branch fails closed rather than trusting that.
     */
    private function checkoutBlocker(Request $request): ?CheckoutBlocker
    {
        if ($this->lines->isEmpty()) {
            return CheckoutBlocker::Empty;
        }

        $viewer = $request->user();

        if (! $viewer instanceof User || ! $viewer->hasVerifiedEmail()) {
            return CheckoutBlocker::UnverifiedEmail;
        }

        return $this->hasUnavailableItems() ? CheckoutBlocker::UnavailableItems : null;
    }
}
