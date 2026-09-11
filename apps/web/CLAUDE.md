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
│   │   ├── (auth)/                  bare: /login, not /auth/login
│   │   ├── (shop)/                  header and footer around everything browsed
│   │   │   └── (dashboard)/         /account and /seller: one layout, one sidebar
│   │   ├── api/[...path]/route.ts   the reverse proxy. One file, no siblings.
│   │   ├── healthz/route.ts         container liveness. Checks nothing else.
│   │   ├── layout.tsx
│   │   ├── not-found.tsx            every unmatched URL. Draws the header.
│   │   └── globals.css              the only global stylesheet
│   ├── components/
│   │   ├── ui/                      primitives. No domain, no lib/api import.
│   │   ├── auth/                    composed. May take API types, fetch nothing.
│   │   ├── account/                 settings forms, the address book
│   │   ├── cart/                    adding to it, and the cart page
│   │   ├── checkout/                addresses, placing orders
│   │   ├── orders/                  status, timeline, what a buyer can do
│   │   ├── sellers/                 applying, a shop's details, its status
│   │   ├── catalogue/               listings: card, grid, gallery
│   │   └── shell/                   header, footer, sign-out
│   ├── hooks/
│   └── lib/
│       ├── api/
│       │   ├── config.ts            server-only. The API address lives here.
│       │   ├── server.ts            server-only. For Server Components.
│       │   ├── client.ts            browser. Handles CSRF.
│       │   ├── errors.ts            ApiError, shared by both
│       │   ├── generated/           from openapi.json. Never edited.
│       │   └── types.ts             named aliases over generated/
│       ├── auth/                    session.ts, redirects.ts
│       ├── catalogue/               searchHref, categoryHref, listingCount
│       ├── dates.ts                 formatDate. In UTC, on purpose.
│       ├── money.ts                 formatMoney. Asks the currency for its digits.
│       ├── navigation.ts            loadFresh: a full page load, on purpose
│       ├── orders/                  statusLabel: an order's status, in words
│       ├── sellers/                 readShop: the signed-in person's shop, once a request
│       └── utils.ts                 cn(), shadcn's contract
├── eslint.config.mjs
├── next.config.ts
└── tsconfig.json
```

It grows by domain under `components/` and `lib/`. Create each when it has a
real responsibility. Do not create empty architecture in anticipation.

## Writing to the API happens in the browser

A form posts through `apiFetch` from a Client Component. **Not a Server
Action**, and not because actions are bad: CSRF and cookie forwarding already
exist exactly once, in `apiFetch` and the proxy, and an action running on this
server would be a second copy of both. The session is the last thing that should
have two implementations. See
[ADR 0023](../../docs/architecture/0023-the-auth-screens.md).

Handle `onSubmit` yourself rather than passing `<form>` an action, so a refusal
never costs somebody what they typed.

**Nothing mirrors the session.** There is no auth context and no signed-in flag:
`currentUser()` asks the API, a 401 means "nobody", and every other status
throws. A copy in the browser is the one that goes stale.

`useApiSubmit` classifies a refusal once. Use it rather than writing a fifth
`catch` that turns 401, 403, 419 and 429 into the same sentence.

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

**`lib/api/generated/schema.d.ts` is generated. Never edit it.** It comes from
`apps/api/openapi.json`, which comes from the Laravel code.

`lib/api/types.ts` is **named aliases only** over that file. It gives generated
shapes readable domain names and describes nothing itself. Never hand-write a
shape the backend already defines: a hand-written copy stops matching the API
silently, which is the failure this pipeline exists to prevent.

To change a shape, change the Laravel resource or form request, then:

```bash
make api-docs      regenerate the spec, these types and the Postman collection
make api-check     fails when the committed output has drifted (part of `make check`)
```

Reasoning is in
[ADR 0006](../../docs/architecture/0006-the-generated-api-contract.md).

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

## Use the tokens, never a palette colour

`text-zinc-600` and `text-emerald-700` are banned. The tokens are role-named -
`text-muted-foreground`, `text-positive`, `border-border` - and a token named
for its job survives a redesign where one named for its colour does not.

The names are shadcn's contract, so components copied in reference them without
being hand-edited. The values come from the design export and the reasoning for
each is in
[ADR 0019](../../docs/architecture/0019-positioning-and-design-direction.md).

Three that are worth knowing before reaching for something else:

```text
primary      blue. This is a marketplace with escrow, and the control that
             commits money should be unmistakable rather than tasteful.
accent       a tint of the primary, for hover states and quiet surfaces. Not a
             colour of its own.
positive
caution      states this domain has and shadcn does not ship.
```

**Light only.** There is no dark mode and no `dark:` variant belongs anywhere:
equipment is photographed on white by everyone who sells it, and every image
would need a treatment nobody is going to give it. A decision, not an omission.

## Build from the export, do not lift from it

`docs/design/exports/LaravelCommerce.html` is the reference for every screen. It
is a bundled React app with its own runtime, so it is read and rebuilt in our
components against our tokens - never copied.

Three things it shows have no backend: messages, reviews, and shipping beyond a
`shipped_at` timestamp. (Search did, until ADR 0020.) Neither do its
favourites, its discounts or the counts in its search box. Build the screens the
API actually feeds, and do not stub the rest into looking real.

## Components are extracted, not designed in advance

A design system is a distillation. Built before there are screens it produces a
button with fourteen variants and no page using eleven of them.

Primitives come from shadcn, are copied into `components/ui/`, and are then
ours to change. They are presentational: they know nothing about the domain and
never import a type from `lib/api`. Composed components live in
`components/<domain>/`, may take API types, and still fetch nothing.

## A component renders the answer, never re-derives the rule

```tsx
{
  order.can_cancel && <CancelButton />;
} // yes
{
  order.status === "pending" && <CancelButton />;
} // no
```

The second is a copy of a rule the API owns, in the one place that goes stale
and the one place an attacker controls. Root `CLAUDE.md` section 4 is why every
resource carries `can_*` fields at all - use them.

---

# 12a. Tests

Two runners, split by what each can render - not by taste.

```text
Vitest       src/**/*.test.ts(x), beside the file    make test-web, in check
Playwright   e2e/, against the running stack          make e2e, outside check
```

**Vitest cannot render `async` Server Components** - the bundled Next docs say
so - and nearly every page is one. So Vitest covers logic and client components,
and anything a page does is Playwright's.

## What is worth a test here

What the frontend owns: the proxy, CSRF, `safeRedirect`, `formatMoney`, how a
refusal is classified, and the promises a component makes (a 422 beside its
field, "from" with a real space, the email kept after a wrong password).

Not the business rules, which the API owns and tests. Not snapshots, which fail
on every harmless change and teach people to update them unread. Not class
names. Not a page rendered against a mocked API, which proves the mock.

Every page goes into `PAGES` in `e2e/pages.spec.ts`, which fails on horizontal
overflow at 375px and on anything axe can find.

## Conventions

- `import { describe, expect, it, vi } from "vitest"` - explicit, no globals.
- jsdom by default. Server code says `// @vitest-environment node` on line one.
- `server-only` resolves to its own empty module under Vitest, because the real
  one throws outside a server build. Never switch Vitest to the `react-server`
  condition to get round it: that swaps React too, and every component test
  breaks.
- Component tests mock `@/lib/api/client` and `next/navigation`, and nothing
  else of ours.
- Playwright reads ports from the root `.env`, uses one worker because every
  test shares one database and one inbox, and finds its mail in Mailpit by
  recipient rather than by clearing the inbox.
- **A signed-in test uses the demo shopper's session**, which the `setup`
  project signs in for once per run, and empties the cart first with
  `emptyCart`. Registration is limited to ten an hour per IP and signing in to
  five a minute per address, the production limits, and an account per test
  would hit them on the second run of the hour. Only the auth spec still
  registers, because registering is what it tests; its accounts are left behind
  as `e2e-*@example.test`, since the browser cannot delete a user.
- **A page that is nothing without a session calls `requireUser(path)`**, which
  redirects to sign in and back. A public page whose one action needs a session
  draws a sign-in link in place of that action instead (ADR 0028, ADR 0029).

## Four traps already hit

- **Currency symbols in an expectation.** A formatted price holds a symbol and
  often a no-break space, and typing either into a test puts a character in
  source that root `CLAUDE.md` section 15 refuses. Build the expectation with
  `formatMoney`, or write the escape (`\u20ac`). This was typed literally five
  times while writing these tests, under comments saying it had not been; the
  charset check caught every one, which is what it is for.

- **Overlapping `act()`.** Rendering several hooks inside a `Promise.all` leaves
  `result.current` null. Render them one at a time.
- **A mocked `Response` is single-use.** Its body can be read once, so a mock
  returning one shared object fails the second request in a test. Build a new
  one per call with `mockImplementation`.
- **Next's route announcer is an alert.** After hydration Next mounts an empty
  `role="alert"` region inside a shadow root, so an unscoped
  `getByRole("alert")` in Playwright finds two - or one, if the assertion beat
  hydration. That is a test which passes once and fails the next time with
  nothing changed. Look for alerts inside `main`.

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
