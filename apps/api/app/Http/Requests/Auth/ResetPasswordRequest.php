<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class ResetPasswordRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Carried back from the emailed link. The broker hashes it and
            // compares against the stored row, so it is never looked up here.
            'token' => ['required', 'string'],

            'email' => ['required', 'string', 'email:rfc', 'max:255'],

            'password' => [
                'required',
                'string',
                'confirmed',
                'max:72',
                Password::defaults(),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->string('email')->toString()))]);
        }
    }
}
