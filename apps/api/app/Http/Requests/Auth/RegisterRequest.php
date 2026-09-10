<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class RegisterRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],

            'email' => [
                'required',
                'string',
                'email:rfc',
                'max:255',
                // Registration tells the caller whether an address is already
                // taken. That is an enumeration vector, and it is a deliberate
                // one: the alternative is accepting the registration silently
                // and emailing the existing account, which leaves somebody
                // who genuinely forgot they had an account with no way to find
                // out. It is rate limited instead - see ADR 0005.
                'unique:users,email',
            ],

            'password' => [
                'required',
                'string',
                // `confirmed` looks for password_confirmation. Checking it
                // here rather than in the browser means the two are compared
                // where the value is actually used.
                'confirmed',
                // bcrypt hashes the first 72 bytes and PHP throws beyond that.
                // Refusing here turns a 500 into a field error.
                'max:72',
                Password::defaults(),
            ],
        ];
    }

    /**
     * Addresses are stored and compared lowercase, so they are lowered before
     * validation rather than after. Otherwise `unique` compares the raw input
     * against stored values and two accounts can exist for one address.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->string('email')->toString()))]);
        }
    }
}
