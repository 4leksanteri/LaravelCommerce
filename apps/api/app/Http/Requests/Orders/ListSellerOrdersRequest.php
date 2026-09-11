<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A shop's order queue, narrowed to one status or not at all.
 *
 * One status at a time, because the questions a shop asks of its queue are
 * each one status: what is waiting to be accepted, what is waiting to be sent
 * (ADR 0036). A status that does not exist is a 422 rather than an empty page,
 * so a mistyped link says so.
 */
final class ListSellerOrdersRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::enum(OrderStatus::class)],
        ];
    }

    public function status(): ?OrderStatus
    {
        return $this->enum('status', OrderStatus::class);
    }
}
