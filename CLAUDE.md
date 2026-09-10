# Laravel Commerce - Engineering Guidelines

## 1. Purpose

Laravel Commerce is a marketplace. Independent sellers run shops; buyers
browse, order, review and message them.

The architecture is two applications and one rule about how they talk:

- Laravel 13, PHP 8.5, PostgreSQL 18 - the API. Owns the database, the domain,
  and every decision.
- Next.js 16, React 19, TypeScript - the web application. Renders, and decides
  nothing.
- Docker Compose for both, in development and in production.

The API is not published to the internet. The browser reaches it through the
Next.js server and by no other route.

---

# 2. Core Engineering Principles

When modifying this project, optimise for:

1. Correctness
2. Readability
3. Maintainability
4. Simplicity
5. Testability
6. Security
7. Performance, when evidence shows it matters

Do not optimise for cleverness.

Prefer boring, explicit code over unnecessary abstractions.

Do not introduce architecture because it might be useful someday. Implement
the simplest design that cleanly supports the current requirement while
leaving reasonable room for extension.

---

# 3. Repository Structure

```text
/
├── apps/
│   ├── api/              Laravel 13. The domain, the database, the rules.
│   └── web/              Next.js 16. Rendering.
│
├── docker/
│   ├── api/              Dockerfile, entrypoints, PHP configuration
│   ├── web/              Dockerfile
│   └── postgres/init/    runs once, on first volume creation
│
├── docs/architecture/    ADRs: why a decision was made
│
├── docker-compose.yml       development
├── docker-compose.prod.yml  production, a separate file and not an overlay
├── Makefile
├── .env.example
├── CLAUDE.md
└── AGENTS.md
```

Do not create new top-level directories without a clear architectural reason.

There is no `packages/` directory. Add one when two applications genuinely
share code, not in anticipation of it. Two applications in two languages share
less than people expect.

---

# 4. The Boundary

This is the rule the whole repository depends on.

```text
Browser
   │
   │ relative /api/v1/...   (same origin, session cookie)
   ▼
Next.js server
   │
   │ reverse proxy, internal network only
   ▼
Laravel / PHP 8.5
   │
   ▼
PostgreSQL
```

Rules:

- **The browser never calls Laravel directly.** It cannot: in production the
  API publishes no port and is reachable only from the web container.
- **The web application never touches PostgreSQL.** Not for domain data, not
  for a quick read, not for a report.
- **A rule the API owns is not re-implemented in the frontend.** The API sends
  the _answer_ - `can_edit`, `actions`, `is_visible` - not the inputs for the
  client to re-derive. A rule copied into the browser is the copy that goes
  stale, and it is the copy an attacker controls.
- **Secrets belong to the API.** Never put one in a variable named
  `NEXT_PUBLIC_*`: Next inlines those into the browser bundle. A publishable
  key is publishable; a secret key is not.
- **The API's hostname is server-side only.** In the frontend it exists in
  exactly one module, and that module imports `server-only` so that reaching
  for it from a Client Component is a build error rather than a hostname in a
  JavaScript bundle.

Reasoning, and the three proxy behaviours that are load-bearing, are in
[docs/architecture/0003-the-proxy-boundary.md](docs/architecture/0003-the-proxy-boundary.md).

---

# 5. Domain Logic

Business logic must not accumulate inside:

- Laravel controllers
- API resources
- Eloquent models
- React components
- data-fetching helpers

Keep controllers thin. A request should broadly follow:

```text
HTTP request
    ↓
Route
    ↓
Form request           validation, and only validation
    ↓
Controller             coordinates, and does nothing else
    ↓
Action / service       the decision
    ↓
Model / persistence
    ↓
API resource           representation
```

For example:

```php
public function store(StoreProductRequest $request, CreateProduct $createProduct): ProductResource
{
    $product = $createProduct->handle(
        seller: $request->seller(),
        attributes: $request->validated(),
    );

    return new ProductResource($product);
}
```

The interesting behaviour belongs in `CreateProduct`, not in the HTTP layer.

---

# 6. Clean Code Rules

## Functions

Functions should perform one coherent task, have descriptive names, avoid
hidden side effects, and return predictable values.

Prefer:

```php
calculateOrderTotal($items)
```

over:

```php
process($data)
```

Avoid functions with several boolean parameters. Prefer explicit domain
concepts.

Bad:

```php
createShop($name, true, false, true);
```

Better:

```php
createShop(
    name: $name,
    status: ShopStatus::PendingApproval,
);
```

## Naming

Names should communicate domain meaning. Prefer:

```text
outstanding_balance    seller    order    payout    dispute    listing
```

Avoid vague names such as `data`, `info`, `item`, `obj`, `thing`, `manager`,
`helper`, `misc`, unless the meaning is genuinely obvious in a very small
scope.

Use marketplace terminology consistently. A `seller` is not sometimes a
`vendor` and sometimes a `merchant`.

## Comments

Comments explain **why**, not what the code already says.

Bad:

```php
// Increment the count
$count++;
```

Useful:

```php
// Sellers price in their own currency, so a basket spanning three shops
// becomes three orders and three payments.
```

Prefer making the code understandable without comments wherever possible.

## Duplication

Do not aggressively abstract after seeing something twice. A small amount of
duplication is preferable to the wrong abstraction. Extract shared behaviour
once the common concept is clear.

## Control flow

Prefer early returns, guard clauses and explicit conditions. Avoid deeply
nested logic.

---

# 7. Money

**Integer minor units. Everywhere. Always.**

A price is `2499` and a currency is `EUR`. It is never `24.99`, and it is
never a float.

Rules:

- Never use a floating-point number for money. `0.1 + 0.2` is a defect in a
  marketplace, not a curiosity.
- Currency is always explicit and travels with the amount.
- **Never sum across currencies.** Sellers price in their own currency, so an
  aggregate is grouped by currency and a total that spans two of them does not
  exist. Where a single figure is genuinely needed, it is a display conversion
  with a recorded rate, labelled as such.
- Rounding is deliberate and tested. Do not silently round.
- Anything a buyer was shown is snapshotted onto the order. A price that
  changes tomorrow must not change what somebody agreed to today.
- The frontend formats money. It never computes it.

Reasoning is in
[docs/architecture/0004-money-and-currency.md](docs/architecture/0004-money-and-currency.md).

---

# 8. Authorization

Treat marketplace data as sensitive. Somebody's orders, addresses and messages
are theirs.

- **Never trust an ID from the client as proof that the caller may access the
  thing it names.** An ID says what is wanted, never who may have it.
- Every seller-owned query is scoped to the authenticated user's seller.
- The API decides. A hidden button in the browser is not an authorization
  control, and neither is a guarded route.

Bad:

```php
Product::findOrFail($id);
```

when the caller may only touch their own shop's products.

Prefer:

```php
$request->seller()->products()->findOrFail($id);
```

or an explicit policy. Laravel's authorization exists; use it rather than
scattering ownership checks through controllers.

## 401 and 403 are not interchangeable

```text
401   there is no session, or it expired.   The frontend clears state and
                                            sends the person to sign in.
403   the session is fine, the action is    The frontend explains it, and
      not allowed.                          does not sign anybody out.
```

Answering one where the other is meant makes a real interaction wrong.

---

# 9. API Design

Resources live under a version prefix:

```text
/api/v1/products
/api/v1/sellers/{seller}/orders
/api/v1/orders/{order}/messages
```

Infrastructure that probes the application stays unversioned, because a probe
should not have to track API versions:

```text
/up          Laravel's health route, used by the container health check
```

**Every route lives under `/api/v1`.** That is not a convention, it is what
the proxy forwards, and `tests/Feature/ApiSurfaceTest.php` fails when a route
appears outside it. A route the proxy does not forward is reachable by
nothing.

Use HTTP semantics properly: `GET` reads, `POST` creates, `PATCH` partially
updates, `DELETE` removes.

Return consistent error structures. Never leak a stack trace, a file path, a
SQL fragment or an internal hostname to a client.

## The contract is generated

The API is described once, by the Laravel code. Everything else follows from
it:

```text
routes, form requests, API resources
        │  Scramble
        ▼
apps/api/openapi.json
        ├──▶ apps/web/src/lib/api/generated/schema.d.ts   the frontend's types
        └──▶ docs/postman/collection.json                 the Postman collection
```

**Never hand-edit any of those three.** Change the Laravel code and run
`make api-docs`. `make api-check` fails when the committed contract is not what
the code produces, and it runs as part of `make check`.

A generated artifact that is committed has to be **deterministic**, or the
check is worthless. Reasoning, and the three sources of randomness that had to
be removed, are in
[docs/architecture/0006-the-generated-api-contract.md](docs/architecture/0006-the-generated-api-contract.md).

---

# 10. Testing

New business behaviour includes tests.

- Feature tests for endpoints, which is most of what exists.
- Unit tests for pure domain logic, once there is some.
- Test behaviour, not implementation details.

**The suite runs against PostgreSQL, not SQLite.** Money is exact numeric,
invariants are CHECK constraints and uniqueness is partial indexes; SQLite has
different semantics for all three and accepts values PostgreSQL rejects. A
suite that passes on an engine nobody deploys reports on an application nobody
runs.

Tests live with the application they test:

```text
apps/api/tests/Feature/
apps/api/tests/Unit/        added with the first thing worth unit testing
```

Do not create a repository-wide `tests/` directory.

## Test the un-negotiated path

`getJson()` sets `Accept: application/json`. Laravel branches on that header in
places you would not expect - the authentication middleware among them - so a
suite written entirely in `getJson()` can pass while every client that omits
the header gets a 500. `SessionAuthenticationTest` has a regression on exactly
that.

When fixing a bug, add a test that fails without the fix.

---

# 11. Security

Always consider authorization, object ownership, file upload validation,
secrets management, input validation, mass assignment, injection and session
security.

- Never commit `.env`, credentials, API keys or private keys.
- Keep `.env.example` updated when a variable is introduced, and add it to the
  `environment:` block in both compose files - a variable that is not listed
  there does not reach the container.
- Uploaded files are untrusted. Validate size and type, and do not trust a
  client-supplied MIME type.
- Never log a password, a token, an API key or a full payment payload.

---

# 12. Environment and Configuration

**There is one `.env` file, at the repository root.** There is deliberately no
`apps/api/.env`.

```text
.env                 the values
docker-compose.yml   the allowlist: which service sees which value
container env        what Laravel and Next actually read
```

Laravel reads its configuration from the container environment rather than
from a file it parses itself. Two files describing one deployment is how a
value gets changed in one of them.

Consequences worth knowing:

- Configuration is read through `config()`, never `env()`, outside
  `config/*.php`. PHPStan enforces this - `noEnvCallsOutsideOfConfig` is on -
  because a cached config file makes every `env()` call outside config return
  null in production.
- `SANCTUM_STATEFUL_DOMAINS`, `FRONTEND_URL` and `WEB_PORT` describe the same
  origin from three angles. Change one and check the others; a mismatch means
  sessions silently stop working.

---

# 13. Docker

Development is `docker compose up`, or `make dev`. Everything runs in
containers; nothing needs PHP or PostgreSQL on the host.

```text
postgres   PostgreSQL 18
mailpit    a real SMTP server that delivers nothing. Development only.
api        Laravel on FrankenPHP
web        Next.js
```

Four in development, three in production - Mailpit has no counterpart there,
where `MAIL_*` points at a real provider and the compose file refuses to start
without one.

There is still no Redis and no queue worker, because sessions, cache and queued
jobs all live in PostgreSQL and nothing yet needs otherwise. **Add a service in
the change that gives it a job to do**, not before. Mailpit earned its place
the day registration started sending mail.

`docker-compose.prod.yml` is a separate file, not an overlay. An overlay
inherits what it does not override, and what it would inherit is a set of bind
mounts pointing at somebody's working tree.

Do not bake secrets into an image. Anything in a layer is readable by anybody
who can pull it.

Do not introduce Kubernetes or an orchestrator unless explicitly asked.

---

# 14. Developer Commands

```bash
make setup      first run: .env, dependencies, containers, database
make dev        start and follow logs
make down       stop
make reset      destroy containers and data, then set up again

make check      lint + typecheck + test. The gate.
make lint       Pint, PHPStan, ESLint, Prettier
make format     apply Pint and Prettier
make test       PHPUnit against PostgreSQL

make artisan ARGS="make:model Product -m"
make composer ARGS="require stripe/stripe-php"
make psql
make routes
```

If commands change, update the Makefile and the README together.

Run `make check` before calling anything done.

---

# 15. Formatting and Linting

## Ownership

Each tool owns one thing, and they do not overlap:

```text
PHP                         Pint          apps/api/pint.json
PHP static analysis         PHPStan       apps/api/phpstan.neon
TS / TSX code quality       ESLint        apps/web/eslint.config.mjs
TS / CSS / MD / JSON / YAML Prettier      .prettierrc.json
```

- Do not use Prettier for PHP. There is no Prettier PHP plugin here on
  purpose: two formatters over one language is a fight, not a configuration.
- Do not add PHP CS Fixer or PHP_CodeSniffer while Pint is configured. Pint is
  PHP CS Fixer, with Laravel's preset already chosen.
- `eslint-config-prettier` is applied last in the ESLint config. It switches
  off every rule that would otherwise argue with Prettier about layout, which
  is what keeps this boundary enforceable rather than merely stated.
- Generated and vendored files are not formatted. See `.prettierignore`.

## Character set

**Source code is ASCII, comments included.** No smart quotes, no typographic
dashes, no stray characters from another script. Where a specific character
matters, write the escape: `\\u00a0` says exactly which space is meant, while
the character itself is indistinguishable from a plain one in review.

**No em or en dashes anywhere, prose included.** Write `-`, or restructure the
sentence. This applies to Markdown as well as code.

Invisible and confusable characters are banned everywhere: non-breaking
spaces, zero-width characters, byte-order marks, bidirectional overrides. A
non-breaking space that drifts into a command or a test expectation costs real
time to find.

Box-drawing and arrow characters in the architecture diagrams are fine and are
expected to stay. They are deliberate and confusable with nothing.

The rule is enforced, not merely stated:

```bash
make charset      # also runs as part of `make lint`
```

Two exemptions, both narrow, both in `scripts/check-charset.mjs`:

```text
LICENSE               the AGPL as published; re-punctuating it would make it
                      a modified licence
apps/web/AGENTS.md    written by `next dev`, which re-adds its own block on
                      every run
```

---

# 16. Dependencies

Before adding one, ask:

1. Does the framework already solve this?
2. Is it actively maintained?
3. Does the benefit justify another dependency?
4. Is it the right size for the problem?

Avoid large libraries for trivial functionality. Do not add two packages that
solve the same problem.

**Read the version that is actually installed before configuring it.** Next,
Laravel and their ecosystems move configuration between majors, and a
remembered option that no longer exists is often accepted silently as an
unknown key rather than rejected. Inspect `node_modules` or `vendor`, or the
published metadata, rather than recalling the syntax. Two examples from
building this repository: `parseModelCastsMethod` defaults to off in Larastan
and quietly types every cast attribute from the migration instead, and Next 16
removed the `eslint` key from `next.config.ts` entirely.

Installing one dependency must not upgrade unrelated ones. If a version
conflict makes that unavoidable, say so before changing anything else.

## Laravel Boost is deliberately not installed

The Laravel skeleton ships a `CLAUDE.md` and `AGENTS.md` telling an agent to
install `laravel/boost` and regenerate the guidelines. Those files were
removed, and this is the file that replaced them.

Boost would add an MCP server and a generated instruction set alongside
hand-written ones that already say what this project wants. If it is ever
wanted, that is a deliberate decision to take and write down, not a setup step
to follow because a scaffold suggested it.

---

# 17. Git

Keep changes focused. Do not mix unrelated refactors into feature work.

```text
feat: accept an order and hold the payment
fix: prevent a seller reading another shop's messages
refactor: extract payout release into an action
test: cover partial refunds across currencies
chore: pin the PostgreSQL image to 18
```

Never commit `.env`, secrets, build output, dependency directories or uploaded
files.

---

# 18. Definition of Done

A change is not complete because it works locally. Before considering it done,
verify where applicable:

- the code follows the existing architecture
- business logic is in the right layer
- input is validated
- authorization is enforced and scoped to the caller
- migrations are included
- tests cover the important behaviour, and pass
- `make check` passes
- new environment variables are in `.env.example` **and** in both compose files
- no secrets are committed
- the API response shape and its TypeScript type were changed together
- documentation is updated where a decision was made

---

# 19. Rules for Claude

## Before implementing

1. Read this file.
2. Read the `CLAUDE.md` of the application you are changing.
3. Read the relevant ADR under `docs/architecture/` before touching the proxy,
   authentication or money.
4. Inspect nearby code before inventing a pattern.
5. Identify whether the change crosses an architectural boundary.

If a request would significantly change the architecture, explain the proposed
change before implementing it.

## While implementing

- Make focused changes.
- Do not rewrite unrelated files.
- Do not invent requirements that were not requested.
- Do not introduce speculative abstractions.
- Do not add dependencies without justification.
- Keep domain logic independent of HTTP and UI concerns.
- Preserve the boundary in section 4.
- Add tests for meaningful new behaviour.

## Before finishing

Run `make check`. Inspect the diff for accidental changes.

Then summarise: what changed, which architectural decisions were taken, what
was verified and how, and what remains.

**Do not claim a command or a test passed unless it was actually run and
actually passed.**

---

# 20. Current Phase

The repository is at its **foundation**. What exists:

```text
the monorepo, both applications, both toolchains
the proxy, and session authentication through it
accounts: register, sign in, sign out, verify an address, reset a password
Docker for development and production, with Mailpit for local mail
five ADRs covering the foundations
```

What deliberately does not exist yet: **the domain**, and **the frontend for
any of the above**. There are no sellers, products, orders, payments, disputes,
reviews or messages, and no Stripe integration. The API sends verification and
reset links to `/verify-email` and `/reset-password` on the web application,
and neither page has been built - the endpoints behind them work and are
tested.

The first milestone is:

```text
Accounts                                   done
       ↓
The pages that go with them                next
       ↓
A seller applies, and is approved
       ↓
A product listing
       ↓
An order
       ↓
A payment held, and released
```

Domain ADRs are written as each of those is built, not in advance. Build
incrementally toward that, and keep the boundary in section 4 intact while
doing it.
