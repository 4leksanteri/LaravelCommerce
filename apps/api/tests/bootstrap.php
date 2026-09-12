<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test bootstrap
|--------------------------------------------------------------------------
|
| The whole test environment, set here rather than in phpunit.xml's <php>
| block. That is not a style preference - the <php> block does not work in this
| stack, and it does not work silently:
|
|   PHPUnit's <env force="true"> writes  getenv() and $_ENV
|   Docker's value lives in              $_SERVER
|   Laravel's Env reads                  $_SERVER first, then $_ENV
|
| Every variable docker-compose.yml passes the api service is therefore
| immune to phpunit.xml, and the override looks applied while doing nothing.
| APP_ENV was the one that mattered: left at the container's `local`, Laravel's
| runningUnitTests() is false, the CSRF middleware stops exempting itself, and
| every POST in the suite fails with a 419 for no visible reason.
|
| Setting all three superglobals below is what actually takes effect.
|
*/

require __DIR__.'/../vendor/autoload.php';

/**
 * Sets a variable everywhere Laravel might read it from.
 */
$put = static function (string $name, string $value): void {
    putenv("{$name}={$value}");
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
};

// Laravel branches on this in more places than is obvious. It has to be
// `testing` before the application boots.
$put('APP_ENV', 'testing');
$put('APP_DEBUG', 'true');
$put('APP_MAINTENANCE_DRIVER', 'file');

// Not a secret. Fixed so the suite is deterministic and runs without a .env.
$put('APP_KEY', 'base64:Q2xhdWRlQ29tbWVyY2VUZXN0S2V5MDEyMzQ1Njc4OTA=');

/*
| The suite runs against PostgreSQL rather than SQLite, because this is a
| marketplace: money is exact numeric, invariants are CHECK constraints and
| uniqueness is partial indexes. SQLite has different semantics for all three.
|
| It runs against a database of its own, named from the development one rather
| than written out a second time. RefreshDatabase drops and recreates every
| table it finds, so a run pointed at DB_DATABASE would take the developer's
| own data with it.
|
| docker/postgres/init/01-create-test-database.sh creates "${POSTGRES_DB}_test"
| from the same variable, so renaming the database renames both.
*/
$database = getenv('DB_DATABASE');

if ($database === false || $database === '') {
    $database = 'laravel_commerce';
}

if (! str_ends_with($database, '_test')) {
    $database .= '_test';
}

$put('DB_CONNECTION', 'pgsql');
$put('DB_DATABASE', $database);

/*
| In memory, and not in the services the development stack happens to be
| running. A suite that shares a cache store with a running application is a
| suite whose rate-limiter tests depend on who used the site last.
*/
$put('CACHE_STORE', 'array');
$put('SESSION_DRIVER', 'array');
$put('QUEUE_CONNECTION', 'sync');

// Never SMTP. Mailpit is running next door and would happily accept everything
// the suite sends, which is a slower run and a cluttered inbox for no benefit.
$put('MAIL_MAILER', 'array');

/*
| Nowhere, for the same reason as the three above: a suite should not write to
| something the developer is also using.
|
| The application logs to its own stream now (ADR 0044), and this suite spends
| its time provoking the refusals it asserts on - "this is sold out", "only 3
| of these are left" - each of which is reported as an ERROR with a stack in
| it. Pointed at the stream they bury the output of `make test` under tens of
| kilobytes of JSON. Pointed at a file, which is where they went before, they
| are most of how storage/logs/laravel.log reached 73 MB.
|
| Set LOG_CHANNEL=stderr for a single run when what a test logs is the thing
| being investigated.
*/
$put('LOG_CHANNEL', 'null');

// bcrypt's work factor. 4 is the minimum and makes the suite several times
// faster; the cost is irrelevant because no test asserts on hashing time.
$put('BCRYPT_ROUNDS', '4');

/*
| Sanctum decides a request may hold a session by matching Origin or Referer
| against this list. Laravel's test client requests http://localhost, so
| `localhost` is what makes TestCase::fromFrontend() produce a stateful
| request.
|
| Pinned so the suite does not inherit whichever port the developer moved the
| web application to. Without it, changing WEB_PORT silently turns every
| authentication test into an assertion about an anonymous request.
*/
$put('SANCTUM_STATEFUL_DOMAINS', 'localhost');

// Where the links in verification and password-reset mail point. Pinned for
// the same reason, and because the tests assert on it.
$put('FRONTEND_URL', 'http://localhost:3000');

/*
| Stripe keys that are shaped right and belong to nobody.
|
| Compose hands this container whichever test keys the developer configured,
| and until these lines existed the suite used them: every checkout opened a
| real PaymentIntent, and a real customer, in a real account.
|
| It is also what made the suite non-deterministic. OpenPaymentsForCheckout
| sends an idempotency key derived from the buyer's id, RefreshDatabase hands
| out the same ids on every run with a different random email each time, and
| Stripe refuses a key reused with different parameters. So the call failed for
| every buyer whose key a previous run had spent and succeeded for the rest -
| and a checkout that succeeded wrote a payment row, which is a row the order
| tests then wrote a second time and PostgreSQL refused.
|
| TestCase puts FakeStripe where the SDK's network client goes, so nothing
| leaves the process whatever these say. They are pinned as well so that a
| machine with keys configured and a machine without one boot the same
| application: without a key, binding StripeClient throws instead.
*/
$put('STRIPE_SECRET', 'sk_test_suite');
$put('STRIPE_WEBHOOK_SECRET', 'whsec_suite');
$put('STRIPE_PUBLISHABLE_KEY', 'pk_test_suite');
