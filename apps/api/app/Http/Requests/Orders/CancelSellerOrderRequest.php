<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A shop calling off an order, and saying why.
 *
 * The reason is required. A buyer whose order a shop cancels is owed an
 * explanation, and reads this one on the order's page and in the mail that
 * tells them (ADR 0035). A buyer cancelling their own order gives none, and
 * their endpoint takes no body.
 */
final class CancelSellerOrderRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
