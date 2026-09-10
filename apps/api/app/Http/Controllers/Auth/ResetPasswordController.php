<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ResetPasswordController extends Controller
{
    /**
     * @throws ValidationException
     */
    public function __invoke(ResetPasswordRequest $request): Response
    {
        $status = Password::reset(
            $request->safe()->only(['email', 'password', 'password_confirmation', 'token']),
            function (User $user, string $password): void {
                $user->forceFill([
                    // The model casts `password` as `hashed`, so assigning the
                    // plain value hashes it. Never call Hash::make() here as
                    // well: that stores a hash of a hash, and the new password
                    // then never works.
                    'password' => $password,

                    // Invalidates every "remember me" cookie issued for this
                    // account. Somebody resetting a password may be doing it
                    // because a device was lost, and that device holds a
                    // cookie that outlives the session.
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PasswordReset) {
            // Unlike the forgot endpoint, this one does report the reason.
            // Reaching it requires a token that was emailed to the address in
            // question, so the caller already knows the account exists -
            // there is nothing left to disclose, and "this link has expired"
            // is the difference between somebody requesting a new one and
            // giving up.
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return response()->noContent();
    }
}
