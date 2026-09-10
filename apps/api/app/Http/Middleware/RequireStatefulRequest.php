<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Refuses, clearly, a request that cannot hold a session.
 *
 * Sanctum only starts a session when Origin or Referer matches
 * SANCTUM_STATEFUL_DOMAINS (ADR 0002). Signing in without one is not
 * meaningful: there is nowhere to put the session, and `$request->session()`
 * throws a RuntimeException that surfaces as a 500 saying "Session store not
 * set on request" - which describes the symptom and not the cause.
 *
 * A browser always sends Origin on an unsafe request, so in normal use this
 * middleware never fires. It fires for a hand-written curl, for a client that
 * strips the header, and for a proxy that has stopped forwarding it - and the
 * last of those is the one worth catching, because it is a configuration
 * mistake that otherwise looks like an application bug.
 */
final class RequireStatefulRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            throw new HttpException(
                400,
                'This endpoint needs a session. The request arrived without an Origin or Referer '.
                'header matching a configured stateful domain, so no session was started.',
            );
        }

        return $next($request);
    }
}
