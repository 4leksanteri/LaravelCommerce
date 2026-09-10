<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configurePasswordRules();
        $this->configureNotificationUrls();
        $this->configureRateLimiting();
    }

    /**
     * One definition of what a password has to be, applied wherever one is
     * accepted, so registration and reset cannot drift apart.
     */
    private function configurePasswordRules(): void
    {
        Password::defaults(function (): Password {
            $rule = Password::min(12);

            // Checks the password against Have I Been Pwned's k-anonymity API.
            // Off outside production because it is a network call in the
            // middle of a test run. It fails open when the service is
            // unreachable, so it cannot take registration down.
            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });
    }

    /**
     * Both notifications link to the Next.js application, not to this one.
     *
     * The API is not reachable from a browser, so a link pointing at it would
     * be a dead link in somebody's inbox. The frontend owns the page; it reads
     * the parameters out of the URL and calls the endpoint that does the work.
     */
    private function configureNotificationUrls(): void
    {
        VerifyEmail::createUrlUsing(function (User $user): string {
            // Relative, deliberately. An absolute signature covers the host,
            // and the host this application sees is whatever the proxy
            // forwarded - so a signature generated in a queued job with no
            // request would not match one validated during a web request. A
            // relative signature covers the path and query only, and the path
            // is the same from everywhere.
            $signed = URL::temporarySignedRoute(
                'auth.email.verify',
                now()->addMinutes((int) config('auth.verification.expire', 60)),
                [
                    'id' => $user->getKey(),
                    'hash' => sha1($user->getEmailForVerification()),
                ],
                absolute: false,
            );

            // The frontend rebuilds this exact path from the four values
            // below. The query is `expires` then `signature`, in that order,
            // because the signature is computed over the raw query string and
            // reordering it invalidates the link. VerifyEmailTest walks the
            // real notification and asserts the round trip.
            [$path, $query] = array_pad(explode('?', $signed, 2), 2, '');
            parse_str($query, $parameters);

            return self::frontendUrl('/verify-email', [
                'id' => $user->getKey(),
                'hash' => Str::afterLast($path, '/'),
                'expires' => $parameters['expires'] ?? '',
                'signature' => $parameters['signature'] ?? '',
            ]);
        });

        ResetPassword::createUrlUsing(
            fn (User $user, string $token): string => self::frontendUrl('/reset-password', [
                'token' => $token,
                // Carried because the broker needs it back to find the user
                // and to verify the token against the right row.
                'email' => $user->getEmailForPasswordReset(),
            ])
        );
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private static function frontendUrl(string $path, array $query): string
    {
        $base = rtrim((string) config('app.frontend_url'), '/');

        return $base.$path.'?'.http_build_query($query);
    }

    /**
     * Limits on the endpoints that are worth guessing at.
     *
     * Keyed by email *and* IP, not one or the other. By IP alone, a shared
     * office network locks out colleagues; by email alone, somebody can lock a
     * person out of their own account by guessing at it from anywhere. The
     * pair costs an attacker a new address for every IP they have.
     *
     * A limiter that is exceeded answers 429 with Retry-After, which the
     * frontend shows as "try again in a moment" rather than as a failure.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('auth-login', fn (Request $request) => [
            Limit::perMinute(5)->by('login:'.Str::lower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by('login-ip:'.$request->ip()),
        ]);

        RateLimiter::for(
            'auth-register',
            fn (Request $request) => Limit::perHour(10)->by('register:'.$request->ip())
        );

        // The password broker throttles the same address for 60 seconds by
        // itself. This limits the caller as well, so one IP cannot walk a list
        // of addresses to find out which of them have accounts.
        RateLimiter::for('auth-password-forgot', fn (Request $request) => [
            Limit::perMinutes(10, 3)->by('forgot:'.Str::lower((string) $request->input('email'))),
            Limit::perMinutes(10, 10)->by('forgot-ip:'.$request->ip()),
        ]);

        RateLimiter::for(
            'auth-email-resend',
            fn (Request $request) => Limit::perMinutes(10, 3)
                ->by('resend:'.($request->user()?->getAuthIdentifier() ?? $request->ip()))
        );
    }
}
