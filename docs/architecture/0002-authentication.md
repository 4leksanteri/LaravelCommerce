# 0002 - Authentication

Status: accepted - 2026-09-10

How the browser authenticates. Builds on
[0003](0003-the-proxy-boundary.md), which establishes the same-origin proxy
this depends on.

---

## Mechanism

Laravel sessions, through Sanctum's stateful guard, with Laravel's CSRF
protection. No JWT, no bearer token in the browser, no refresh token, no
third-party identity service.

```text
GET /api/v1/auth/csrf-cookie    Laravel sets XSRF-TOKEN and the session cookie
        ↓
browser reads XSRF-TOKEN from document.cookie
        ↓
POST /api/v1/auth/login  with X-XSRF-TOKEN
        ↓
Laravel validates CSRF, authenticates, regenerates the session
        ↓
session cookie returned, HttpOnly
```

Two cookies, with different jobs:

```text
laravel-commerce-session   HttpOnly. The secret. JavaScript cannot read it,
                           and that is the point.
XSRF-TOKEN                 Readable on purpose. The frontend echoes it back
                           as X-XSRF-TOKEN. It is not a secret; it proves the
                           request came from a page on this origin.
```

The session cookie is first-party because the proxy makes the API same-origin
with the site (0003). No token is ever exposed to JavaScript the way
`localStorage` would expose one, and there is no CORS to configure because
there is no cross-origin request.

---

## Why not tokens in the browser

The alternative considered was the pattern used in an earlier project: the
Next.js server holds a bearer token in Redis, keyed by an opaque session
cookie, and calls the API server-side.

It works, and it costs a Redis instance whose only job is authentication, plus
a session store the Next.js server now owns and has to expire, revoke and
reason about. The proxy already makes the API same-origin, so Laravel's own
session - which is battle-tested, already implemented, and already backed by
the database - reaches the browser safely without any of that.

Revisit when a native mobile client exists. That client will authenticate with
Sanctum tokens directly against the API, which is a **different transport to
the same user and the same permissions**, and it changes nothing here.

---

## Stateful is decided by Origin or Referer

This is the part that surprises people, and the reason several things in this
repository look the way they do.

`EnsureFrontendRequestsAreStateful` inspects the request's `Referer`, falling
back to `Origin`, and matches it against `SANCTUM_STATEFUL_DOMAINS`. On a
match, it runs the session, cookie and CSRF middleware for that request. On no
match, it runs none of them and the request is anonymous - **even with a
perfectly valid session cookie attached.**

Two consequences, both handled in code, both silent when broken:

- **The proxy forwards `Origin` and `Referer` untouched.** Strip them, and
  every request arrives anonymous while the browser keeps sending a session
  cookie it believes in.
- **Server-side fetches must set them by hand.** A `fetch` from a Server
  Component sends neither header, so `lib/api/server.ts` sets both from the
  incoming request's host. Without it, a server-rendered page comes back
  anonymous while the identical call from the browser succeeds - which is a
  confusing afternoon.

`SANCTUM_STATEFUL_DOMAINS` therefore names the **public web origin**, not the
API's own address, and never a wildcard. It has to be kept in step with
`FRONTEND_URL` and `WEB_PORT`, which describe the same origin from two other
angles.

---

## The CSRF cookie route lives under /api/v1

Sanctum publishes `GET {prefix}/csrf-cookie`, defaulting to `/sanctum`.

`config/sanctum.php` sets the prefix to `api/v1/auth` instead, so the route is
`GET /api/v1/auth/csrf-cookie`. This keeps the entire public surface of the API
under one prefix: one path for the Next.js server to proxy, and one rule to
state - the browser calls `/api`, and nothing else exists to call.

`ApiSurfaceTest` asserts that no route escapes `api/v1` except the `/up` probe,
so this cannot quietly regress.

---

## The CSRF token is read per request, never cached

Laravel rotates the CSRF token, including when the session is regenerated on
login. A token captured once and reused is a 419 on the first write after
somebody signs in.

Both clients read the cookie on every unsafe request. Do not cache it, and do
not hold it in React state.

---

## Guests are not redirected

Laravel's `Authenticate` middleware defaults to redirecting an unauthenticated
caller to `route('login')`. There is no such route here, and there will not be:
the sign-in page belongs to the Next.js application.

Left at its default, the middleware resolved that route **before the exception
handler was consulted**, so an unauthenticated request that did not send
`Accept: application/json` died with a 500 "Route [login] not defined" instead
of a 401. Configuring `shouldRenderJsonWhen` did not help, because the
middleware branches on `$request->expectsJson()` directly.

`bootstrap/app.php` sets `redirectGuestsTo(fn () => null)`. The middleware then
throws `AuthenticationException`, which the handler renders as a 401.

The bug survived the first test suite because every test used `getJson()`,
which sets the `Accept` header. There is now a regression that deliberately
uses `get()`.

---

## 401 and 403 mean different things

```text
401   no session, or it expired.        Frontend clears state, sends the
                                        person to sign in.
403   session is fine, action is not    Frontend explains it, and does not
      allowed.                          sign anybody out.
```

Answering 403 for a missing session, or 401 for a permission failure, makes one
of those behaviours wrong. `ApiError` in the frontend exposes both separately
for exactly this reason.

---

## Route protection is not authorization

When the frontend grows guarded routes, they decide **what to draw** and
nothing else. They ask whether there is a session; they never ask what role
somebody holds or whether a shop is theirs.

Laravel remains the only authorization boundary. A visitor who edits the URL
past a route guard reaches a page whose data then comes back 401 or 403. That
is the design working, not a hole. Never weaken an API permission test because
a route is now guarded in the browser.

---

## Cookies stay host-only

`SESSION_DOMAIN` is null. A cookie scoped to a shared parent domain is a cookie
every sibling subdomain can read.

`SESSION_SECURE_COOKIE` is false locally and true in production, where it is
set in `docker-compose.prod.yml` rather than left to an environment variable
somebody might forget. Over plain HTTP a secure cookie is silently discarded by
the browser, and nobody can sign in.

---

## Not implemented yet

There are no registration, login or logout endpoints. `GET /api/v1/auth/me` and
the CSRF cookie route exist, which is enough to prove the mechanism end to end.

When those endpoints are written:

- **Answer identically for a known and an unknown email address.** Login and
  password reset both, including when the account is disabled and including
  when sending the mail fails. An endpoint that answers differently is a way to
  discover who has an account.
- Regenerate the session on login, and invalidate it on logout.
- Sign out server-side **first**, then clear local state. The other order
  leaves a live session behind an interface that looks signed out.
