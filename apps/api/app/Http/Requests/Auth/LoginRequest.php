<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class LoginRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],

            // No Password::defaults() here, and that is not an oversight.
            // Validating the shape of a submitted password would reject an
            // old one that no longer meets current rules, telling the caller
            // that the rules changed and refusing a legitimate sign-in.
            'password' => ['required', 'string'],

            'remember' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->string('email')->toString()))]);
        }
    }

    /**
     * @return array{email: string, password: string}
     */
    public function credentials(): array
    {
        return [
            'email' => $this->string('email')->toString(),
            'password' => $this->string('password')->toString(),
        ];
    }
}
