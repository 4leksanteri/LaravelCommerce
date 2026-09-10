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

            $this->cancel($locked);

            return $locked;
        });
    }

    /**
     * Cancels an order nobody acted on in time.
     *
     * A second entry point rather than a third `OrderParty`, because expiry is
     * not a party: `OrderParty` names which side of an order is asking, and the
     * platform is not a side. Adding a `System` case would make every
     * `canBeCancelledBy()` answer carry an actor that has no stake.
     *
     * **Returns false rather than throwing** when the order has moved on. The
     * command that calls this selected a batch a moment earlier, and a seller
     * accepting one of them in between is an ordinary race and not a failure -
     * the order is simply skipped. The status is re-read under the lock, so
     * that check is the one that counts.
     */
    public function expire(Order $order): bool
    {
        return DB::transaction(function () use ($order): bool {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== OrderStatus::Pending) {
                return false;
            }

            $this->cancel($locked);

            return true;
        });
    }

    /**
     * The cancellation itself, once it has been decided.
     *
     * Called with the order already locked, inside a transaction. Everything
     * above this line is about who may; everything below is what happens.
     */
    private function cancel(Order $order): void
    {
        $this->returnStock($order);

        $order->forceFill([
            'status' => OrderStatus::Cancelled,
            'cancelled_at' => now(),
        ])->save();
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
