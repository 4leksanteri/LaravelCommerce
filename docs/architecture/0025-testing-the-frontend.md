# 0025 - Testing the frontend

Status: accepted - 2026-09-11

The API has had tests from its first commit. The frontend had none, and was
being verified with curl - which calls the API the way a form would, and cannot
run the form. **No page's JavaScript had ever been executed by a check.** The
CSRF token being read from `document.cookie`, the session cookie arriving
through the proxy, `router.refresh()` redrawing the header: all of it happens in
the page, and none of it had been seen.

The first run found two real defects. That is the case for this ADR.

---

## Two runners, split by what each can render

```text
Vitest       src/**/*.test.ts(x), beside the file    make test-web, inside check
Playwright   e2e/, against the running stack          make e2e, outside check
```

The split is not taste. The bundled Next 16 docs state that **Vitest cannot
render `async` Server Components**, and nearly every page here is one. So Vitest
covers logic and client components, and anything a page does belongs to
Playwright.

Playwright stays out of `make check` because it needs the whole stack running
and a real inbox. A gate that fails because the containers happen to be stopped
teaches people to stop running the gate.

## What is worth a test

What the frontend owns. The proxy, whose failures are silent (ADR 0003). CSRF,
whose one mistake - caching the token - fails exactly after signing in.
`safeRedirect`, which stands between `?next=` and a phishing page.
`formatMoney`, which must ask a currency for its digits. How a refusal is
classified. And the promises components make: a 422 beside its field, "from"
with a real space, the email address kept after a wrong password.

Not the business rules, which the API owns and tests. Not snapshots, which fail
on every harmless change and train people to update them unread. Not class
names. Not a page rendered against a mocked API, which proves the mock.

The status-message test asserts that 401, 403, 419 and 429 come out
**different from one another** rather than pinning each sentence. The wording is
the page's to improve; the distinction is the rule.

---

## What the first run found

**An open redirect.** `safeRedirect` refused `//host` and `/\host` by looking at
the leading characters. The test table included `/\t/elsewhere.test` on the
suspicion that it would not: browsers delete tabs and newlines from a URL before
parsing it, so that string passes a check for `//` and then navigates to another
origin as `//elsewhere.test`. It failed, as suspected, and so did the newline
variant.

The fix stops inspecting the string. It parses the target with the same WHATWG
parser the browser uses, against a placeholder origin, and honours it only if it
comes back on that origin - so every trick that works by making the string and
the parser disagree is answered by the parser. The test was written before the
bug was known, failed without the fix and passes with it, which is what root
`CLAUDE.md` section 10 asks for.

**A sign-out that never redrew the page.** From the home page, the header went
on showing "Orders" and a disabled "Signing out..." to somebody who was no longer
signed in. The first attempt at a diagnosis could not tell a failed request from
a page that did not notice, so the test was changed to assert the logout
response itself: it was a 204. The API had ended the session. Calling
`router.refresh()` and then pushing to the URL the page was already on left it as
it was.

Sign-out now ends in a full page load. That is right beyond the bug: the client
router holds on to what it rendered for the person who just left, and signing
out is the moment all of it should go. ESLint's rule against `location.assign`
recommends `router.push()` - the approach that failed - so it is disabled on that
one line with the reason beside it.

Neither defect could have been found by the 331 API tests, and neither by curl.

---

## Every page, at phone width and through axe

`e2e/pages.spec.ts` loads every page at 375px and fails if anything scrolls
sideways, and runs axe over each. ADR 0024 admitted phone width had been
reasoned about rather than looked at; all five pages passed, so the reasoning
held.

axe finds what a machine can - contrast, missing names, broken ARIA references -
and found nothing. A clean report is a floor rather than a verdict: it cannot
tell whether a page makes sense read aloud.

A new page goes into `PAGES`. That is the whole cost of both checks.

## Setup decisions worth knowing

- **`server-only` resolves to its own empty module under Vitest.** The real one
  throws outside a server build, so the proxy and `serverFetch` could not be
  imported at all. The alternative - the `react-server` export condition -
  would also swap React for its server build and break every component test.
- **Ports come from the root `.env`**, loaded with `process.loadEnvFile`, so
  nothing assumes the 3010 one machine happens to use.
- **One worker, in order.** Every test shares a database and an inbox, and two
  racing would fail each other in ways that look like application bugs.
- **Mail is found by recipient**, through Mailpit's search API, rather than by
  clearing the inbox somebody may have open.
- **End-to-end runs leave `e2e-*@example.test` accounts behind.** The browser
  cannot delete a user and must not be able to, so there is no teardown that
  respects the boundary in root `CLAUDE.md` section 4.
- **Chromium only.** The point is the arrangement, not engine quirks, and each
  extra browser is a download on a disk that has already filled up once.

Two traps were hit on the test side and are written into `apps/web/CLAUDE.md`:
rendering several hooks inside overlapping `act()` calls leaves `result.current`
null, and a mocked `Response` can be read once, so a shared one fails the second
request in a test.

---

## Not yet decided

- **A production build.** The Next docs recommend running Playwright against
  `next build && next start`. It runs against `next dev`, which is what is up;
  a production image under test is its own piece of work.
- **Cleaning up after end-to-end runs.** The accounts accumulate. Any fix has to
  go through the API, and an endpoint that deletes users for a test runner is a
  backdoor with a test's name on it.
- **Coverage thresholds.** Deliberately none. A percentage rewards testing what
  is easy to reach, and the two defects above lived in thirty lines.
- **Visual regression, Firefox and WebKit.** Each would catch things this does
  not, and none has earned its cost yet.
