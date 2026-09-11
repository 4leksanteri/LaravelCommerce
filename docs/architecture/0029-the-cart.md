# 0029 - The cart

Status: accepted - 2026-09-11

The first page that is nothing without a session, and the first where
somebody changes something and sees the consequence priced.

---

## A page that is nothing without a session redirects

ADR 0028 answered half of how a page should behave for somebody signed out: a
public page whose one action needs a session draws a way to sign in in place of
that action. The cart is the other half. There is nothing on it for anybody but
its owner, and an empty shell that could only say "sign in" is worse than
taking them there.

`requireUser(next)` returns the signed-in person or redirects to sign in with a
way back. It was extracted here because the cart was the second page doing the
same thing inline; the page saying a confirmation email is on its way was the
first, and now uses it too.

It answers "who is looking" and nothing more. It decides no permission - what
the person may do is still the API's to refuse - and `next` goes through
`safeRedirect` on the far side like any other return address.

## Drawn from the API's answers, all of them

A line's price is read from its variant each time the cart is shown (ADR 0010).
Its total, the shop's subtotal and whether each line can still be bought all
arrive computed. After any change the page is redrawn from `GET /cart`, and
nothing is worked out in the browser in between.

**The unit test for a line gives it a total that deliberately does not equal
price times quantity**, and checks the page shows that figure. A line that
multiplied for itself would show the product instead, and fail.

The price a line had when it was added is shown only to say it moved, and only
as what it was. The API has already said `price_changed`; "it went up" would be
a second answer to the same question.

Whether a line can be bought is one of four answers, and the switch over them is
exhaustive, so a fifth added to the API fails the type check rather than
rendering as though it were fine.

## One group per shop, and no total

Each shop prices in its own currency and each group becomes its own order and
its own payment (ADR 0004, ADR 0011). A figure adding euros to pounds is not a
number. The page says so in a sentence rather than leaving somebody to wonder
where the total went.

`has_unavailable_items` is described by the API itself as the answer a checkout
button needs, so the button is drawn from it: disabled, with the reason above
it, rather than hidden.

---

## Every quantity is the API's to accept

"One more" sends the new quantity and the API decides. Four in stock and a
fifth asked for is a 409, shown in the API's own words, and nothing here knows
the stock.

**At one, "fewer" becomes "remove".** Removing is its own action rather than a
quantity of nought, so the control changes what it does instead of sending a
number the API would refuse. When there are fewer left than a line asks for,
the API says how many, and "change to that" is offered - its number, not a
guess.

A 404 means the line was already gone, taken out in another tab. That is the
state the person wanted, so the page is simply redrawn. A 401 goes to sign in
and back to the cart.

---

## The end-to-end suite now signs in once

The cart spec was first written with a fresh account per test, and the count
showed what that would cost: eight registrations a run, against a limit of ten
an hour per IP, and signing in is limited to five a minute per address. Those
are the production limits. The second run of any hour would have failed on 429s
that looked like application bugs.

The suite works within them rather than being given looser ones of its own.
`make seed-demo` adds a demo shopper; a Playwright `setup` project signs in as
it once per run, through the real form, and saves the session; every signed-in
test reuses that session and empties the cart first through the API, so no test
inherits another's basket. Only the auth spec still registers, because
registering is what it tests.

A shared `register()` helper was extracted for the cart spec and deleted in the
same change once its callers went, because a helper with no caller reads as
though something uses it.

The saved session holds a live cookie, so its directory is gitignored.

---

## Not yet decided

- ~~**Checkout.**~~ **Built in [ADR 0030](0030-checkout.md)**, with the cart
  answering for itself whether it can be checked out.
- **Emptying the whole cart.** The API has `DELETE /cart`; the page offers only
  removing lines, and the end-to-end suite is the only thing that empties it.
- **Buying from your own shop.** Still nothing stops it (ADR 0010); checkout is
  where that rule belongs.
- **A quoted total.** The cart flags a price that moved, and checkout charges
  whatever the price is when the button is pressed (ADR 0011).
