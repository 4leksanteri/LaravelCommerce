# 0001 - Foundation decisions

Status: accepted - 2026-09-10

Decisions taken while standing up the repository, recorded because they are
expensive to reverse once data and clients exist.

---

## Runtime versions

- **PHP 8.5.** Laravel 13 requires 8.3 or newer; 8.5 is what the container
  runs and what `composer.json` declares, so a feature available in 8.5 can be
  used without wondering whether some environment is older.
- **Laravel 13.** Current major. `laravel/framework` 13.31 at the time of
  writing.
- **PostgreSQL 18.** Exact numeric for money, CHECK constraints, partial
  indexes. All three are load-bearing for a marketplace and all three are
  reasons SQLite is not used, including in tests.
- **Node 24 LTS.** The active LTS line.
- **Next.js 16, React 19, TypeScript 5.**

**TypeScript 5, not 7, and the reason is specific.** TypeScript 7.0.2 is the
current stable release - the native port of the compiler - and it was tried
here. `tsc --noEmit` passes under it. ESLint does not:

```text
Error: typescript-eslint does not support TS 7.0.
```

That is a hard runtime error, not a warning. `typescript-eslint` 8.70, which
`eslint-config-next@16.3.4` depends on, declares a peer range of
`>=4.8.4 <6.1.0` and refuses to load, taking the whole lint step with it.

So the choice is TypeScript 7 with no linting, or TypeScript 5 with both. Until
`typescript-eslint` ships TS 7 support, it is 5. Retry by bumping the
`typescript` devDependency in `apps/web/package.json` and running
`pnpm --filter web lint`; the day that command succeeds is the day this changes.

---

## Two applications, one repository

```text
apps/api    Laravel. The domain, the database, the rules.
apps/web    Next.js. Rendering.
```

They are in one repository because an API response shape and the TypeScript
type that reads it change together, and two repositories make that two pull
requests that can be merged in either order.

**There is no `packages/` directory.** Nothing is shared: one side is PHP and
the other is TypeScript, and the only thing they have in common is the shape of
the JSON between them. That shape wants a generated contract, not a shared
package - see 0003.

`pnpm-workspace.yaml` lists `apps/web` explicitly rather than globbing
`apps/*`. `apps/api` is a Composer project with no `package.json`, and a glob
would be a promise the repository does not keep.

---

## Three services, and no more

```text
postgres   PostgreSQL 18
api        Laravel on FrankenPHP
web        Next.js
```

There is no Redis, no queue worker and no mail catcher. Sessions, cache and
queued jobs all use the database driver, which PostgreSQL serves perfectly well
at this scale.

Redis is the obvious thing to add early and the obvious thing to regret. It is
a second piece of stateful infrastructure to run, back up and reason about,
introduced before anything measures a need for it. Add it in the change that
gives it a job - a queue that is genuinely too hot for Postgres, or a cache
that genuinely needs to be shared across hosts.

The same applies to a worker container. `QUEUE_CONNECTION=database` runs jobs
synchronously enough for development, and the first genuinely asynchronous
workload is what justifies a process that stays alive without answering HTTP.

---

## FrankenPHP rather than php-fpm behind nginx

The API serves HTTP on the internal network and nothing else. FrankenPHP is one
process that does that: one container, one log stream, and no second
configuration file describing how the first talks to PHP.

**Classic mode, not Octane worker mode.** A worker keeps the application in
memory between requests, which is faster and which makes state leaking from one
person's request into another's possible. In an application that will hold
orders, addresses and payment details, that is not a class of bug worth
accepting for throughput nobody has measured a need for.

Consequences that took a build to discover, both now handled in
`docker/api/Dockerfile`:

- `SERVER_NAME` is `:8000`, a bare port. A hostname would make Caddy try to
  obtain a TLS certificate for a host the internet cannot resolve. TLS
  terminates in front of the Next.js server, not here.
- Caddy provisions a local certificate authority during startup **even when no
  site uses TLS**, and writing its root certificate is among the first things
  it does. Running as an unprivileged user therefore requires `/data/caddy` and
  `/config/caddy` to exist and be owned by that user, or the server exits
  before it listens.

---

## PostgreSQL 18 changed where data lives

The mount is `/var/lib/postgresql`, not `/var/lib/postgresql/data`.

From 18, these images keep data in a major-version subdirectory so that
`pg_upgrade --link` does not have to cross a mount boundary. An image given the
old path finds data where it does not expect it and **refuses to start**, with
a long and easily-skimmed message. Both compose files use the new path.

---

## One environment file

```text
.env                 the values
docker-compose.yml   the allowlist: which service sees which value
container env        what Laravel and Next actually read
```

There is deliberately no `apps/api/.env`. Laravel reads configuration from the
container environment rather than parsing a file of its own, which is how a
single file can configure a stack of three services without any of them holding
a second copy.

Two consequences:

- Configuration is read through `config()`, never `env()`, outside
  `config/*.php`. Larastan's `noEnvCallsOutsideOfConfig` enforces it, because a
  cached config file makes every `env()` call elsewhere return null in
  production.
- `php artisan test` warns on every test, because Collision reads the `.env`
  file to decide which variables to clear first and there is not one. The suite
  runs through `phpunit` directly instead. The warning is noise about a file
  whose absence is the design.

---

## Development and production are separate compose files

`docker-compose.prod.yml` does not extend `docker-compose.yml`.

An overlay inherits what it does not override, and what it would inherit here
is a set of bind mounts pointing at somebody's working tree. A production
container serving a developer's uncommitted files is the exact accident that
arrangement invites, and it fails silently - the container starts, and serves
the wrong code.

The duplication between the two files is the price of not being able to make
that mistake. They are read side by side; the differences are the interesting
part and are commented as such.

---

## Tests run against PostgreSQL

Laravel's default is SQLite in memory, and it is faster.

It is also a different database. Money is `numeric`, invariants are CHECK
constraints and uniqueness is partial indexes; SQLite has different semantics
for all three and accepts values PostgreSQL rejects. A suite that passes on an
engine nobody deploys reports on an application nobody runs. Setting this up
now costs one init script; retrofitting it after a hundred tests costs a week.

Two details make it safe:

- `docker/postgres/init/01-create-test-database.sh` creates
  `${POSTGRES_DB}_test` when the volume is first initialised.
- `apps/api/tests/bootstrap.php` derives the test database name by appending
  `_test` to `DB_DATABASE`, rather than naming it a second time in
  `phpunit.xml`. `RefreshDatabase` drops every table it finds, so a suite
  pointed at the development database would take somebody's data with it, and
  PHPUnit does **not** override an environment variable that already exists
  unless told to.
