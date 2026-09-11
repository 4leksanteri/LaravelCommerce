# 0024 - The shell and the front door

Status: accepted - 2026-09-11

After ADR 0023 a person could sign in and then had nowhere to go. This is the
header, the footer and the home page - the first time the application is
somewhere rather than a set of forms.

---

## Two route groups, and the difference is a directory

```text
app/(auth)/    bare. Somebody signing in is doing one thing.
app/(shop)/    the header and footer, around everything a shopper browses.
app/not-found  draws the header itself.
```

A layout that inspected the path to decide whether to draw a header would be
that decision made in the wrong place. Two groups make it structural.

`not-found` sits at the root rather than inside `(shop)` because Next sends
every unmatched URL there, and a group's own `not-found` only catches
`notFound()` thrown inside it. It draws the header because a 404 with no way
back to the shop is a dead end on top of a wrong turn. It is also what a
listing, shop or order that is not yours looks like - the API answers 404 rather
than 403 for those on purpose (ADR 0007, ADR 0011) - so it must not guess which
it was.

## What the header leaves out

The design export has "Saved" and "Messages" beside the cart, and a search box
promising "240,000 items from 6,100 shops". There is no favourites domain, no
messages domain, and those numbers were invented for a mockup.

A header is the worst place to stub something into looking real: it is on every
page, so a dead link there is dead everywhere. What is in it is what exists - a
search box, the cart with its count, "Your shop" or "Open a shop" drawn from
`has_shop`, the order history, and sign-out, which until now nothing called.

**Search is a plain `GET` form.** No client component: the browser serialises
`q` and Next routes it. The one control every visitor uses works before any
JavaScript has loaded.

**Some of its links point at pages that do not exist yet** - `/search`, a
category, a product, `/cart`, `/orders`, `/sell`, `/seller`. They are the
permanent addresses, and the pages behind them are the next pieces of work; in
the meantime they land on `not-found`, which is honest about it. The alternative,
a header that grows links as pages arrive, would change shape every week and
teach nobody where anything is.

The strip under the navigation - "every payment is held until you confirm the
parcel arrived" - is the one claim this marketplace makes, and it is a
description of behaviour rather than marketing: completion on confirmation, or
fourteen days after dispatch (ADR 0014).

---

## The price on a card is the API's answer

A listing with two sizes has two prices (ADR 0009), so "what does this cost" has
no single answer and something has to decide which figure a card advertises.
That is a rule, and a rule the browser derives from `variants` is the copy that
drifts: the day the answer starts excluding sold-out sizes, every card would
disagree with it.

So `PublicProductResource` now sends `price_from_minor`, `price_to_minor` and
`in_stock`. Equal prices mean one price; different ones mean the card says
"from". The range is over every variant, sold out or not, because availability
is its own answer - a sold-out listing still says what it cost.

## Money is formatted by asking the currency

`formatMoney` divides by `10 ** digits` where the digits come from `Intl`, not by
a hundred. Every currency a shop can use today has two minor-unit digits, and
`App\Enums\Currency` says in as many words that no code should rely on it: a
zero-digit currency would otherwise be out by a factor of a hundred with nothing
failing.

**One locale, fixed.** Server and browser must render the same string or React
reports a hydration mismatch, and the server's default locale is whatever the
container happens to have. When the interface is translated the locale becomes a
parameter both sides agree on, not a default either guesses.

## The image optimiser refuses query strings

`images.localPatterns` allows `/api/v1/images/**` and nothing else, with
`search: ""`. The second half is the security decision.

A photograph on a public listing has a plain URL. One that is not on sale is
served against a signature that expires within the hour (ADR 0016), and the
signature is in the query string. The optimiser caches by URL and keeps the
result as long as it likes, so a signed URL let through would keep serving a
private image after its signature expired. Refusing query strings keeps them out
of the cache entirely.

## The export's colour rule, first used

The export's design notes reserve **amber for money being held** and **green for
money released**, so escrow state reads at a glance everywhere. The home page's
three steps are the first place it applies, through the `caution` and
`positive` tokens ADR 0019 added for exactly this.

---

## A demo catalogue, kept out of `db:seed`

With one unpublished listing in the database, the home page rendered its empty
state and the product card had never drawn once. That is a verification gap, not
a cosmetic one, so `make seed-demo` now opens five shops with sixteen listings.

Chosen to exercise what the pages must get right: four currencies, listings with
several prices, two sold out, staggered publication dates. No photographs -
images go through the real upload pipeline, and a seeder that wrote files around
it would exercise a path the application never takes.

**It is deliberately not called by `DatabaseSeeder`.** Categories are platform
data every environment needs; invented shops are not, and a `db:seed` run
anywhere that mattered must not quietly open them.

The first draft had a bug the render found. It advanced publication dates shop by
shop, so the home page's eight newest cards were three shops' catalogues in a
row - the arrangement least likely to show a formatting bug, since most of the
cards shared one currency. It publishes round-robin now, and the comment that
claimed it already did has been corrected.

The same render found a smaller one: "from" and the price sat in adjacent
elements separated by a margin, which a screen reader can read as
"fromDKK 950.00". A real space fixes it; a margin only separates them for the
eye.

---

## Not yet decided

- **Every page the header links to.** ~~Search results~~ **built in
  [ADR 0026](0026-the-search-page.md)**, ~~a category~~ **in
  [ADR 0027](0027-the-category-page.md)**, ~~a product~~ **in
  [ADR 0028](0028-the-product-page.md)**, and ~~the cart~~ **in
  [ADR 0029](0029-the-cart.md)**. Orders and the seller area remain.
- ~~**Phone width has not been seen in a browser.**~~ **Now it has**, by
  [ADR 0025](0025-testing-the-frontend.md): every page is loaded at 375px in
  Chromium and fails if anything scrolls sideways. The reasoning above held -
  none did.
- **Sign-out did not redraw the page**, and this ADR shipped it that way. From
  the home page, `router.refresh()` followed by `router.push("/")` left the
  header drawn for somebody the API had already signed out. The first
  end-to-end run caught it; it now ends in a full page load (ADR 0025).
- **The cart count costs a request.** A signed-in person's every page fetches
  `/cart` to draw one number. Fine at this size; the day it is not, the API
  sends a count on `/auth/me` or the header stops showing one.
- **Twenty-four fetched to show eight.** `GET /search` has a fixed page size and
  no `per_page` (ADR 0022 says why a client may not choose one), so "New in"
  throws away two thirds of what it asked for.
- **Photographs in the demo catalogue.** Every card currently says it has none.
