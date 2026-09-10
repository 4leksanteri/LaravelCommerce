<?php

declare(strict_types=1);

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | A request whose Origin or Referer matches one of these hosts is treated
    | as first-party: the session cookie authenticates it and CSRF is enforced.
    | A request that matches nothing falls through to bearer-token
    | authentication, which this application does not currently issue, and is
    | therefore anonymous.
    |
    | This is the list that decides who may hold a session. It holds the public
    | origin of the Next.js application and nothing else. Never a wildcard, and
    | never this application's own internal address - the proxy forwards the
    | browser's Origin precisely so that the browser's origin is what gets
    | matched here.
    |
    | Stated once, in the environment, because it must track the public web
    | origin and a second copy would go stale. See ADR 0002.
    |
    */

    'stateful' => array_filter(
        array_map('trim', explode(',', (string) env('SANCTUM_STATEFUL_DOMAINS', 'localhost:3000')))
    ),

    /*
    |--------------------------------------------------------------------------
    | Route Prefix
    |--------------------------------------------------------------------------
    |
    | Sanctum publishes GET {prefix}/csrf-cookie, which is how a browser is
    | given the XSRF-TOKEN cookie it has to echo back on unsafe requests.
    |
    | It sits under the versioned API prefix rather than Sanctum's default
    | /sanctum so that the entire public surface of this application is
    | /api/**. That is one prefix for the Next.js server to proxy and one rule
    | to state: the browser calls /api and nothing else exists to call.
    |
    */

    'prefix' => 'api/v1/auth',

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | The guards checked when Sanctum authenticates a request. `web` is the
    | session guard, which is the only mechanism in use.
    |
    */

    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | Applies to issued API tokens, of which there are none. Session lifetime
    | is config/session.php, and is not affected by this value.
    |
    */

    'expiration' => null,

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | A prefix on issued tokens lets secret-scanning services recognise one in
    | a public repository. Unused while no tokens are issued; set it in the
    | same change that starts issuing them.
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | The middleware Sanctum runs for a first-party request. AuthenticateSession
    | invalidates other sessions for the same user when a password changes,
    | which is why it is present rather than commented out.
    |
    */

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
