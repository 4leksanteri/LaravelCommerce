<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\OrderParty;
use App\Enums\OrderStatus;
use App\Exceptions\OrderTransitionNotAllowedException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

/**
 * Calls an order off, and gives the stock back.
 *
 * **The stock release is the point.** Checkout takes stock at placement
 * (ADR 0011), so an order that is cancelled and does not return it is inventory
 * quietly deleted from a shop. This is the first thing in the application that
 * puts any back.
 *
 * Who may cancel depends on where the order has got to, and the asymmetry is
 * deliberate: either party may call off an order nobody has committed to, and
 * once a seller has accepted it is theirs alone to call off - they are the one
 * who set stock aside for it. `OrderStatus::canBeCancelledBy()` holds that rule;
 * this action applies it.
 *
 * Nothing cancels a shipped order. That is a return, and returns are disputes.
 */
final class CancelOrder
{
    /**
     * @throws OrderTransitionNotAllowedException
     */
    public function handle(Order $order, OrderParty $party): Order
    {
        return DB::transaction(function () use ($order, $party): Order {
            // Locked before the check, so two cancellations racing cannot both
            // pass it and both hand the stock back twice.
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->canBeCancelledBy($party)) {
                throw OrderTransitionNotAllowedException::cannotCancel($locked->status, $party);
            }

            $this->returnStock($locked);

            $locked->forceFill([
                'status' => OrderStatus::Cancelled,
                'cancelled_at' => now(),
            ])->save();

            return $locked;
        });
    }

    /**
     * Puts back exactly what checkout took.
     *
     * Read from the order's own lines rather than from anything current,
     * because the quantity that was taken is the quantity that was agreed and
     * the catalogue may have moved since.
     *
     * A line whose variant has been deleted returns nothing, because there is
     * nowhere to return it to. That is the same nullable reference ADR 0011
     * describes, and the order still cancels - a seller who removed the variant
     * has already stopped counting it.
     */
    private function returnStock(Order $order): void
    {
        $items = $order->items()->whereNotNull('product_variant_id')->get();

        if ($items->isEmpty()) {
            return;
        }

        $ids = $items
            ->map(static fn (OrderItem $item): ?int => $item->product_variant_id)
            ->filter(static fn (?int $id): bool => $id !== null)
            ->unique()
            ->sort()
            ->values()
            ->all();

        // Locked in id order, the same order checkout takes them in, so a
        // cancellation and a checkout touching the same variants cannot
        // deadlock against each other.
        ProductVariant::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();

        foreach ($items as $item) {
            ProductVariant::query()
                ->whereKey($item->product_variant_id)
                ->increment('stock', $item->quantity);
        }
    }
}
