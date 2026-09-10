<?php

declare(strict_types=1);

use App\Exceptions\CannotRemoveLastVariantException;
use App\Exceptions\CheckoutBlockedException;
use App\Exceptions\ProductNotPublishableException;
use App\Exceptions\SellerAlreadyReviewedException;
use App\Exceptions\ShopApplicationNotAllowedException;
use App\Exceptions\VariantNotPurchasableException;
use App\Http\Middleware\RequireSellerProfile;
use App\Http\Middleware\RequireStatefulRequest;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // Deliberately empty of routes. The file stays because it is what
        // registers the `web` middleware group, and Sanctum's CSRF cookie
        // route is published into that group. See routes/web.php.
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',

        // Infrastructure that probes the application. Deliberately outside the
        // versioned prefix, because a probe should not have to track API
        // versions, and deliberately not proxied - it is reached from inside
        // the container by the health check.
        health: '/up',

        // One file per API version, mounted at its own prefix.
        //
        // `apiPrefix: 'api/v1'` would have been shorter, and it pins the whole
        // application to one version forever: there is no second prefix to
        // give a second route file. Versioning exists so that v1 can keep
        // answering while v2 exists, and that requires both to be mounted at
        // the same time.
        //
        // Introducing v2 is one line here and one new file. Nothing about v1
        // moves, which is the point - a version that has to be edited to add
        // its successor is not a version.
        then: function (): void {
            Route::middleware('api')
                ->prefix('api/v1')
                ->group(base_path('routes/api/v1.php'));
        },
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

        // `stateful` on a route means: refuse, with a 400 that explains
        // itself, a request that arrived without a session. See the
        // middleware's own comment for why a 500 is the alternative.
        $middleware->alias([
            'stateful' => RequireStatefulRequest::class,

            // `seller` on a route means: the caller has a shop, and the
            // controller can read it back without looking it up again.
            'seller' => RequireSellerProfile::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // There is no HTML surface here. Every response, including a failure
        // and including the debug page in local development, is JSON, because
        // the only caller that will ever read one is a JSON client.
        $exceptions->shouldRenderJsonWhen(static fn (Request $request): bool => true);

        // Domain failures get their status here, once, rather than in a
        // try/catch in every controller that might provoke one. A controller
        // catching a domain exception only to rethrow it as HTTP is a
        // controller doing translation, which is this layer's job.
        //
        // Both of these are 409 Conflict, and the choice matters. Neither is a
        // 403 - the caller was allowed - and neither is a 422 - what they sent
        // was valid. What went wrong is the state of the world.
        $exceptions->render(static fn (SellerAlreadyReviewedException $e) => new JsonResponse(
            ['message' => $e->getMessage()],
            409,
        ));

        $exceptions->render(static fn (ShopApplicationNotAllowedException $e) => new JsonResponse(
            ['message' => $e->getMessage()],
            409,
        ));

        $exceptions->render(static fn (ProductNotPublishableException $e) => new JsonResponse(
            ['message' => $e->getMessage()],
            409,
        ));

        $exceptions->render(static fn (CannotRemoveLastVariantException $e) => new JsonResponse(
            ['message' => $e->getMessage()],
            409,
        ));

        // Checkout refused, with nothing written. `items` names the lines that
        // blocked it so the frontend can mark them in place rather than showing
        // "something went wrong" over a cart of nine things; it is empty when
        // the cart itself was.
        $exceptions->render(static fn (CheckoutBlockedException $e) => new JsonResponse(
            [
                'message' => $e->getMessage(),
                'items' => $e->items,
            ],
            409,
        ));

        // The only one of these that carries more than a message. `available`
        // is how many can actually be had, and it is here so the frontend can
        // offer "reduce to 3" rather than leaving somebody to find the number
        // by trying. Null when the reason is not stock; always present, so a
        // client reads it without checking whether the key exists.
        $exceptions->render(static fn (VariantNotPurchasableException $e) => new JsonResponse(
            [
                'message' => $e->getMessage(),
                'available' => $e->available,
            ],
            409,
        ));
    })->create();
