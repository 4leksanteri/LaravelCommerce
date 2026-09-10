<?php

declare(strict_types=1);

namespace App\Http\Requests\Addresses;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateAddressRequest extends FormRequest
{
    /**
     * `sometimes` throughout, because this is a PATCH: an absent field is left
     * alone rather than cleared.
     *
     * Editing rewrites nothing. Orders froze their own copy, so correcting a
     * typo here fixes the next parcel and leaves every past one saying where it
     * actually went (ADR 0021).
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'line1' => ['sometimes', 'required', 'string', 'max:200'],
            'line2' => ['sometimes', 'nullable', 'string', 'max:200'],
            'city' => ['sometimes', 'required', 'string', 'max:120'],
            'region' => ['sometimes', 'nullable', 'string', 'max:120'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'country' => ['sometimes', 'required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
        ];
    }
}
