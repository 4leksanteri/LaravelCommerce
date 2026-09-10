# API Engineering Guidelines

Rules specific to the Laravel application in `apps/api`.

The repository-level [CLAUDE.md](../../CLAUDE.md) remains authoritative for
project-wide rules: clean code, naming, money, security, testing philosophy,
dependencies, Git and the definition of done. Do not duplicate them here unless
Laravel-specific clarification is needed.

If this file conflicts with the root `CLAUDE.md`, stop and name the conflict
rather than silently choosing one.

---

# 1. Purpose

`apps/api` is the marketplace. It owns:

- the database and every migration
- the domain and every rule
- authentication, authorization and sessions
- eventually, payments and payouts

It renders nothing. There is no Blade UI, no asset pipeline, no Vite, and no
`package.json` - all of which the Laravel skeleton ships and all of which were
removed. The only thing a person looks at is `apps/web`.

---

# 2. Technology

- Laravel 13, PHP 8.5
- PostgreSQL 18
- Sanctum, for session authentication only
- Pint (formatting), Larastan (static analysis), PHPUnit
- FrankenPHP in a container

Use the framework before adding a package. Laravel already has authorization,
validation, queues, scheduling, rate limiting, events and file storage.

---

# 3. Structure

```text
apps/api/
├── app/
│   ├── Http/
│   │   ├── Controllers/     thin; one action each where it reads better
│   │   ├── Middleware/      `stateful`, and whatever else earns a name
│   │   ├── Requests/        validation, and only validation
│   │   └── Resources/       the API representation
│   ├── Models/
│   └── Providers/           password policy, mail links, rate limiters
├── bootstrap/app.php        middleware, routing, exception rendering
├── config/                  the only place env() may be called
├── database/migrations/
├── openapi.json            generated from the code. Never edited.
├── routes/
│   ├── api/v1.php           the entire public surface, for v1
│   ├── web.php              deliberately empty; see the file
│   └── console.php
├── tests/
│   ├── bootstrap.php        the test environment; read it before phpunit.xml
│   └── Feature/
├── pint.json
├── phpstan.neon
└── phpunit.xml
```

The structure grows by domain, not by technical layer. When sellers arrive,
`app/Actions/Sellers/` and `app/Models/Seller.php` arrive with them. Do not
create `app/Services/`, `app/Helpers/` or `app/Support/` as empty architecture
waiting to be filled.

---

# 4. Request flow

```text
Route
  ↓
Form request        validates. Throws 422 with field errors. Nothing else.
  ↓
Controller          resolves the actor, calls one action, returns a resource.
  ↓
Action              the decision. Where the interesting code lives.
  ↓
Model               persistence.
  ↓
API resource        representation.
```

## Controllers stay thin

A controller coordinates. It does not decide.

Bad:

```php
public function store(Request $request)
{
    $validated = $request->validate([...]);

    if ($request->user()->seller->status !== 'approved') {
        abort(403);
    }

    $product = Product::create([...]);
    $product->images()->createMany([...]);
    Mail::to(...)->send(new ProductPublished($product));

    return response()->json($product);
}
```

Prefer:

```php
public function store(StoreProductRequest $request, PublishProduct $publish): ProductResource
{
    return new ProductResource(
        $publish->handle($request->seller(), $request->validated()),
    );
}
```

The rules moved into `PublishProduct`, where they can be tested without an HTTP
request and reused from a console command.

## Never return a model directly

An API resource is an allowlist. Returning a model publishes whatever columns
the table happens to have, which is how an internal flag or a hash reaches a
browser after an unrelated migration. `SessionAuthenticationTest` asserts the
exact key set of `UserResource` for this reason.

---

# 5. Routing

**One route file per API version**, each mounted at its own prefix in
`bootstrap/app.php`. `routes/api/v1.php` is v1; a v2 is a new file and one more
`Route::prefix(...)->group(...)` beside it, with nothing about v1 moving.

Do not reach for `apiPrefix`. It is shorter and it pins the whole application
to one version forever, because there is no second prefix to give a second
file. Reasoning is in
[ADR 0005](../../docs/architecture/0005-api-versioning.md), which also says
what does and does not count as a breaking change.

**A route outside the versioned prefix is unreachable.** The Next.js server
proxies `/api/**` and nothing else, so a route elsewhere is served to nobody.
`ApiSurfaceTest` fails when one appears, with one exception: `/up`, the
container health probe, which does not go through the proxy and should not have
to track an API version.

This is why `config/sanctum.php` sets `'prefix' => 'api/v1/auth'` rather than
leaving Sanctum's default `/sanctum`, and why `filesystems.local.serve` is
false - `true` publishes `GET|PUT /storage/{path}`, outside the prefix, that
nothing calls.

`routes/web.php` is deliberately empty and should stay that way. It exists
because registering it is what defines the `web` middleware group, which
Sanctum's CSRF cookie route uses.

---

# 6. Models

Models represent durable domain concepts. They hold relationships, casts and
scopes.

They do not hold workflows. A method named `$order->completeAndPayoutSeller()`
is an action wearing a model's clothes.

## Casts

Laravel 13 declares casts in a `casts()` method. Use it, and know that
**Larastan does not read it unless told to** - `parseModelCastsMethod: true` in
`phpstan.neon` is what makes that work. Without it every cast attribute is
typed from the migration instead, so `email_verified_at` reads as `string` and
calling a date method on it passes analysis and fails at runtime.

## Constraints belong in the database

Use foreign keys, unique constraints, CHECK constraints and partial indexes.
The database is the last thing standing between a bug and bad data, and it is
the only layer that holds under concurrency.

An invariant enforced only in PHP is enforced only in the code paths somebody
remembered.

## Migrations

Every model change that needs a migration includes one. Never edit an applied
migration to make today easier. Call out anything destructive explicitly.

---

# 7. Validation

Validation lives in form requests, and validation is _all_ it does. A form
request that queries the database to decide whether an action is allowed is
doing authorization, which belongs in a policy.

Return field-level errors. Laravel's 422 shape - `{ message, errors: { field:
[...] } }` - is what the frontend renders beside the field that caused it, and
`ApiError.validationErrors` in `apps/web` depends on it.

---

# 8. Authorization

Laravel's policies and gates exist. Use them rather than scattering ownership
checks through controllers.

Scope every query to the caller:

```php
// Bad, when the caller may only touch their own shop.
Product::findOrFail($id);

// Better.
$request->seller()->products()->findOrFail($id);
```

A 404 for something that exists but is not yours is fine, and often better than
a 403, because it does not confirm the thing exists.

Send the **answer**, not the inputs. When the frontend needs to know whether to
draw a button, the resource carries `can_edit: true`, not the role and status
for the browser to re-derive. See root `CLAUDE.md` section 4.

---

# 9. Configuration

`env()` is called in `config/*.php` and nowhere else. Larastan enforces this
(`noEnvCallsOutsideOfConfig`), and the reason is concrete: `config:cache` in
production makes every `env()` call outside a config file return null.

There is no `.env` in this application. Configuration arrives as real
environment variables from Compose. A new variable goes in three places, in the
same commit:

```text
.env.example              documented, with why it exists
docker-compose.yml        the development allowlist
docker-compose.prod.yml   the production allowlist
```

A variable missing from the compose files does not reach the container, however
carefully it is set in `.env`.

---

# 10. Errors

Every response is JSON. `shouldRenderJsonWhen` returns true unconditionally,
because there is no HTML surface and no client that would read one.

- Use domain exceptions where they clarify: `OrderAlreadyShipped` rather than
  returning `false`.
- Translate them to status codes at the boundary, not in the action.
- Never leak a stack trace, a file path, a SQL fragment or an internal hostname
  to a client.
- Keep 401 and 403 distinct. See ADR 0002.

---

# 11. Static analysis

```bash
make lint-api        # pint --test, then phpstan
```

PHPStan runs at **level 8** with Larastan. Level 8 reasons about null, which is
the class of bug worth catching. Levels 9 and 10 tighten `mixed`, which in
Laravel mostly means annotating request input that has already been validated.

`checkModelProperties: true` is on: a `@property` annotation that disagrees with
the column it names is worse than no annotation, because every caller believes
it. It also means a factory's `definition()` is typed
`array<model-property<Model>, mixed>`, so a factory cannot keep seeding an
attribute a migration renamed.

**Do not silence an error.** No `@phpstan-ignore`, no baseline entries, no
`assert()` to narrow a type, no cast added purely to make a message go away.
Each of those trades a real finding for quiet. Fix the cause, or if the
analyser is genuinely wrong, say so in the change rather than hiding it.

Where a type needs narrowing, write a real branch:

```php
$user = $request->user();

if (! $user instanceof User) {
    throw new AuthenticationException();
}
```

That proves the type instead of promising it, and it fails closed if a
middleware change ever makes it reachable.

---

# 12. Formatting

Pint owns PHP formatting, with Laravel's preset plus `declare_strict_types`.

Strict types are on for every file. It means a scalar passed to a typed
parameter is not silently coerced, which in an application that handles money
is the difference between a bug and a `TypeError`.

Prettier never touches PHP. There is no Prettier PHP plugin here on purpose.

```bash
make format      # pint, then prettier for everything else
```

---

# 13. Testing

```bash
make test        # phpunit, against PostgreSQL
```

The suite runs against PostgreSQL, in a database named by appending `_test` to
`DB_DATABASE` in `tests/bootstrap.php`. `RefreshDatabase` drops every table it
finds, which is why that name is derived in one place rather than written twice.

## The test environment lives in tests/bootstrap.php, not phpunit.xml

This is worth knowing before you try to change a value for the suite.

**`<env force="true">` in phpunit.xml cannot override a variable Docker
exported, and it fails silently.** PHPUnit's force writes `getenv()` and
`$_ENV`; Docker's value lives in `$_SERVER`; Laravel's `Env` reads `$_SERVER`
first. The override looks applied and does nothing.

Every variable `docker-compose.yml` passes the api service is therefore immune
to phpunit.xml, which is most of the interesting ones. `APP_ENV` was the one
that mattered: left at the container's `local`, Laravel's `runningUnitTests()`
is false, the CSRF middleware stops exempting itself, and every POST in the
suite fails with a 419 for no visible reason.

Set it in `tests/bootstrap.php`, which writes all three superglobals.

## Authentication tests need `fromFrontend()`

Sanctum only starts a session when Origin or Referer matches, and a test client
sends neither. `TestCase::fromFrontend()` adds them. A test that skips it
asserts against an anonymous request, which is not what the application does in
production.

CSRF is **not** covered by any of this: Laravel's `ValidateCsrfToken` exempts
itself while tests run, so every request here passes without a token. The CSRF
path is verified against the running stack instead.

Read root `CLAUDE.md` section 10 before adding tests. The one Laravel-specific
trap worth repeating here:

**`getJson()` sets `Accept: application/json`, and Laravel branches on that
header in places you would not expect.** The authentication middleware is one:
with the framework default, an unauthenticated request without that header got
a 500 rather than a 401, and a suite written entirely in `getJson()` passed
anyway. Test the un-negotiated path where it matters.

`php artisan test` is not used; `composer test` runs `phpunit` directly.
Collision's test command reads a `.env` file that this container deliberately
does not have, and warns on every test about it.

---

# 14. Adding a dependency

```bash
make composer ARGS="require vendor/package"
```

Inside the container, so the lockfile is resolved against the PHP version and
extensions that actually run it.

Read root `CLAUDE.md` section 16 first. And note the removed scaffold:
`laravel/boost` is deliberately not installed, and the Laravel skeleton's own
`CLAUDE.md` telling you to install it was deleted. That is a decision, not an
omission.

`laravel/pao` **is** installed, and is why Pint, PHPStan and PHPUnit emit
structured output rather than prose. That output is designed to be read by an
agent; when it names an error identifier, the documentation for it is at
`https://phpstan.org/error-identifiers/<identifier>`.

---

# 15. Before starting a task here

1. Read this file and the root `CLAUDE.md`.
2. Read ADR 0002 before touching authentication, and ADR 0004 before touching
   money.
3. Look at how the nearest existing code does it.
4. Ask which client calls the thing you are adding. If the answer is the
   browser, it belongs under `/api/v1` and the matching TypeScript type in
   `apps/web/src/lib/api/types.ts` changes in the same commit.
