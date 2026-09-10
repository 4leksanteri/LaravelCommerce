<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

/**
 * Sends the verification mail again, for the signed-in account only.
 *
 * Behind `auth:sanctum`, which is what keeps it from being a way to send mail
 * to an arbitrary address: there is no email field, and the address is read
 * off the session's own user.
 */
final class ResendVerificationEmailController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new RuntimeException('The guard authenticated a request without a user.');
        }

        // Answers the same way whether or not anything was sent. An already
        // verified account needs no mail, and saying so distinctly would only
        // give the interface a second case to handle for no benefit.
        if (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return response()->noContent();
    }
}
