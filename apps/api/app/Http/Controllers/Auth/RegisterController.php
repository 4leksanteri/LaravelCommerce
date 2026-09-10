<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

final class RegisterController extends Controller
{
    public function __invoke(RegisterRequest $request): JsonResponse
    {
        $user = User::create($request->safe()->only(['name', 'email', 'password']));

        // The framework listens for this and sends the verification mail. It
        // is an event rather than a direct call so that anything else that
        // should happen on registration - a welcome message, an analytics
        // record - hangs off the same moment instead of accumulating here.
        event(new Registered($user));

        // Signed in immediately, and deliberately: making somebody register
        // and then sign in with the credentials they just typed is a second
        // chance to mistype them. The account exists and is unverified, which
        // is a state the rest of the application understands.
        Auth::login($user);

        // Against session fixation: an attacker who planted a session id
        // before registration must not still hold a valid one after it.
        $request->session()->regenerate();

        return (new UserResource($user))->response()->setStatusCode(201);
    }
}
