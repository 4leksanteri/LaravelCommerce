<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Account\ChangeEmail;
use App\Actions\Account\ChangePassword;
use App\Http\Controllers\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\ChangeEmailRequest;
use App\Http\Requests\Account\ChangePasswordRequest;
use App\Http\Requests\Account\UpdateAccountRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * The signed-in person's own account: their name, their email address and
 * their password (ADR 0034).
 *
 * No id in any path. The account is the session's, so there is nothing a caller
 * could substitute to reach somebody else's, and no policy question to ask.
 *
 * The two changes that can lock somebody out, the address and the password,
 * each need the current password and are rate limited by account. A session
 * left open on a shared computer is the attacker they are for.
 */
final class AccountController extends Controller
{
    use ResolvesAuthenticatedUser;

    public function update(UpdateAccountRequest $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);

        $user->fill($request->safe()->only(['name']))->save();

        return (new UserResource($user))->response();
    }

    /**
     * Moves the account to a new address, which then needs confirming: a link
     * is sent to it, as at registration.
     */
    public function email(ChangeEmailRequest $request, ChangeEmail $change): JsonResponse
    {
        $user = $change->handle(
            $this->authenticatedUser($request),
            $request->string('email')->toString(),
        );

        return (new UserResource($user))->response();
    }

    /**
     * Replaces the password, and signs out every other session the account has.
     */
    public function password(ChangePasswordRequest $request, ChangePassword $change): Response
    {
        $change->handle(
            $this->authenticatedUser($request),
            $request->string('password')->toString(),
            keepSessionId: $request->session()->getId(),
        );

        // The session that made the change keeps going, under a new id. Its old
        // id was issued before the change, and destroying it treats it like
        // every other session that was.
        $request->session()->regenerate(destroy: true);

        return response()->noContent();
    }
}
