# 0006 - The generated API contract

Status: accepted - 2026-09-10

Closes the weakness recorded in [0003](0003-the-proxy-boundary.md): the
frontend's types described the API by hand, and nothing noticed when they
stopped matching it.

---

## One source, three outputs

```text
Laravel routes, form requests, API resources
        │  Scramble, by static analysis. No annotations.
        ▼
apps/api/openapi.json
        │
        ├──▶ apps/web/src/lib/api/generated/schema.d.ts   openapi-typescript
        └──▶ docs/postman/collection.json                 openapi-to-postmanv2
```

All three are committed, so a change to the contract appears in review as a
diff. `make api-docs` regenerates them; `make api-check` fails when what is
recorded is not what the code produces, and runs as part of `make check`.

The frontend's `lib/api/types.ts` is now **named aliases only** over the
generated schema. It describes nothing itself.

---

## Why not a hand-maintained Postman collection

That was the original suggestion, and it is the thing this decision rejects.

A collection maintained by hand is a second hand-written copy of the contract,
alongside the one in `types.ts` that had already gone stale once. Two copies
kept in step by discipline is worse than one, because the discipline is what
fails. Generating both from the same document means an endpoint cannot be
missing from Postman, and a response shape cannot be wrong in TypeScript,
without `make check` saying so.

What stays hand-written is the part a generator cannot know: **how to
authenticate**. That lives in `scripts/build-postman-collection.mjs` as a
collection-level pre-request script, and it does not change when endpoints do.

---

## Authentication in the collection

Two things, and the first is the one that costs an afternoon.

**Postman does not send `Origin`.** Sanctum decides whether a request may hold
a session by matching Origin or Referer against `SANCTUM_STATEFUL_DOMAINS`
(ADR 0002). A browser sends them; Postman does not. Without them every request
arrives anonymous while carrying a perfectly valid session cookie, which reads
as the session being broken rather than as a missing header. The script sets
both from the `origin` variable.

**CSRF is fetched and attached per unsafe request.** The script reads the
`XSRF-TOKEN` cookie from Postman's jar, fetching `/auth/csrf-cookie` first if
there is none, and sends it decoded as `X-XSRF-TOKEN`. Never cached: Laravel
rotates the token when the session regenerates on sign-in.

The session itself needs no handling. Postman's cookie jar carries it, so
`auth.login` once and everything afterwards is authenticated.

`origin` is the **web application's** URL, not the API's. Nothing reaches the
API on its own port; the browser calls `/api/v1` on the web origin and the
proxy forwards it.

---

## The document had to be made reproducible

Three things made the generated output differ between runs or between
machines. A drift check that cannot pass is not a check, so each was fixed
rather than tolerated.

- **The server URL came from `APP_URL`**, which produced one developer's
  dev-only API port, committed. `AppServiceProvider` pins it to the relative
  `/api/v1` instead - valid in OpenAPI 3.1, the same everywhere, and a truer
  description: the API is same-origin with the web application.
- **The Postman converter mints a fresh UUID per item on every run** - 72
  lines of pure noise. They are stripped; Postman assigns its own on import.
- **The converter fills example responses with random values**, a random
  integer for each `id` and a random number of entries for a map like
  Laravel's `errors`. Saved examples are dropped entirely; the OpenAPI
  document describes every response properly, which is where that belongs.

---

## Scramble's own routes are turned off

Scramble publishes `/docs/api`, `/docs/api.json` and a dev-tools asset. All
three sit outside the versioned prefix, which makes them unreachable - the
Next.js server proxies `/api/**` and nothing else - and makes `ApiSurfaceTest`
fail, correctly.

`Scramble::ignoreDefaultRoutes()` in `AppServiceProvider::register()`, plus
`dev_tools.enabled => false`. In `register()` because Scramble reads the flag
while booting, and every provider's `register()` runs before any provider's
`boot()`.

The document is a committed artifact instead. Any OpenAPI viewer renders it,
and unlike a live route it is reviewable.

Scramble is a **dev dependency**: nothing generates documentation in
production. Both calls are guarded by `class_exists`, because in the
production image the class genuinely is not there.

---

## What this does not do

- **It does not validate responses at runtime.** The document says what an
  endpoint returns because Scramble read the resource; nothing asserts that
  the running application agrees. Feature tests are what cover that.
- **Postman does not stay live-synced.** Importing an OpenAPI document into
  Postman makes a copy. The committed collection is regenerated by
  `make api-docs`, and picking up a change means re-importing it.
- **A version is still a decision, not a generated fact.** What counts as a
  breaking change, and when v2 exists, is [ADR 0005](0005-api-versioning.md).
