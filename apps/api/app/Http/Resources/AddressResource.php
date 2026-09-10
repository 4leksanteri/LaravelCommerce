<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Address;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An entry in somebody's address book.
 *
 * Only ever rendered for its own owner - `AddressController` resolves through
 * `$user->addresses()`, so there is nothing here that needs hiding from
 * somebody else because somebody else never sees one.
 *
 * The same field names an order's `shipping_address` uses, so a client renders
 * both with one component.
 */
final class AddressResource extends JsonResource
{
    public function __construct(private readonly Address $address)
    {
        parent::__construct($address);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->address->id,
            'name' => $this->address->name,
            'line1' => $this->address->line1,
            'line2' => $this->address->line2,
            'city' => $this->address->city,
            'region' => $this->address->region,
            'postal_code' => $this->address->postal_code,
            'country' => $this->address->country,
            'phone' => $this->address->phone,
        ];
    }
}
