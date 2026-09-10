<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class LoginController extends Controller
{
    /**
     * @throws ValidationException
     */
    public function __invoke(LoginRequest $request): UserResource
    {
        if (! Auth::attempt($request->credentials(), $request->boolean('remember'))) {
            // One message for both failures. An address with no account and a
            // wrong password are indistinguishable from out here, which is
            // what stops this endpoint being used to discover who has an
            // account. Never add "no account with that address".
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        // A new session id for the authenticated session. Without this, a
        // session id an attacker planted before sign-in is still valid after
        // it, which is session fixation.
        $request->session()->regenerate();

        $user = $request->user();

        if (! $user instanceof User) {
            // Unreachable: attempt() has just succeeded, so the guard holds a
            // user. Written as a branch rather than an assertion for the
            // reason CLAUDE.md section 11 gives - it proves the type instead
            // of promising it.
            throw new RuntimeException('The guard authenticated a request without a user.');
        }

        return new UserResource($user);
    }
}
