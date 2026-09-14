# 0053 - The shop's page

Status: accepted - 2026-09-14

ADR 0028 built the listing's page and left one thing open in as many words: "a
shop page. Whether the storefront exists at all is still open." Until it was
settled, a shop's name was plain text everywhere it appeared, because "a link
that always led to not-found would be the one dead end on a page people act on".

This settles it. In a marketplace for secondhand equipment a buyer judges the
seller as much as the item - what else they have, what they say about
themselves, what they charge in - and there was nowhere to do that.

---

## The API already had it, and no page was using it

```text
GET /shops/{slug}            PublicShopResource, through Seller::scopePublic()
GET /shops/{slug}/products   paginated 24, withRating(), newest first
```

Both existed before this change and neither had a caller in the web
application. So this is assembly rather than a new domain: no migration, no
action, no resource, and nothing added to the contract.

**The shop's listings endpoint is a public browse filtered by shop**, shaped
like the category listing rather than as `$shop->products()` - which
`PublicProductController` explains at length, because a scope reached through a
relation is invisible to the generator and this endpoint once published no
`meta` because of it.

## The API decides whether the shop exists

An unapproved shop, a **suspended** one (ADR 0052) and a slug nobody ever used
are the same 404 from `GET /shops/{slug}`, and become this application's
not-found page. Nothing on the page reads a status to decide, and nothing says
which of the three it was: "awaiting review" would tell anybody who guessed a
slug that somebody applied under it (ADR 0007).

That also means suspension reaches the storefront through one more route
without anything being written for it, which is the claim ADR 0052 rests on.

## Where the name became a link, and where it deliberately did not

```text
the listing page   links        it only renders for a public shop
a product card     stays text   one link per card, and it names the product
a buyer's order    stays text   an order outlives its shop's standing
the checkout       stays text   same
staff surfaces     stay text    a case being decided, not a shop to visit
```

**An order outlives a suspension, and that is the whole reason for the split.**
A suspended shop's page answers 404, and the orders placed with it do not go
away - a buyer still has to receive the parcel, confirm it or dispute it. A
link from the order page would be a dead end exactly when something has gone
wrong, which is the failure ADR 0028 refused to introduce. The storefront has
no such problem: a card or a listing page only exists while the shop is public,
because `Product::scopePublic()` requires it.

So ADR 0028's rule was not overruled. Its reason simply stopped applying in one
place and still applies in the other.

**The card was linked first, and its own test refused it.** `ProductCard`
stretches one anchor over the whole article so that a screen reader hears a
single link named for the product "instead of one link containing a
photograph, a shop, a title and a price", and `product-card.test.tsx` asserts
exactly one link per card. Adding a second made that query find two.

The test was right and the change was wrong: a grid of twenty-four cards would
have carried forty-eight links, and the dead-link reasoning above says nothing
about whether a second link belongs on a card. So the card still says which
shop a listing is from, as text, and the listing's page is where that becomes a
link - one click further, and the shop page's breadcrumb is the way back.

## What the page does not have

**No shop rating.** ADR 0047 left that open on purpose - "one bad listing is
not one bad shop" - and an average across a shop's listings is a different
aggregate with a real design question behind it: an average of averages
over-weights a listing with one review. Inventing one here in passing would be
the kind of unasked-for rule this codebase keeps refusing. The cards carry
their own per-listing ratings, which is what exists.

**No messaging the shop.** ADR 0050 lists it, and this is where it would live -
but a conversation is scoped to an order today, and opening one from a shop page
is the chapter that has to answer rate limiting and blocking first.

The contact email **is** shown. `PublicShopResource` publishes it and says why:
it is the address the seller chose for being contacted, not the one they sign
in with.

## Testing

Playwright: the shop's page lists what the shop sells and says how many; a
listing's page reaches it by the shop's name; an unknown slug is the not-found
page; and a **suspended** shop's page is not found either, which is ADR 0052's
propagation asserted from the storefront rather than from the API. Both demo
shops go into `PAGES`, so the page is held to the phone-width and axe checks
every public page is - unlike the staff pages, which are checked inside their
own specs because they need a session.

Vitest: the product card still says which shop a listing is from, and still does
not make it a second link - the test that already asserted one link per card is
what caught the attempt.

---

## Not yet decided

- **A shop rating**, above. It is the obvious next aggregate and ADR 0047 owns
  the question.
- **Messaging a shop from its page.** ADR 0050 owns that one.
- **Sorting or filtering within a shop.** The listings are newest first and
  cannot be narrowed, which is fine for sixteen and not for six hundred.
- **Anything about the seller as a person.** No joined date, no number of sales,
  no response time - the export shows a reply time and there is nothing behind
  it.
- **A link from an order to the shop.** Left as text above, and it would become
  possible if a suspended shop ever kept a page that said so rather than
  answering 404.
