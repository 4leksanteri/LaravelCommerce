<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Marks an address verified from an emailed link.
 *
 * Behind `signed:relative`, and **not** behind `auth:sanctum`. The link is the
 * credential: it is signed by this application and expires. Requiring a
 * session as well would mean the link only worked in the browser the person
 * registered in, and mail is very often opened somewhere else.
 */
final class VerifyEmailController extends Controller
{
    public function __invoke(string $id, string $hash): JsonResponse
    {
        $user = User::find($id);

        // hash_equals rather than ===, so a wrong hash costs the same time to
        // reject whatever it gets wrong. The hash is over the address, so it
        // also means a link stops working the moment the address changes.
        if (! $user instanceof User || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            // 403 rather than 404. The signature was valid, so this is a link
            // that is no longer for this address rather than a link that was
            // never real, and the two want different advice.
            throw new HttpException(403, 'This verification link is not valid for that account.');
        }

        if ($user->hasVerifiedEmail()) {
            // Idempotent on purpose. Mail clients prefetch links, people click
            // twice, and a second click must not read as a failure.
            return new JsonResponse(['verified' => true, 'already_verified' => true]);
        }

        $user->markEmailAsVerified();

        event(new Verified($user));

        return new JsonResponse(['verified' => true, 'already_verified' => false]);
    }
}
