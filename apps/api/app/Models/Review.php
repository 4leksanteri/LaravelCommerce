<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\ReviewFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One buyer's verdict on one listing (ADR 0047).
 *
 * Nothing here is fillable. The rating and the body come from a request, but
 * they arrive through an action that has already decided the person is entitled
 * to write them, and the three ids are the platform's to set.
 *
 * `order` is the proof of purchase, and it is why "verified" needs no column: a
 * review exists only where a completed order did.
 *
 * @property-read Product $product
 * @property-read User $user
 * @property-read Order $order
 * @property int $id
 * @property int $product_id
 * @property int $user_id
 * @property int $order_id
 * @property int $rating
 * @property string|null $body
 * @property CarbonInterface|null $hidden_at
 * @property string|null $hidden_reason
 * @property int|null $hidden_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Review extends Model
{
    /** @use HasFactory<ReviewFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'hidden_at' => 'datetime',
        ];
    }

    /**
     * Reviews a reader other than the author may see (ADR 0054).
     *
     * **The one definition of "visible", and `Product::reviews()` is written in
     * terms of it** - so the rating aggregate, both of its fallbacks and the
     * public list all exclude a hidden review without any of them saying so.
     * That is the same reasoning `Seller::scopePublic()` gives: a condition
     * carried by the query cannot be forgotten by the next endpoint.
     *
     * **`LeaveReview` deliberately does not apply it.** A hidden review still
     * occupies its author's one-per-listing slot, because otherwise hiding
     * somebody's review would quietly hand them a fresh one - which is
     * moderation undoing itself.
     *
     * @param  Builder<Review>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $query->whereNull('hidden_at');
    }

    /**
     * Whether the platform has taken it out of sight.
     *
     * The row stays either way. ADR 0047 refused deletion because "who may
     * erase one is an argument between two parties this codebase cannot hear",
     * and hiding is what the third party does instead: the author keeps their
     * words and may still edit them, and nobody else sees either version.
     */
    public function isHidden(): bool
    {
        return $this->hidden_at !== null;
    }

    /**
     * The member of staff who hid it.
     *
     * @return BelongsTo<User, $this>
     */
    public function hiddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hidden_by');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The completed order this was earned by.
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Whether it has been changed since it was written.
     *
     * Shown rather than hidden: a review that has been rewritten is a different
     * thing from one that has stood since the day it was left, and a reader is
     * entitled to know which they are looking at.
     */
    public function wasEdited(): bool
    {
        return $this->created_at !== null
            && $this->updated_at !== null
            && $this->updated_at->gt($this->created_at);
    }
}
