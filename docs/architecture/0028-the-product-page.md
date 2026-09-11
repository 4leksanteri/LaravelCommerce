# 0028 - The product page

Status: accepted - 2026-09-11

Where every card has been pointing: what a listing is, who sells it, what it
costs, its photographs, and the way to buy it. Also the first page where
somebody does something rather than reads something.

---

## The page is public, and only its one action needs a session

ADR 0023 left open how a page that needs somebody signed in should behave. This
page answers the narrower half of that, deliberately.

Everything here is readable signed out. What needs a session is adding to the
cart, and the server knows before the page is drawn whether there is one. So the
purchase control is a button for somebody signed in and **a link to sign in and
come back here** for somebody who is not. `AddToCart` takes that answer as
`signInHref`; it does not ask.

A session that ends between drawing the page and pressing the button is a 401,
and goes to the same place, with the same way back.

The other half - a page that is nothing without a session, like the cart - is
still to decide, and the cart will decide it.

## The price is the chosen option's own

A listing with two sizes has two prices (ADR 0009). The figure above the button
is the chosen option's `price_minor`, and changes when the choice does; it is
never a range once a choice is made, because the button adds one specific thing.
Each option's label shows its own price too, so the choice is made knowing it.

Sold-out options cannot be chosen, and the page starts on one that can be. When
nothing at all is left, there is no button: the page says sold out and still
says what it cost, because availability and price are separate answers
(ADR 0024).

## One at a time

There is no quantity field. Most of what is sold here is the only one of its
kind, and a spinner would need its own copy of the API's quantity rules to know
where to stop - the same objection ADR 0026 made to a `minLength`. How many is
the cart page's question, where the API already answers it for each line.

## A 409 says what changed

`useApiSubmit` had no case for 409, so "only one left" would have reached the
page as "something went wrong at our end". That is the collapse
`apps/web/CLAUDE.md` section 9 forbids, and it was invisible until the first
screen that could get one. A 409 now shows the API's own message, which is the
next step the person needs.

---

## Photographs, through the code a seller's upload reaches

The demo catalogue now has them: striped placeholders in the design export's own
palette, pushed through `StoreProductImage` exactly as an upload is - decoded,
stripped, resized and stored as WebP (ADR 0016). Three on each shop's first
listing, fewer after, none on one, so a gallery, a single photograph and none
are all drawn somewhere.

That made the whole image arrangement testable for the first time. The end to
end test follows a photograph from the upload code, through the API's
`/api/v1/images` route and the proxy, to Next's optimiser under the
`localPatterns` rule ADR 0024 wrote, and checks the optimiser answers with an
image.

PHPStan found something real in the seeder: GD's `imagecolorallocate` is typed
to take `int<0, 255>`, and a channel parsed with `hexdec` is only `int`. The
range is applied with `min` and `max` rather than asserted, so it is a bound the
analyser proves instead of one it takes on trust.

The gallery shows the large photograph with `object-contain`. A card crops to a
square because it is one of many; here the whole photograph is the point.

---

## Smaller things worth knowing

- **The header's cart count had a name problem**, spotted while writing the
  assertion for it: a number beside a word reads as "Cart1", the same trap as
  "fromDKK" (ADR 0024). It is named in words now.
- **`router.refresh()` alone does redraw the header.** Adding to the cart
  refreshes, and the count follows. The sign-out failure in ADR 0025 came from
  following a refresh with a push to the same URL, not from refreshing.
- **The shop's name is text, not a link.** There is no shop page; a link that
  always led to not-found would be the one dead end on a page people act on.
- **A draft, a deleted listing and an unapproved shop's listing are the same
  404**, from the API and therefore here. None says which it was (ADR 0007).
- **The listing is read once per request**, through React's `cache`, because
  `generateMetadata` and the page both need it and `serverFetch` skips Next's
  fetch cache on purpose.

---

## Not yet decided

- **The contract does not declare this endpoint's 404.** It answers one at
  runtime and the page handles it, but Scramble does not see the `firstOrFail`
  behind the extracted shop lookup, so the generated types say it cannot.
- **A shop page.** Whether the storefront exists at all is still open.
- **Buying from your own shop.** Nothing stops a seller adding their own listing
  to their cart (ADR 0010); checkout is where that rule belongs.
- **The large photograph is not preloaded.** It is the largest thing on the page
  and would benefit, and the right option in this version of Next has not been
  checked yet.
