# Laravel Commerce

A marketplace for independent sellers. Sellers run shops; buyers browse, order,
review and message them.

Two applications, one boundary:

| Path                   | What it is                                                           |
| ---------------------- | -------------------------------------------------------------------- |
| [`apps/api`](apps/api) | Laravel 13 on PHP 8.5. Owns the database, the domain and every rule. |
| [`apps/web`](apps/web) | Next.js 16. Renders; decides nothing.                                |

The API is not published to the internet. The browser reaches it through the
Next.js server and by no other route.

```text
Browser ──▶ Next.js ──▶ Laravel ──▶ PostgreSQL
            (public)    (internal network only)
```

> **Status: early.** The monorepo, both toolchains, the proxy and Docker for
> development and production all work, and accounts are built: register, sign
> in, sign out, verify an address, reset a password.
>
> Sellers apply to open a shop and staff approve or reject it. An approved shop
> can list products with variants, publish them, and they appear on a public
> storefront. Shoppers have a cart, grouped by shop, that prices itself from the
> catalogue rather than from what it remembers, and checkout turns it into one
> order per shop - snapshotting what was agreed and taking the stock. Orders run
> pending, accepted, shipped, completed, with either party able to cancel early
> and cancellation giving the stock back.
>
> An order nobody acts on expires after three days and hands its stock back,
> through `orders:expire` - a command that knows nothing about what triggers it.
>
> Not built yet: the **frontend pages** for any of it, and payments, disputes,
> reviews or messages. Nothing pays for an order, nothing is emailed to anybody
> about one, and nothing schedules `orders:expire` in production.

---

## Requirements

- Docker and Docker Compose
- Node 24 and pnpm 9, for the JavaScript tooling and for editor support

PHP, Composer and PostgreSQL are **not** required on the host. They run in
containers.

---

## Getting started

```bash
make setup
```

That copies `.env.example` to `.env`, generates an `APP_KEY`, installs the
JavaScript dependencies, builds the images and starts the stack.

```text
http://localhost:3000                    the web application
http://localhost:8000/api/v1/health      the API, development only
http://localhost:8025                    Mailpit - every email the app sends
```

Mailpit is a real SMTP server that accepts everything and delivers nothing.
Registration, email verification and password reset all send mail, and it lands
there instead of in somebody's actual mailbox. It is development only; there is
no Mailpit in the production stack.

If a port is already taken, change `WEB_PORT`, `API_PORT` or `POSTGRES_PORT` in
`.env`. **Changing the web port means changing two other values with it** -
`FRONTEND_URL` and `SANCTUM_STATEFUL_DOMAINS` name the same origin, and if they
disagree, sessions stop working with no error anywhere. See
[ADR 0002](docs/architecture/0002-authentication.md).

---

## Commands

```bash
make dev            start, and follow logs
make down           stop
make reset          destroy containers and data, then set up again
make ps             service status
make logs           follow all logs

make check          lint + typecheck + test. Run this before you are done.
make lint           Pint, PHPStan, ESLint, Prettier
make format         apply Pint and Prettier
make test           PHPUnit, against PostgreSQL

make shell          a shell in the API container
make psql           psql against the development database
make routes         the API's routes
make api-docs       regenerate the OpenAPI spec, TS types and Postman collection
make migrate        run pending migrations

make artisan ARGS="make:model Product -m"
make composer ARGS="require stripe/stripe-php"

# Scheduled work. Nothing runs these automatically - see ADR 0013.
make artisan ARGS="orders:expire"
```

`make help` lists everything.

---

## Production

```bash
make prod-build
make deploy-migrate     # once, before the new containers take traffic
make prod-up
```

`docker-compose.prod.yml` is a separate file, not an overlay on the development
one. In it the API service has no published port at all: it is reachable from
the web container and from nothing else.

TLS terminates in front of the stack. The web container speaks plain HTTP on
3000 and reads the `X-Forwarded-Proto` its proxy sets.

---

## Environment

There is one `.env` file, at the repository root, documented by
[`.env.example`](.env.example). There is deliberately no `apps/api/.env`:
Compose passes each service the values it needs and Laravel reads them from the
container environment.

A new variable goes in three places in the same commit: `.env.example`,
`docker-compose.yml` and `docker-compose.prod.yml`. A variable missing from the
compose files does not reach the container, however carefully it is set.

---

## Working on this

Start with [CLAUDE.md](CLAUDE.md), then the `CLAUDE.md` of the application you
are changing. They are written for both people and coding agents, and they are
the working agreement rather than background reading.

[`docs/architecture/`](docs/architecture/) records why decisions were made:

| ADR                                                          | Subject                                                   |
| ------------------------------------------------------------ | --------------------------------------------------------- |
| [0001](docs/architecture/0001-foundations.md)                | Runtime versions, repository shape, why three services    |
| [0002](docs/architecture/0002-authentication.md)             | Session authentication, and its traps                     |
| [0003](docs/architecture/0003-the-proxy-boundary.md)         | How the browser reaches the API                           |
| [0004](docs/architecture/0004-money-and-currency.md)         | Integer minor units, and why currencies are never summed  |
| [0005](docs/architecture/0005-api-versioning.md)             | One route file per version, and why `apiPrefix` was wrong |
| [0006](docs/architecture/0006-the-generated-api-contract.md) | OpenAPI as the single source for types and Postman        |
| [0007](docs/architecture/0007-sellers-and-shop-approval.md)  | What a seller is, one shop per account, approval          |
| [0008](docs/architecture/0008-authorization.md)              | Policies not conditionals, and what 403 is not            |
| [0009](docs/architecture/0009-products-and-variants.md)      | Where a price lives, and why lists need named collections |
| [0010](docs/architecture/0010-the-cart.md)                   | Why a cart stores no price, and has no grand total        |
| [0011](docs/architecture/0011-checkout-and-orders.md)        | One order per shop, what is snapshotted, when stock moves |
| [0012](docs/architecture/0012-the-order-lifecycle.md)        | Order states, who may move them, and giving stock back    |
| [0013](docs/architecture/0013-scheduled-work.md)             | Commands that know nothing about what triggers them       |

Read 0003 before touching the proxy, and 0002 before touching authentication.
Both contain behaviours that break silently when changed.

---

## The API contract

The endpoints are described once, by the Laravel code, and everything else is
generated from that:

```text
routes, form requests, API resources
        │  Scramble
        ▼
apps/api/openapi.json
        ├──▶ apps/web/src/lib/api/generated/schema.d.ts
        └──▶ docs/postman/collection.json
```

```bash
make api-docs      regenerate all three
make api-check     fail if the committed contract has drifted (part of `make check`)
```

Do not edit any of those three by hand. Change the Laravel code and regenerate.

### Postman

Import [`docs/postman/collection.json`](docs/postman/collection.json) and
[`environment.json`](docs/postman/environment.json), then set `origin` to the
**web application's** URL - `http://localhost:3000` by default. Not the API's:
nothing reaches the API on its own port.

Authentication is automatic. Send `auth.register` or `auth.login` once and
every later request is authenticated: the session cookie lives in Postman's
jar, and the collection's pre-request script attaches CSRF and the `Origin`
header Sanctum needs. There is nothing to copy between requests.

If unsafe requests come back 419, allow the origin under Postman's
Cookies -> Domains allowlist so the script can read the cookie jar.

---

## Licence

[GNU Affero General Public License v3.0 only](LICENSE).

AGPL rather than a permissive licence because this is network software: the
copyleft only means something here if it reaches people who run a modified
version as a service rather than distributing it.
