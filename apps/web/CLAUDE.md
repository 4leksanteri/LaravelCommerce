@AGENTS.md

# Web Engineering Guidelines

Rules specific to the Next.js application in `apps/web`.

The line above imports `AGENTS.md`, which `next dev` generates and re-adds on
every run. It carries Next's own version-specific guidance. **Do not hand-edit
it**, and commit it with your work rather than reverting it.

The repository-level [CLAUDE.md](../../CLAUDE.md) remains authoritative for
project-wide rules. If this file conflicts with it, stop and name the conflict
rather than silently choosing one.

---

# 1. Purpose

`apps/web` is the marketplace's interface. It renders, and it decides nothing.

Every rule - what a person may see, what they may do, what something costs -
belongs to the API. This application asks, and draws the answer.

---

# 2. Technology

- Next.js 16, App Router, Turbopack
- React 19
- TypeScript 5
- Tailwind CSS 4
- ESLint, Prettier

**TypeScript 5, not 7**, and it is not an oversight: `typescript-eslint` hard-
errors on TS 7 and takes the entire lint step with it. The reasoning and the
retry instructions are in
[ADR 0001](../../docs/architecture/0001-foundations.md).

Do not add a state library, a data-fetching library, a component framework or
an HTTP client without explaining why what exists does not do the job. Server
Components fetch on the server; `apiFetch` handles the browser.

---

# 3. Architecture

```text
Browser
   │
   │ relative /api/v1/*
   ▼
Next.js route handler          app/api/[...path]/route.ts
   │
   │ internal network
   ▼
Laravel
```

and, for anything rendered on the server:

```text
Server Component ──▶ serverFetch ──▶ Laravel, directly
```

The browser must never call the API directly, and never with an absolute URL.
`http://api:8000` does not resolve in a browser and must never reach a bundle.

**`lib/api/config.ts` imports `server-only`.** That is what enforces this:
importing it, or `lib/api/server.ts`, from a Client Component is a build error
rather than a hostname quietly shipped to every visitor. Do not remove that
import, and do not work around it.

Read [ADR 0003](../../docs/architecture/0003-the-proxy-boundary.md) before
editing the proxy. Three of its behaviours break authentication silently.

---

# 4. Structure

```text
apps/web/
├── src/
│   ├── app/
│   │   ├── api/[...path]/route.ts   the reverse proxy. One file, no siblings.
│   │   ├── layout.tsx
│   │   ├── page.tsx
│   │   └── globals.css              the only global stylesheet
│   └── lib/
│       └── api/
│           ├── config.ts            server-only. The API address lives here.
│           ├── server.ts            server-only. For Server Components.
│           ├── client.ts            browser. Handles CSRF.
│           ├── errors.ts            ApiError, shared by both
│           └── types.ts             the API's response shapes
├── eslint.config.mjs
├── next.config.ts
└── tsconfig.json
```

It grows towards `components/`, `hooks/` and one directory per domain under
each. Create each when it has a real responsibility. Do not create empty
architecture in anticipation.

---

# 5. Server and client

Default to Server Components. Reach for `"use client"` when something genuinely
needs interactivity, browser APIs or React state - not by habit.

A Server Component fetching through `serverFetch` is the normal way to get
data. It runs on the server, forwards the caller's session, and ships no
fetching code to the browser.

## Catching around a server fetch needs `unstable_rethrow`

Next signals control flow **by throwing**: `notFound()`, `redirect()`, and the
marker that says a route read `headers()` and cannot be prerendered.

A `try/catch` around a server fetch swallows those. This is not theoretical -
the home page rendered "API unreachable" during `next build` until the rethrow
was added, because the catch ate `DYNAMIC_SERVER_USAGE`:

```ts
try {
  return await serverFetch<Thing>("/things");
} catch (error) {
  unstable_rethrow(error);
  // now it is safe to handle a real failure
}
```

Every catch around a server fetch needs it.

---

# 6. Talking to the API

## From the browser

```ts
import { apiFetch } from "@/lib/api/client";

const order = await apiFetch<Resource<Order>>("/orders", {
  method: "POST",
  body: JSON.stringify(payload),
  headers: { "content-type": "application/json" },
});
```

Relative, same-origin, session cookie attached by the browser. CSRF is handled
inside `apiFetch` and must not be handled anywhere else.

Do not scatter raw `fetch("/api/...")` calls through components.

## From the server

```ts
import { serverFetch } from "@/lib/api/server";

const products = await serverFetch<Resource<Product[]>>("/products");
```

## CSRF

The token is read from the cookie on **every** unsafe request and never cached.
Laravel rotates it, including when the session regenerates on login, so a token
held in a variable is a 419 on the first write after signing in.

---

# 7. Types

`lib/api/types.ts` describes what the API returns. It is hand-written today,
which is a known weakness rather than a design.

**Changing a resource in `apps/api` means changing its type here, in the same
commit.** Nothing checks this yet. ADR 0003 records the intended direction: a
generated contract, once there is enough API surface to justify the generator.

Keep TypeScript strict. Prefer `unknown` over `any` for data that has not been
validated, and a narrow cast of a known shape over widening a type. Model
absence explicitly rather than writing `string | null | undefined` without
knowing why all three exist.

---

# 8. Money

Amounts arrive as integer minor units with a currency, and the browser
**formats** them. It never computes them.

```ts
// Right.
new Intl.NumberFormat(locale, { style: "currency", currency }).format(amount / 100);

// Wrong, all of it.
const total = items.reduce((sum, item) => sum + item.price * item.quantity, 0);
const discounted = price * 0.9;
```

That first line divides, which is the one place division is allowed: converting
minor units for the formatter, on a value that is displayed and never sent
back.

No subtotals, no basket totals, no discounts, no tax. If a figure is needed,
the API computes it and sends it - it has the exact types, the tax rules and
the rounding decisions. A figure computed here will eventually disagree with
what the buyer is charged, and they will believe the one they were shown.

Never sum amounts in different currencies. See
[ADR 0004](../../docs/architecture/0004-money-and-currency.md).

---

# 9. Errors

`ApiError` carries the status, and the status is part of the contract:

```text
401   session gone.      Clear local state, send the person to sign in.
403   not allowed.       Explain it. Do not sign anybody out.
422   validation.        Render each message beside the field it names.
```

Never collapse them into one generic failure.

Do not render a server-side error's detail into the page. What went wrong
reaching an internal service is not a visitor's business; log it on the server
and show something a person can act on.

---

# 10. Rendering

- Nothing behind a session is cacheable by default. `serverFetch` sets
  `cache: "no-store"` and the proxy is `force-dynamic`, because serving one
  person's page to another is the worst bug this application could have.
- Avoid differences between server-rendered and client-rendered output. Do not
  solve a hydration problem by disabling SSR; work out which state belongs
  where.
- `window`, `document`, `localStorage` and `navigator` do not exist on the
  server. Guard with `typeof window !== "undefined"` only in code that has a
  real reason to run in both.

---

# 11. Components

Components are focused on presentation and interaction. Business logic does not
live in them.

Split a component when responsibilities differ, when part of it is genuinely
reusable, or when state ownership has become unclear. Not because it passed a
line count.

Prefer explicit props and one-way data flow. Avoid passing a whole page-state
object down several levels for convenience.

---

# 12. Styling

Tailwind, with `globals.css` as the only global stylesheet. Design tokens live
in its `@theme` block.

Do not add a second global stylesheet, a CSS-in-JS runtime or a component
library without explaining why Tailwind does not do the job.

The application must work at phone width. A marketplace is browsed on a phone
more often than not.

---

# 13. Formatting and linting

```bash
make lint-web       # eslint
make format         # prettier, repo-wide
make typecheck      # next typegen && tsc --noEmit
```

`next typegen` before `tsc` is required, not optional: Next generates the route
types that `LayoutProps` and `PageProps` come from, and without it a bare `tsc`
fails on types it cannot see.

`eslint-config-prettier` is applied last in `eslint.config.mjs`. ESLint owns
code quality, Prettier owns layout, and they do not argue.

Next 16 no longer runs ESLint during `next build`, which is why there is no
`eslint` key in `next.config.ts` and why linting is its own step.

---

# 14. Before starting a task here

1. Read this file and the root `CLAUDE.md`.
2. Read ADR 0003 before touching anything under `app/api/` or `lib/api/`.
3. Ask whether the thing you are about to compute is the API's job. It usually
   is.
4. If you changed a response shape, change the Laravel resource and
   `lib/api/types.ts` together.
