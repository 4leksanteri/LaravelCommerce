<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Currency;
use App\Enums\OrderStatus;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An agreement with one shop.
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
}
