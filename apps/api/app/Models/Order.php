<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Carrier;
use App\Enums\Currency;
use App\Enums\OrderActor;
use App\Enums\OrderParty;
use App\Enums\OrderStatus;
use Carbon\CarbonInterface;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An agreement with one shop.
 *
 * Two references, and they are different things. `reference` names this order;
 * `checkout_reference` is shared by every order the same checkout produced, and
 * is the only record that a basket spanning three shops was one purchase. Both
 * are drawn from the same pool, so a string is never both (ADR 0011).
 *
 * Nothing here is fillable. Every column is decided by `PlaceOrders` or copied
 * from the catalogue at the moment of checkout, and an order assembled from a
 * request body is an order whose total came from the browser.
 *
 * State transitions will belong in actions under `App\Actions\Orders`, not
 * here, for the reason `Seller` gives: a model method that moves a workflow on
 * has to know about the payment, the timestamps and the invariants between
 * them.
 *
 * @property-read User $user
 * @property-read Seller $seller
 * @property-read Collection<int, OrderItem> $items
 * @property-read Collection<int, OrderMessage> $messages
 * @property-read Dispute|null $dispute
 *
 * The annotation below is load-bearing rather than decorative. Cast to
 * `integer` and left unannotated, this column was published to the frontend as
 * a **string** while `total_minor` - the same bigInteger, cast the same way,
 * two lines apart - came out as a number. The `@property` line is what settles
 * it, which is the rule `apps/api/CLAUDE.md` section 8 states and ADR 0043 and
 * ADR 0047 both learned (ADR 0057).
 * @property int $shipping_minor
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'currency' => Currency::class,
            'carrier' => Carrier::class,
            'total_minor' => 'integer',

            // What was charged to post it, snapshotted at checkout and never
            // read from the catalogue again (ADR 0057). Included in
            // `total_minor`, which ADR 0011 said it would be.
            'shipping_minor' => 'integer',
            'accepted_at' => 'datetime',
            'shipped_at' => 'datetime',
            'auto_complete_at' => 'datetime',
            'completion_extensions' => 'integer',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'cancelled_by' => OrderActor::class,
            'completed_by' => OrderActor::class,
        ];
    }

    /**
     * The buyer.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The shop it was placed with.
     *
     * @return BelongsTo<Seller, $this>
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    /**
     * What was charged for this order, and where that charge got to.
     *
     * One per order, because a PaymentIntent has one currency and an order is
     * already one shop's worth in one currency (ADR 0015). Null for an order
     * placed before payments existed, and for the moment between an order
     * being written and its intent being created.
     *
     * @return HasOne<Payment, $this>
     */
    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class)
            /*
             * Sets the payment's `order` back to this one as it is loaded.
             *
             * `Payment::platformFeeMinor()` asks the order what the postage
             * was, because the marketplace does not take a cut of it
             * (ADR 0057). Without this, every payment reached through an order
             * would go and fetch the order it was just loaded from - and a
             * shop's order queue would do it once per row.
             */
            ->chaperone();
    }

    /**
     * Where to follow the parcel, or null when there is nowhere to send anybody.
     *
     * The API's answer rather than the browser's (ADR 0049). A URL template per
     * carrier is a rule, and a copy of it in the frontend is the one that goes
     * stale when a carrier changes their paths.
     *
     * Null for an untracked shipment, and for a number given without a carrier
     * - that one is shown as text, which is still something a buyer can quote.
     */
    public function trackingUrl(): ?string
    {
        if (! $this->carrier instanceof Carrier || $this->tracking_number === null) {
            return null;
        }

        return $this->carrier->trackingUrl($this->tracking_number);
    }

    /** Whether the money for this order is on the platform. */
    public function isPaid(): bool
    {
        return $this->payment?->isPaid() ?? false;
    }

    /**
     * Whether there is still a card to enter for this order.
     *
     * Both halves, and asked here rather than in the resource so that the
     * answer the frontend draws a link from is the same answer the domain
     * would give: nobody has paid, and the order is still waiting for somebody
     * to. A cancelled order has nothing left to pay, and an accepted one was
     * paid before the shop could accept it (ADR 0042).
     */
    public function canBePaid(): bool
    {
        return $this->status === OrderStatus::Pending && ! $this->isPaid();
    }

    /**
     * Orders somebody has actually paid for.
     *
     * **The one definition of what a shop may see** (ADR 0042). An order is
     * written before it is paid - stock is taken at checkout (ADR 0011) - so
     * between those two moments it exists, holds stock, and is nobody's work
     * yet. A shop shown one would be committing to fulfil something that may
     * never be paid for, and cancelling it a minute later when the expiry
     * cleared it.
     *
     * Carried by the query rather than checked afterwards, for the reason
     * `Seller::scopePublic` gives: a check that is part of the query cannot be
     * forgotten by the next endpoint.
     *
     * @param  Builder<Order>  $query
     */
    public function scopePaid(Builder $query): void
    {
        $query->whereHas('payment', function (Builder $payment): void {
            $payment->whereNotNull('paid_at');
        });
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    /**
     * What the two sides have said to each other about it (ADR 0050).
     *
     * Oldest first, because that is the order they were said in and a
     * conversation read backwards is not one.
     *
     * @return HasMany<OrderMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(OrderMessage::class)->orderBy('id');
    }

    /**
     * The dispute raised about it, if one ever was (ADR 0051).
     *
     * HasOne rather than HasMany: deciding one ends the order, either by
     * cancelling it or by completing it, so a second is a state this domain
     * cannot reach. The unique index on `disputes.order_id` is what makes that
     * true of the data rather than of the code that happens to write them.
     *
     * @return HasOne<Dispute, $this>
     */
    public function dispute(): HasOne
    {
        return $this->hasOne(Dispute::class);
    }

    /**
     * Whether this order is waiting on the platform to decide something.
     *
     * **This is what stops the clock.** `AutoCompleteShippedOrders` skips an
     * order with one, so a deadline cannot release the money for the very thing
     * being argued about (ADR 0051). The deadline itself is left where it is:
     * the database requires a shipped order to have one, and both parties
     * should still see the date it would otherwise have completed on.
     */
    public function hasOpenDispute(): bool
    {
        return $this->dispute()->whereNull('resolved_at')->exists();
    }

    /**
     * Whether the buyer may raise a dispute about it (ADR 0051, widened by
     * ADR 0061).
     *
     * **The window used to be exactly as wide as the money was held**, and that
     * was not a policy so much as a limit: once the money had reached the shop,
     * bringing it back would have been a Stripe reversal and ADR 0041 built
     * none. There is one now, so the window reaches past completion.
     *
     * ```text
     * shipped      while there is money that could still come back
     * completed    for `orders.dispute_after_completion_days` afterwards
     * ```
     *
     * **Bounded, because the alternative is a shop that is never paid.** Money
     * that can be taken back at any time is money a shop can never treat as its
     * own, which costs honest sellers more than the abuse an unbounded window
     * would catch.
     *
     * Asked here rather than in `OpenDispute` alone, so that the answer the
     * action enforces and the answer the resource draws a button from are the
     * same answer - which is what `canExtendCompletion()` exists for too.
     */
    public function canBeDisputed(): bool
    {
        if ($this->dispute()->exists() || $this->payment?->canComeBack() !== true) {
            return false;
        }

        return match ($this->status) {
            OrderStatus::Shipped => true,
            OrderStatus::Completed => $this->withinDisputeWindow(),
            default => false,
        };
    }

    /**
     * Whether a completed order is still recent enough to argue about.
     *
     * **Measured from completion rather than from shipping**, because
     * completion is the moment the buyer said it arrived - or the deadline said
     * so on their behalf. A parcel confirmed early and opened late is exactly
     * the case this window exists for, and measuring from dispatch would give
     * the least time to the buyer who waited longest for it.
     */
    private function withinDisputeWindow(): bool
    {
        $completedAt = $this->completed_at;

        if (! $completedAt instanceof CarbonInterface) {
            return false;
        }

        return $completedAt
            ->copy()
            ->addDays((int) config('orders.dispute_after_completion_days'))
            ->isFuture();
    }

    /**
     * The unread count for one side, in the same join as the orders.
     *
     * **Carried by a scope so a list does not become a count per row**, which is
     * the reasoning `Product::scopeWithRating` gives for the same shape. A page
     * of twenty orders with a badge on each would otherwise be twenty extra
     * queries.
     *
     * The alias names the side it was counted for. A query that asked for the
     * buyer's count and a resource that reads the shop's would otherwise get a
     * number that is real, wrong, and impossible to spot.
     *
     * @param  Builder<Order>  $query
     */
    public function scopeWithUnreadMessagesFor(Builder $query, OrderParty $party): void
    {
        /*
         * A correlated subquery rather than a `withCount` closure, for the
         * reason `LeaveReview` gives: inside a closure the analyser is handed a
         * `Builder<Model>` and cannot check a column name against it, so
         * `where('sender', ...)` is an error there. Starting from
         * `OrderMessage` gives every condition something real to be checked
         * against.
         *
         * `orders.*` is named alongside it because `addSelect` on a query that
         * has chosen no columns yet makes this subquery the only one selected.
         */
        $query->addSelect([
            'orders.*',
            $this->unreadAlias($party) => OrderMessage::query()
                ->selectRaw('count(*)')
                ->whereColumn('order_messages.order_id', 'orders.id')
                ->where('sender', '!=', $party)
                ->whereNull('read_at'),
        ]);
    }

    /**
     * How many messages are waiting for one side of this order.
     *
     * Unread means written by the other party and not yet read: a message is
     * never unread to whoever wrote it. Asked here rather than in each of the
     * two resources, so the buyer's badge and the shop's are counting the same
     * thing from opposite ends.
     *
     * Reads the aggregate the scope added, and falls back to a real query when
     * it is absent - so an endpoint that forgets the scope is slower rather than
     * wrong, exactly as `averageRating()` promises for a listing.
     */
    public function unreadMessageCountFor(OrderParty $party): int
    {
        $alias = $this->unreadAlias($party);

        if (! array_key_exists($alias, $this->attributes)) {
            return $this->messages()
                ->where('sender', '!=', $party)
                ->whereNull('read_at')
                ->count();
        }

        return (int) $this->getAttribute($alias);
    }

    private function unreadAlias(OrderParty $party): string
    {
        return "unread_for_{$party->value}";
    }

    /**
     * The sum of the lines.
     *
     * `total_minor` is stored rather than derived, because it is the figure a
     * payment will be made against and it must not move if line arithmetic ever
     * changes. This recomputes it from the lines, and `CheckoutTest` asserts
     * the two agree - a stored total that has drifted from what it totals is
     * the kind of defect nothing else would notice.
     */
    public function recalculatedTotalMinor(): int
    {
        $total = 0;

        foreach ($this->items as $item) {
            $total += $item->lineTotalMinor();
        }

        /*
         * Postage is part of what the buyer owes, so it is part of what the
         * total has to equal (ADR 0011 said the name would not have to change
         * when this arrived, and it did not). Leaving it out here would make
         * `CheckoutTest`'s drift assertion fail for every order that costs
         * anything to send - which is the assertion doing its job.
         */
        return $total + $this->shipping_minor;
    }

    /**
     * Whether the buyer may still push back the date this completes on its own.
     *
     * Both halves: the order has to be shipped, and they have to have
     * extensions left. Asked by the action that does it and by the resource
     * that tells the frontend whether to draw the button, so the two cannot
     * disagree.
     */
    public function canExtendCompletion(): bool
    {
        return $this->status->canHaveDeadlineExtended()
            && $this->completion_extensions < (int) config('orders.max_completion_extensions');
    }

    /**
     * Units, not lines. Both sides of an order count them the same way, so it
     * lives here rather than in each of the two resources.
     */
    public function itemCount(): int
    {
        $count = 0;

        foreach ($this->items as $item) {
            $count += $item->quantity;
        }

        return $count;
    }
}
