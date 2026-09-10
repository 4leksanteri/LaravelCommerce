<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Where an order was actually sent.
 *
 * Read from the order's own frozen columns, never from the buyer's address book
 * (ADR 0021). Somebody who moves house does not change where last year's parcel
 * went, and this is the resource that makes that true in the response as well as
 * in the schema.
 *
 * Deliberately the same shape as `AddressResource`, minus the id - there is no
 * id, because there is nothing to point at. A client renders both with one
 * component.
 */
final class ShippingAddressResource extends JsonResource
{
    public function __construct(private readonly Order $order)
    {
        parent::__construct($order);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->order->shipping_name,
            'line1' => $this->order->shipping_line1,
            'line2' => $this->order->shipping_line2,
            'city' => $this->order->shipping_city,
            'region' => $this->order->shipping_region,
            'postal_code' => $this->order->shipping_postal_code,
            'country' => $this->order->shipping_country,
            'phone' => $this->order->shipping_phone,
        ];
    }
}
