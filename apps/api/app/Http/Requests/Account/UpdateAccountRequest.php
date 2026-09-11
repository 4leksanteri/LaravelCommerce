<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The part of an account that changes without proving anything: the name.
 *
 * The address and the password have endpoints of their own, because each needs
 * the current password and changing either has consequences a name does not.
 */
final class UpdateAccountRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
