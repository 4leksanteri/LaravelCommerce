<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Currency;
use App\Enums\SellerStatus;
use Database\Factories\SellerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A shop.
 *
 * One per user (ADR 0007), and the thing that makes somebody a seller - there
 * is no `seller` role. Approval is what makes it public; nothing else does.
 *
 * State transitions belong in the actions under `App\Actions\Sellers`, not
 * here. A model method called `approve()` would be a workflow wearing a
 * model's clothes, and it would have to know about the reviewer, the
 * timestamps and the invariants between them.
 *
 * @property-read User $user
 * @property-read User|null $reviewer
 */
#[Fillable(['shop_name', 'description', 'contact_email'])]
class Seller extends Model
{
    /** @use HasFactory<SellerFactory> */
    use HasFactory;

    /**
     * `slug`, `currency`, `status` and everything about the review are absent
     * from the fillable list above, deliberately. None of them is a field
     * somebody submits: the slug is derived once, the currency is validated
     * and set at application, and the review columns are written only by the
     * actions that record a decision. Mass assignment is how one of them ends
     * up being set by a request body that happened to carry the key.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SellerStatus::class,
            'currency' => Currency::class,
            'applied_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * The shop's listings, at any status.
     *
     * Seller-scoped endpoints resolve products through this relation, so the
     * ownership rule is part of the query rather than something a controller
     * remembers to check afterwards (ADR 0008).
     *
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Shops a shopper may see.
     *
     * Used by every public read. Asking for `status = approved` at each call
     * site instead would work until the day somebody forgets, and that day
     * publishes a shop nobody approved.
     *
     * @param  Builder<Seller>  $query
     */
    public function scopePublic(Builder $query): void
    {
        $query->where('status', SellerStatus::Approved);
    }

    public function isPublic(): bool
    {
        return $this->status->isPublic();
    }

    public function isPending(): bool
    {
        return $this->status === SellerStatus::Pending;
    }
}
