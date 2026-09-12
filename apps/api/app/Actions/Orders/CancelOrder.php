<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Actions\Payments\RefundPayment;
use App\Enums\OrderActor;
use App\Enums\OrderParty;
use App\Enums\OrderStatus;
use App\Exceptions\OrderTransitionNotAllowedException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Notifications\Orders\OrderCancelled;
use Illuminate\Support\Facades\DB;
use Throwable;

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
 * **Who called it off is recorded, and the other side is told** (ADR 0035): a
 * buyer cancelling tells the shop, a shop cancelling tells the buyer and gives
 * its reason, and a deadline tells both. The mail waits for the commit.
 */
final class CancelOrder
{
    public function __construct(private readonly RefundPayment $refund) {}

    /**
     * @param  string|null  $reason  the shop's, required of a shop by its request
     *
     * @throws OrderTransitionNotAllowedException
     */
    public function handle(Order $order, OrderParty $party, ?string $reason = null): Order
    {
        $cancelled = DB::transaction(function () use ($order, $party, $reason): Order {
            // Locked before the check, so two cancellations racing cannot both
            // pass it and both hand the stock back twice.
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->canBeCancelledBy($party)) {
                throw OrderTransitionNotAllowedException::cannotCancel($locked->status, $party);
            }

            // A reason is the shop's to give. The database refuses one on any
            // other cancellation, so it is not written for a buyer's.
            $this->cancel(
                $locked,
                OrderActor::party($party),
                $party === OrderParty::Seller ? $reason : null,
            );

            return $locked;
        });

        $this->giveTheMoneyBack($cancelled);

        // Whoever did not do it.
        if ($party === OrderParty::Buyer) {
            $cancelled->seller->notify(new OrderCancelled($cancelled, OrderParty::Seller));
        } else {
            $cancelled->user->notify(new OrderCancelled($cancelled, OrderParty::Buyer));
        }

        return $cancelled;
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
        $expired = DB::transaction(function () use ($order): ?Order {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== OrderStatus::Pending) {
                return null;
            }

            $this->cancel($locked, OrderActor::Deadline, null);

            return $locked;
        });

        if (! $expired instanceof Order) {
            return false;
        }

        $this->giveTheMoneyBack($expired);

        // Nobody chose this, so both sides hear it.
        $expired->user->notify(new OrderCancelled($expired, OrderParty::Buyer));
        $expired->seller->notify(new OrderCancelled($expired, OrderParty::Seller));

        return true;
    }

    /**
     * The buyer gets their money back, if any of it was taken (ADR 0041).
     *
     * Outside the transaction, because a call to Stripe inside it would hold a
     * lock on the order for a network round trip. Guarded, because the
     * cancellation is done and committed and the stock is already back: an
     * outage at Stripe must not turn that into an error the caller sees.
     * `payments:settle` refunds whatever was left behind.
     *
     * An unpaid order has nothing to give back, which is most of them today.
     */
    private function giveTheMoneyBack(Order $order): void
    {
        try {
            $this->refund->handle($order);
        } catch (Throwable $failure) {
            report($failure);
        }
    }

    /**
     * The cancellation itself, once it has been decided.
     *
     * Called with the order already locked, inside a transaction. Everything
     * above this line is about who may; everything below is what happens.
     */
    private function cancel(Order $order, OrderActor $by, ?string $reason): void
    {
        // **Stock comes back only if it never left.**
        //
        // A seller may cancel a shipped order, and it is the escape hatch for a
        // parcel that never arrives (ADR 0014). But the goods are in a van or
        // on somebody's doorstep by then - a shop that has posted something
        // does not still have it, and putting the units back would sell them a
        // second time.
        //
        // If they do come back, the seller restocks the variant themselves.
        // That is a real event with a real date, and guessing it here would be
        // the inventory equivalent of assuming delivery.
        if (! $order->status->hasShipped()) {
            $this->returnStock($order);
        }

        $order->forceFill([
            'status' => OrderStatus::Cancelled,
            'cancelled_at' => now(),
            'cancelled_by' => $by,
            'cancellation_reason' => $reason,
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
