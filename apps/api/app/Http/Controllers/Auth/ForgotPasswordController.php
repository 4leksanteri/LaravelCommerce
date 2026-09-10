<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;

final class ForgotPasswordController extends Controller
{
    public function __invoke(ForgotPasswordRequest $request): JsonResponse
    {
        // The return value is deliberately discarded.
        //
        // sendResetLink() answers RESET_LINK_SENT, INVALID_USER or
        // RESET_THROTTLED, and reporting which one would turn this endpoint
        // into a way to ask "does this person have an account here". For a
        // marketplace that is a real disclosure: it says who sells and who
        // buys here.
        //
        // So every caller gets the same reply, at the same status, whether the
        // address is unknown, known, or asking again too soon. Whether mail
        // was actually sent is visible in the logs and in Mailpit, which is
        // where somebody debugging should be looking anyway.
        Password::sendResetLink(['email' => $request->string('email')->toString()]);

        return new JsonResponse([
            'message' => __('If that address has an account, a reset link is on its way.'),
        ]);
    }
}
