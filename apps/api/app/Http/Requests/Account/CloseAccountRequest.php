<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Closing an account needs the password, for the reason changing the address
 * and the password do (ADR 0034): the attacker these are for is somebody
 * already inside a session left open on a shared computer.
 *
 * This one has the strongest claim of the three. Both of those can be undone by
 * the owner from their inbox; this cannot be undone at all.
 */
final class CloseAccountRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.current_password' => 'That is not your current password.',
        ];
    }
}
