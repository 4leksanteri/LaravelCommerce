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

## The endpoints

```text
GET    /api/v1/auth/csrf-cookie             sets XSRF-TOKEN and the session cookie
POST   /api/v1/auth/register                creates an account and signs in
POST   /api/v1/auth/login                   signs in
POST   /api/v1/auth/logout                  ends the session
GET    /api/v1/auth/me                      who the session belongs to
POST   /api/v1/auth/password/forgot         emails a reset link
POST   /api/v1/auth/password/reset          sets a new password from the token
GET    /api/v1/auth/email/verify/{id}/{hash} marks an address verified
POST   /api/v1/auth/email/resend            sends the verification mail again
```

---

## Anti-enumeration, and where it stops

An endpoint that answers differently for a known and an unknown address is a
way to find out who has an account. For a marketplace that discloses who sells
and who buys here, so two endpoints answer identically in every case:

- **Login.** `Auth::attempt` fails the same way for an address with no account
  and for a wrong password, and one message covers both. Never add "no account
  with that address".
- **Forgot password.** The broker's status - sent, unknown user, throttled - is
  discarded, and every caller gets the same body at the same status. Whether
  mail was actually sent is visible in the logs and in Mailpit.

Both have regression tests that assert the two responses are _identical_,
rather than that each of them fails.

**Registration is the deliberate exception.** It answers 422 when an address is
already taken, which does disclose that the account exists. The alternative is
accepting the registration silently and emailing the existing account, which
strands somebody who genuinely forgot they had an account: they get no error,
no account, and no explanation. The disclosure is rate limited instead, at ten
attempts an hour per IP.

Two residual leaks, recorded rather than fixed:

- **Timing.** An unknown address returns before any hash is computed; a known
  one pays for bcrypt first. Rate limiting is the control here, not constant
  time.
- **Registration**, as above.

---

## Rate limiting

Named limiters in `AppServiceProvider`, keyed by **address and IP together**.
By IP alone a shared office network locks colleagues out of their own accounts;
by address alone anybody can lock a person out from anywhere. The pair costs an
attacker a fresh address for every IP they hold.

```text
login             5 per minute per address+IP, 20 per minute per IP
register          10 per hour per IP
password/forgot   3 per 10 minutes per address, 10 per 10 minutes per IP
email/resend      3 per 10 minutes per user
email/verify      6 per minute
```

The password broker also throttles the same address for 60 seconds by itself,
which is separate from and additional to the above.

---

## Links in mail point at the frontend

The API is not reachable from a browser, so a link pointing at it is a dead
link in somebody's inbox. Both notifications are re-pointed at
`config('app.frontend_url')` in `AppServiceProvider`.

```text
verify email    {FRONTEND_URL}/verify-email?id=&hash=&expires=&signature=
reset password  {FRONTEND_URL}/reset-password?token=&email=
```

The frontend reads those values and calls the API with them. For verification
that means rebuilding the path exactly:

```text
/api/v1/auth/email/verify/{id}/{hash}?expires=&signature=
```

**The query order is load-bearing.** Laravel signs the raw query string, so
`expires` must come before `signature`, as it did when the URL was generated.

### The signature is relative

`URL::temporarySignedRoute(..., absolute: false)`, and that matters here.

An absolute signature covers the host. This application sees whatever host the
proxy forwarded, and a link generated in a queued job - where there is no
request at all - would be signed against `APP_URL` instead. The two would not
match, and verification would fail in production while passing in every test.
A relative signature covers path and query only, which is the same from
everywhere.

### Verification does not require a session

`signed:relative`, and deliberately not `auth:sanctum`. The link _is_ the
credential: this application signed it and it expires. Requiring a session as
well would mean the link only worked in the browser somebody registered in, and
mail is very often opened somewhere else.

It is also idempotent. Mail clients prefetch links and people click twice, so a
second visit answers `already_verified: true` rather than failing.

---

## Sessions and sign-out

- **Registration and login both regenerate the session id.** Without it, an id
  an attacker planted before sign-in is still valid after it, which is session
  fixation.
- **Logout invalidates the session and reissues the CSRF token.** The old token
  belonged to the session that just ended, and the next write would be refused.
- **Logout also calls `Auth::forgetGuards()`.** `auth:sanctum` makes `sanctum`
  the default guard when it passes, and that guard is a `RequestGuard` which
  caches the user it resolved. Clearing the `web` guard does not clear that
  cache, so without this anything running later in the same request still sees
  somebody signed in - including `AuthenticateSession`'s terminating callback,
  which would write a password hash into the session that was invalidated two
  lines earlier.
- **The frontend signs out server-side first, then clears local state.** The
  other order leaves a live session behind an interface that looks signed out.

---

## A request that cannot hold a session is refused clearly

Sanctum only starts a session when Origin or Referer matches. Signing in
without one is not meaningful, and `$request->session()` then throws a
`RuntimeException` that surfaces as a 500 saying "Session store not set on
request" - which describes the symptom and not the cause.

The `stateful` middleware turns that into a 400 that says what happened. A
browser always sends Origin on an unsafe request, so it never fires in normal
use; it fires for a hand-written client, and for a proxy that has stopped
forwarding the header. The second is a configuration mistake that otherwise
looks like an application bug.

---

## Passwords

One definition, in `AppServiceProvider`, applied wherever a password is
accepted so that registration and reset cannot drift apart.

- Minimum twelve characters.
- Checked against Have I Been Pwned in production only. It is a network call,
  it fails open, and it has no business in a test run.
- Maximum 72 bytes, because bcrypt hashes the first 72 and PHP throws beyond
  that. Refusing in validation turns a 500 into a field error.

**Login deliberately does not apply these rules to the submitted password.**
Validating the shape of an existing password would refuse an old one that no
longer meets current policy, telling somebody their own password is invalid.
There is a test for that.

Reset regenerates `remember_token`, which invalidates every "remember me"
cookie for the account. Somebody resetting a password may be doing it because a
device was lost, and that device holds a cookie that outlives the session.
