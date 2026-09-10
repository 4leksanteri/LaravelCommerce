<?php

declare(strict_types=1);

namespace App\Http\Requests\Sellers;

use Illuminate\Foundation\Http\FormRequest;

final class RejectSellerRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Required, and with a floor on the length. The applicant reads
            // this and is expected to act on it, and "no" is not something
            // anybody can act on.
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }
}
