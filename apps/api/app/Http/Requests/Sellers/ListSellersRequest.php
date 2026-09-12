<?php

declare(strict_types=1);

namespace App\Http\Requests\Sellers;

use App\Enums\SellerStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The review queue, narrowed to one status or not at all.
 *
 * One status at a time, as the shop's own order queue is narrowed (ADR 0036).
 * A status that does not exist is a 422 rather than the whole queue: it used to
 * be ignored silently, which meant a mistyped link answered with every shop on
 * the platform and looked like it had worked.
 */
final class ListSellersRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::enum(SellerStatus::class)],
        ];
    }

    public function status(): ?SellerStatus
    {
        return $this->enum('status', SellerStatus::class);
    }
}
