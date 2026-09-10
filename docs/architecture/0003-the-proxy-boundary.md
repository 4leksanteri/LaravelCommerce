# 0003 - The proxy boundary

Status: accepted - 2026-09-10

How the browser reaches the API, why it is arranged this way, and the specific
behaviours that must not be changed casually.

---

## The shape

```text
browser ──▶ https://example.com/                (Next.js page)
        ──▶ https://example.com/api/v1/...      (Next route handler
                                                 ──▶ http://api:8000/api/v1/...)

Server Component ──▶ http://api:8000/api/v1/... (direct, internal network)
```

The Next.js server is the only thing published. In `docker-compose.prod.yml`
the API service has no `ports` key at all, which is the single most important
line in that file: the API is reachable from the web container and from
nothing else.

Consequences:

- Application code in the browser uses **relative** `/api/v1/...` URLs.
- The browser sees one origin, so **there is no CORS**. `HandleCors` is left at
  its defaults and `config/cors.php` is not published, because there is no
  cross-origin request to permit.
- Session cookies are first-party and `HttpOnly` (0002).
- The API's address exists in one frontend module, `lib/api/config.ts`, which
  imports `server-only`. Importing it from a Client Component is a build error
  rather than an internal hostname shipped in a bundle.

---

## Why not expose the API directly

It would be simpler, and it would mean CORS, a cross-site cookie, and every
hardening decision applied twice - once for the browser's origin and once for
the API's. The proxy removes an entire category of configuration by making the
question not arise.

It also means the API's attack surface is a single internal network hop rather
than the internet. When a native mobile client eventually needs direct access,
that is a deliberate exposure with its own decision and its own hardening, not
a default that was never chosen.

---

## Two paths, deliberately

**The browser goes through the proxy.** `app/api/[...path]/route.ts`, which
forwards `/api/**` to Laravel unchanged. Laravel mounts its routes under `/api`
too, so the path maps across without rewriting.

**The server does not.** `lib/api/server.ts` calls Laravel directly. Proxying
would mean the Next.js server making an HTTP request to itself to reach a
service it can already see: an extra hop, and a way to exhaust the request pool
under load.

The cost of the second path is that two things a browser provides for free have
to be reconstructed:

```text
Cookie   the session belongs to the person whose page is being rendered, not
         to the server process. Forwarded from the incoming request.
Origin   Sanctum ignores a session cookie unless Origin or Referer matches
         SANCTUM_STATEFUL_DOMAINS (0002). A server-side fetch sends neither.
```

Both are set in `serverFetch`, derived from the incoming request rather than
configured, so a deployment behind a load balancer and one on a laptop both get
it right without another variable to keep in step.

---

## Three behaviours in the proxy are load-bearing

Each of these breaks authentication **silently** rather than loudly. Read this
section before editing `app/api/[...path]/route.ts`.

### 1. Origin and Referer are forwarded untouched

Sanctum decides whether a request may be authenticated by a session cookie by
matching one of those two headers. Drop them and every request arrives
anonymous, with a valid session cookie attached and no error anywhere.

### 2. Every Set-Cookie is forwarded, separately

Iterating a `Headers` object collapses repeated headers into one comma-joined
value. A session cookie and a CSRF cookie merged into a single string is two
cookies the browser stores as neither.

The proxy skips `set-cookie` in its header loop and re-adds each value from
`getSetCookie()`. Laravel rotates the session on login and rotates the CSRF
token with it, so losing one response's cookies signs somebody out on their
next write.

### 3. Content-Encoding and Content-Length are dropped from the response

`fetch` has already decoded the body by the time the proxy sees it, so passing
the upstream's claims about it on to the browser describes a body that no
longer exists. `accept-encoding` is stripped from the request for the same
reason: there is no point asking the API to compress a body that will be
decompressed one hop later.

### And two smaller ones

- `x-forwarded-host` and `x-forwarded-proto` are **set**, not merged, so a
  client-supplied value cannot survive and spoof the origin Laravel believes it
  is serving.
- `server` and `x-powered-by` are stripped from the response. Nothing
  downstream reads them, and they only help somebody match a known CVE to this
  deployment.

---

## Laravel trusts all proxies, and that is conditional

`bootstrap/app.php` calls `trustProxies(at: '*')`.

That is safe **because the API is not published**. The only ingress is the web
container on the internal network, so every `X-Forwarded-*` header the
application sees was written by the proxy and there is no untrusted hop to
distrust.

It stops being safe the moment the API is exposed directly. If that ever
happens, this becomes an explicit proxy list in the same change.

---

## The request body is streamed

The proxy passes `request.body` through rather than buffering it, with
`duplex: "half"` because Node requires it to accept a stream as a request body.

Product images are the reason. Buffering would put every upload in the Next.js
process's memory in full before the API saw any of it.

---

## The contract between the two sides is hand-written, for now

`apps/web/src/lib/api/types.ts` describes the API's responses by hand. That is
a known weakness: a type there can drift from the Laravel resource that
produces it, and nothing notices until something renders `undefined`.

Until it is generated, **changing a resource in `apps/api` means changing the
matching type in `apps/web` in the same commit.**

The intended direction is for the API to publish an OpenAPI document and for
those types to be generated from it, at which point the file stops being
editable by hand and a drift becomes a failing check rather than a bug report.
That is worth doing once there is enough API surface for the generator to earn
its configuration - roughly, once sellers, products and orders exist. Doing it
now would be tooling around two endpoints.
