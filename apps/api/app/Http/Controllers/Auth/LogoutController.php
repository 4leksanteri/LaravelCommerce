<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

final class LogoutController extends Controller
{
    public function __invoke(Request $request): Response
    {
        Auth::guard('web')->logout();

        // Both, and in this order. logout() forgets the user; invalidate()
        // throws the session data away and issues a new id; regenerateToken()
        // replaces the CSRF token, because the old one belonged to the session
        // that just ended and the next write would otherwise be refused.
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Drops every guard instance resolved during this request.
        //
        // `auth:sanctum` makes `sanctum` the default guard when it passes, and
        // that guard is a RequestGuard which caches the user it resolved.
        // Logging out of `web` does not clear that cache, so without this line
        // anything running later in the same request still sees somebody
        // signed in - including AuthenticateSession's terminating callback,
        // which would then write a password hash into the session that was
        // invalidated two lines ago.
        Auth::forgetGuards();

        return response()->noContent();
    }
}
