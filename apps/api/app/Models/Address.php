<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AddressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An entry in somebody's address book.
 *
 * Everything except `user_id` is fillable, because unlike most of this schema
 * every field here **is** something a person types into a form. The owner is not
 * one of them: it comes from the session.
 *
 * Editing one does not rewrite history. An order snapshots where it was actually
 * sent (ADR 0021), so moving house changes where the next parcel goes and
 * nothing about the last one.
 *
 * @property-read User $user
 */
#[Fillable(['name', 'line1', 'line2', 'city', 'region', 'postal_code', 'country', 'phone'])]
class Address extends Model
{
    /** @use HasFactory<AddressFactory> */
    use HasFactory;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The fields an order freezes, keyed as the order's own columns.
     *
     * One place, so adding a field to an address and forgetting to snapshot it
     * is a change to this method rather than a silent omission in `PlaceOrders`.
     *
     * @return array<string, string|null>
     */
    public function toOrderSnapshot(): array
    {
        return [
            'shipping_name' => $this->name,
            'shipping_line1' => $this->line1,
            'shipping_line2' => $this->line2,
            'shipping_city' => $this->city,
            'shipping_region' => $this->region,
            'shipping_postal_code' => $this->postal_code,
            'shipping_country' => $this->country,
            'shipping_phone' => $this->phone,
        ];
    }
}
