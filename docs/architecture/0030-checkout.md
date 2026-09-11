# 0030 - Checkout

Status: accepted - 2026-09-11

Where a basket becomes orders: one address, one order per shop, and a
confirmation somebody can come back to.

---

## The cart says whether it can be checked out

Checkout needs a confirmed email address; that is the `verified` middleware on
the checkout route (ADR 0011). A checkout page that looked at
`email_verified_at` and decided for itself would be a second copy of that rule,
and the copy that drifts. A page that said nothing would let somebody fill in an
address and only then be refused.

So the cart answers the question. `checkout_blocker` is one of `empty`,
`unverified_email` or `unavailable_items`, or null when nothing is in the way,
and the checkout page draws the next step for whichever it is. It sits beside
`has_unavailable_items`, which the API already described as the answer a
checkout button needs.

**One reason, in the order a person would meet the refusals.** An empty basket
first, since there is then nothing to check out at all. After that, the order
checkout itself refuses in: the `verified` middleware runs before PlaceOrders
revalidates the basket, so an unconfirmed address is the answer even when a line
is also unavailable.

**The answer and the rule are tested as one.** CheckoutBlockerTest builds each
kind of basket, asks the cart what is in the way, then asks checkout to proceed:
`unverified_email` is a 403 from checkout, `unavailable_items` is a 409, and no
blocker is a 201. If either side changes without the other, it fails.

It is computed for the viewer, from the request, as the `can_*` fields
elsewhere are, and fails closed if there were somehow no viewer.

## A 403 now says what the API said

`useApiSubmit` answered every 403 with "That link is no longer valid" - a
sentence written for the password-reset screen. At checkout it would have told
somebody with an unconfirmed address that their link had expired. It now shows
the API's own message, as a 409 already did.

---

## One address, and only its id

Every order a checkout creates freezes the same destination (ADR 0021). The
newest address is chosen to start with; the API lists newest first, which makes
that a reasonable guess rather than a decision.

**What reaches the API is the address id and nothing else.** The cart, the
prices and the totals are read on the server under lock (ADR 0011).

A new address is added through the address book's own endpoint, from a form
beside the checkout controls rather than inside them, because forms cannot nest.
Country is a text box asking for two letters rather than a list of countries: a
list would be a decision about where things can be sent, and the API has not
made one (ADR 0021).

A 409 means the basket moved between drawing the page and pressing the button,
so the page is redrawn and the cart's answer then says what is in the way - one
place saying it, not two. A 404 means the chosen address was removed in another
tab.

## The confirmation has an address of its own

`/checkout/placed?orders=K7M2QXV9RT,8Y9JN63MTC`, read back through
`GET /orders/{reference}`. That resolves through the buyer's own orders, so
somebody else's reference in the URL is a 404 and shows nothing. At most ten are
read; a URL is not a way to make a page fan out a hundred requests.

Drawing the confirmation from the checkout response in the browser would have
been simpler and wrong: it vanishes on a reload, and the checkout page redrawn
with an empty basket would unmount it.

## Getting there is a full page load, and the test found why

The first version went to the confirmation with `router.push`. The end-to-end
test failed on the header: the confirmation was drawn under a header still
saying "Cart, 2 items" over a basket that checkout had just emptied.

**A client-side navigation keeps the layout as it was drawn**, and the header
lives in the layout. That also explains the two earlier observations properly.
Adding to the cart updated the header because it calls `router.refresh()`,
which redraws layouts. Signing out did not (ADR 0025).

Placing orders and signing out are both transaction boundaries: afterwards,
everything the client router holds was drawn for a state that no longer exists.
Both now end in `loadFresh`, a full page load, which keeps the reasoning in one
place and lets a unit test replace it. The lint rule against a relative
`location.assign` does not fire through it, because the destination is a
parameter rather than a literal, so the exception the sign-out button needed
went away with the move rather than moving with it.

---

## No card is charged, and the pages say so

Orders are placed and move through their states for real. Money is not taken:
payments are decided in ADR 0015 and not built. The checkout button says "Place
orders", not "Pay", and both the button and the confirmation say plainly that
no card was charged.

The rest of the site's copy - the header's "every payment is held until you
confirm the parcel arrived", and the home page's three steps - describes how an
order completes, which is true, and how money moves, which is not yet. That copy
is left as it was and flagged here, for the owner of the project to decide.

## Real orders take real stock

The end-to-end test places orders, and checkout takes stock. It buys the two
listings with stock to spare rather than the one the product and cart specs
depend on, and it cancels every order it placed in a `finally`, through the
buyer's own cancellation - which gives the stock back exactly as a person
cancelling would. That tidying ran even on the run where the header assertion
failed.

`make seed-demo` also puts demo stock back to what the seeder says on every run,
as a net under a run that dies before it can tidy up.

---

## Not yet decided

- **The contract does not declare checkout's 403.** The `verified` middleware
  produces one and the page handles it, but Scramble does not see middleware, so
  the generated types say checkout cannot answer 403.
- **Payment.** The whole of it (ADR 0015).
- **Buying from your own shop.** Still nothing stops it; checkout is where the
  rule would belong (ADR 0010).
- **A quoted total.** Checkout charges whatever the price is when the button is
  pressed, and the cart flags a price that moved beforehand (ADR 0011).
- **Managing the address book.** Addresses can be added from checkout. Editing
  and deleting exist in the API and have no page.
- **The order history.** The confirmation links nowhere further yet; `/orders`
  and a single order's page are next.
