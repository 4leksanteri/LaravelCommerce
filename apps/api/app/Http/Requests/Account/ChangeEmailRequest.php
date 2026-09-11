<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ChangeEmailRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $user = $this->user();
        $current = $user instanceof User ? $user->email : '';

        return [
            'email' => [
                'required',
                'string',
                'email:rfc',
                'max:255',
                Rule::unique('users', 'email')->ignore($user instanceof User ? $user->id : null),
                // The address already held: changing to it would unconfirm it
                // and send a link for nothing.
                Rule::notIn([$current]),
            ],

            // Proof that the account's owner is at the keyboard rather than
            // whoever found it signed in. The address is where a password reset
            // goes, so changing it is how an account is taken.
            'current_password' => ['required', 'string', 'current_password'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.not_in' => 'That is already your email address.',
            'current_password.current_password' => 'That is not your current password.',
        ];
    }

    /**
     * Stored and compared lowercase, as registration does, so `unique` compares
     * like with like.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->string('email')->toString()))]);
        }
    }
}
