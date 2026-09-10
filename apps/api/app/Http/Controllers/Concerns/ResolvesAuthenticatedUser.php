<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * `$request->user()` with a type.
 *
 * Every route using this is behind `auth:sanctum`, so the guard has already
 * refused an anonymous caller and the user is never null here. The branch
 * exists to prove that to the analyser rather than to promise it - see root
 * CLAUDE.md section 11 on why this is a real branch and not an `assert()`.
 *
 * One implementation, so the same five lines are not repeated in every
 * controller that needs the current account.
 */
trait ResolvesAuthenticatedUser
{
    protected function authenticatedUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new RuntimeException('The guard authenticated a request without a user.');
        }

        return $user;
    }
}
