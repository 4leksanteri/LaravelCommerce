<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class ChangePasswordRequest extends FormRequest
{
    /**
     * The new password is held to registration's rules, `Password::defaults()`
     * and the 72-byte bcrypt limit, so the two cannot drift apart.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],

            'password' => [
                'required',
                'string',
                'confirmed',
                'max:72',
                // A change to the same password changes nothing and signs every
                // other device out for it.
                'different:current_password',
                Password::defaults(),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.current_password' => 'That is not your current password.',
            'password.different' => 'Choose a password other than the one you have now.',
        ];
    }
}
