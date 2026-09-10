<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CartFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A shopper's basket. One per account (ADR 0010).
 *
 * There is no `#[Fillable]` attribute on this class and that is deliberate:
 * nothing here is a field a request body sets. A cart has exactly one piece of
 * data, whose account it is, and that comes from the session rather than from
 * the payload. Laravel's default empty fillable list is the right one.
 *
 * The cart holds no money. Prices are read from variants every time the cart is
 * shown, which is the whole point of ADR 0010 - a total stored here would be a
 * second copy of a number the catalogue owns.
 *
 * @property-read User $user
 * @property-read Collection<int, CartItem> $items
 */
class Cart extends Model
{
    /** @use HasFactory<CartFactory> */
    use HasFactory;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The lines, oldest first.
     *
     * Insertion order rather than anything cleverer: a cart is a list somebody
     * built, and reordering it under them is disorienting.
     *
     * @return HasMany<CartItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class)->orderBy('id');
    }

    /**
     * The lines, loaded with everything needed to say what each one costs and
     * whether it can still be bought.
     *
     * One place, because the relations are not optional extras:
     * `CartItem::availability()` reads `purchasableVariant`, which is null
     * unless it was loaded, and loading it lazily per line is a query per line.
     *
     * @return Collection<int, CartItem>
     */
    public function lines(): Collection
    {
        return $this->items()->with(['seller', 'purchasableVariant.product'])->get();
    }
}
