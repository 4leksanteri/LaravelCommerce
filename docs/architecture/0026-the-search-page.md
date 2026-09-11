# 0026 - The search page

Status: accepted - 2026-09-11

The first page the header linked to that is no longer a dead end. It is also
the page behind "Start browsing" and "See everything", because `GET /search`
with no term is everything on the marketplace, newest first (ADR 0020).

---

## A refusal is the API's, rendered as it said it

Two things make the search endpoint answer 422: a one-letter term, and a
category that does not exist. The page renders the API's messages, and nothing
of its own.

**There is deliberately no `minLength` on the search box.** The rule is `min:2`
in `SearchRequest`, and a second copy of it in the browser is the one that
drifts (root `CLAUDE.md` section 4). The cost is one round trip for somebody
who typed a single letter, which is not a cost worth a duplicated rule.

An unknown category is a **422 rather than a 404**, because `exists` in the form
request runs before the controller ever looks the category up. The page offers
"search all categories instead", keeping the term.

Any other failure is left to throw. Rendering "nothing matches" when the API is
down would be a lie about the catalogue, not a degraded page.

## Paged by `meta`, addressed by `searchHref`

The pager draws `meta.current_page` and `meta.last_page` - the API's answer to
where this page sits (ADR 0022) - not whatever `?page=` said. Laravel's
paginator treats `?page=abc` as page 1, and so does the pager, because it asks.

Every link on the page is the current search with one thing changed: a filter,
a page, the category removed. `searchHref` builds all of them, in one parameter
order, never writing page 1 - so one search has one address, and a filter link
cannot quietly keep a stale page number.

`Pagination` is a `ui/` primitive: two numbers and a function that makes an
address. Past the end, on page 40 of three because something was deleted
mid-browse, it still draws "Previous" and points it at the last real page.

## The header's box shows the term

The header is in a layout, and a layout is not given the query string. So the
box is the one client component in the header: it reads `useSearchParams`,
inside the Suspense boundary the Next docs ask for, and falls back to the same
box empty. The form around it is still a plain `GET` and works without
JavaScript.

`key` is the term itself. `defaultValue` applies only when an input mounts, so
without it a second search made from the results page would leave the first
term in the box.

Only on `/search`. A `?q=` elsewhere means something else, or nothing.

## Filtering by category keeps the term

The top level always, and a category's own subcategories once it or one of them
is chosen. The API already includes everything beneath a category (ADR 0017), so
choosing "Audio" finds the turntables; the second row is for narrowing, not for
finding.

The page does not say how results are ordered. The API ranks by relevance when
there is a term and by date when not, and a label saying so would be a copy of
that rule.

Result pages are `noindex`. There are unboundedly many of them and they change
by the hour.

---

## What testing found

axe failed the page on **heading order**. Every product card titles itself with
an `h3`; on the home page the grid sits under an `h2` and the outline is sound,
but on the search page it sat straight under the `h1`. The results now have a
visually hidden `h2`, which also lets somebody navigating by headings skip the
filters. The rule is written into `ProductGrid`: it belongs under an `h2`.

`ProductGrid` itself was extracted here, at its second caller, rather than when
the home page was its only one.

`make e2e` now seeds the demo catalogue before it runs - idempotently - because
the search specs name demo listings. A search test that only checked that some
cards appeared would pass against a search that ignored the term.

---

## Not yet decided

- **"lens" still does not find "lenses".** The stemmer defect pinned in ADR 0020
  is untouched. The empty state suggests a shorter or more general word, which
  helps; it does not fix anything.
- **A category menu in the header.** The design export attaches "All
  categories" to the search box. Searching from the header searches everything,
  and narrowing happens on the results page.
- **Sorting.** One order per search, chosen by the API. Nothing takes `sort`.
- **An error page.** There is no `error.tsx`, so an API failure during a search
  shows Next's default.
- **Endless scrolling.** Pages of 24. ADR 0022 records why cursor pagination
  would be the tool for that, and why it has not been needed.
