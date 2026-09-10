<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

/**
 * The user the current session belongs to.
 *
 * Behind `auth:sanctum`, so an anonymous caller gets 401 and never reaches
 * this method. That distinction matters to the frontend: 401 means the session
 * is gone and should be cleared, 403 means the session is fine and the action
 * is not allowed. Do not blur them.
 */
final class AuthenticatedUserController extends Controller
{
    /**
     * @throws AuthenticationException
     */
    public function __invoke(Request $request): UserResource
    {
        $user = $request->user();

        // Unreachable: the guard refused the request already. It is written as
        // a real branch rather than an assertion so that the type is proven
        // instead of promised, and so that a future middleware change fails
        // closed with a 401 rather than a type error.
        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return new UserResource($user);
    }
}
