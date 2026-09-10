<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // Deliberately empty of routes. The file stays because it is what
        // registers the `web` middleware group, and Sanctum's CSRF cookie
        // route is published into that group. See routes/web.php.
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        // Every resource is versioned. Infrastructure that probes the
        // application - the container health check on /up - deliberately is
        // not, because a probe should not have to track API versions.
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum's stateful guard, and the reason browser authentication
        // works at all. It inspects Origin/Referer against
        // `sanctum.stateful`; on a match it runs the session, cookie and CSRF
        // middleware for that request, and the caller is authenticated by the
        // session cookie rather than by a token.
        //
        // The consequence is easy to trip over: a request arriving with
        // neither Origin nor Referer is *not* stateful, and its session
        // cookie is ignored. Anything proxying to this application must
        // forward both. See ADR 0003.
        $middleware->statefulApi();

        // There is no sign-in page to send anybody to. Laravel's default is
        // `route('login')`, and the Authenticate middleware resolves it before
        // the exception handler is ever consulted - so an unauthenticated
        // request that did not ask for JSON died with a 500
        // "Route [login] not defined" instead of a 401.
        //
        // Returning null means the middleware throws AuthenticationException,
        // which the handler renders as a 401. The sign-in page belongs to the
        // Next.js application, and this one answers with a status code.
        $middleware->redirectGuestsTo(static fn (): ?string => null);

        // This application is never published to the internet. The only
        // ingress is the Next.js container on the internal Docker network,
        // so every X-Forwarded-* header this process sees was written by the
        // proxy and there is no untrusted hop to distrust.
        //
        // That reasoning is what makes `*` safe, and it stops being true the
        // moment this service is exposed directly. If that ever happens, this
        // becomes an explicit proxy list on the same day.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // There is no HTML surface here. Every response, including a failure
        // and including the debug page in local development, is JSON, because
        // the only caller that will ever read one is a JSON client.
        $exceptions->shouldRenderJsonWhen(static fn (Request $request): bool => true);
    })->create();
