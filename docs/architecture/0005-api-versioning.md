# 0005 - API versioning

Status: accepted - 2026-09-10

How the API is versioned, and why the obvious shortcut was rejected.

---

## One route file per version, mounted explicitly

```text
routes/api/v1.php     mounted at /api/v1
routes/api/v2.php     mounted at /api/v2, when there is one
```

`bootstrap/app.php` mounts each with its own prefix:

```php
then: function (): void {
    Route::middleware('api')
        ->prefix('api/v1')
        ->group(base_path('routes/api/v1.php'));
},
```

Adding v2 is one line there and one new file. **Nothing about v1 moves.** That
is the property being bought: a version that has to be edited in order to add
its successor is not really a version.

---

## Why not `apiPrefix`

Laravel offers a shorter way, and it was what this repository did first:

```php
->withRouting(
    api: __DIR__.'/../routes/api.php',
    apiPrefix: 'api/v1',
)
```

It works, and it pins the entire application to one version permanently. There
is one API route file and one prefix for it, so there is no second prefix to
give a second file. Introducing v2 would mean restructuring the routing of a
live application - exactly when the cost of doing so is highest, because v1 is
in use by then.

The whole reason to version an API is that v1 can keep answering while v2
exists. A scheme that cannot express two versions at once does not do that; it
just puts a number in a URL.

---

## What a version means here

A version is a **contract with a client**, not a release number.

- Adding a field, a new endpoint, or an optional parameter is not a new
  version. Existing callers keep working.
- Removing or renaming a field, changing a type, changing a status code, or
  tightening validation **is** a new version.
- Fixing a response that was wrong is a judgement call. If a client could
  reasonably have depended on the wrong behaviour, it is breaking.

When v2 arrives, v1 does not get deleted with it. It is deprecated with a date,
and removed when nothing calls it.

---

## Probes stay unversioned

```text
/up            Laravel's health route. Reached from inside the container by
               the Docker health check, never through the proxy.
```

A probe should not have to track API versions, and the thing it answers - is
this process alive - has no contract to break.

`/api/v1/health` is a different endpoint with a different purpose: it is what
the Next.js server calls, over the versioned API, and it is versioned like
everything else there.

---

## The prefix is enforced, not assumed

`tests/Feature/ApiSurfaceTest.php` fails when any route appears outside
`api/v1`, with `/up` as the only exception.

That is not tidiness. The Next.js server proxies `/api/**` and nothing else, so
a route outside the prefix is reachable by nothing - it is dead surface,
usually published by a package nobody asked. The test is what caught
`filesystems.local.serve` publishing `GET|PUT /storage/{path}`.

When v2 exists, that test grows a second allowed prefix rather than losing the
rule.

---

## Consequence for the frontend

`apps/web/src/lib/api/client.ts` and `server.ts` both hard-code `/api/v1` as
their base path. A second version means a second base path, chosen per call
rather than globally - the frontend will speak v1 for some endpoints and v2 for
others during any real migration, and a single global constant cannot express
that.

Do not turn the version into an environment variable. Which version a call
speaks is a property of the code making the call, not of the deployment.
