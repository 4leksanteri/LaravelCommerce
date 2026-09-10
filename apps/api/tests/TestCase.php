<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Makes the next request look like one from the browser.
     *
     * Sanctum only starts a session when Origin or Referer matches
     * SANCTUM_STATEFUL_DOMAINS (ADR 0002), and a test client sends neither. A
     * test that skips this gets an anonymous request with no session, which is
     * not what the application does in production and not what should be
     * asserted against.
     *
     * `localhost` is pinned in phpunit.xml and is the host Laravel's test
     * client requests, so this matches without depending on which port the
     * developer moved the web application to.
     *
     * Note what is *not* covered by any of this: CSRF. Laravel's
     * ValidateCsrfToken middleware short-circuits when the application is
     * running tests, so every request here passes the check without having a
     * token. CSRF is verified against the running stack instead - see
     * SessionAuthenticationTest for the cookie itself.
     */
    protected function fromFrontend(): static
    {
        return $this->withHeaders([
            'Origin' => 'http://localhost',
            'Referer' => 'http://localhost/',
        ]);
    }
}
