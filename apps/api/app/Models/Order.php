<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Currency;
use App\Enums\OrderActor;
use App\Enums\OrderStatus;
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
            'total_minor' => 'integer',
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
        return $this->hasOne(Payment::class);
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

        return $total;
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
