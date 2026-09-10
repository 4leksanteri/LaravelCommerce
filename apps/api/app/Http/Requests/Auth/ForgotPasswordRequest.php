<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class ForgotPasswordRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // No `exists:users,email`. That rule is the whole enumeration
            // problem in one word: it answers 422 for an address with no
            // account and 200 for one that has. The controller answers the
            // same thing either way instead.
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->string('email')->toString()))]);
        }
    }
}
