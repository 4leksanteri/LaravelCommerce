# 0056 - Buying from your own shop

Status: accepted - 2026-09-14

Five ADRs have deferred this one rule, each to the next. ADR 0010 named it and
said where it belonged; 0011, 0028, 0029 and 0030 each recorded that it was
still not written. It is the most-repeated open item in this repository, and
that repetition is the thing worth paying attention to.

It also got worse while it waited. When ADR 0010 deferred it, an order was a
promise between two people and nothing else rested on it. Since then:

```text
ADR 0040/0041  a card is charged and a payout is released
ADR 0047       a review is earned by a completed order, and is
               "structurally" a verified purchase because of it
ADR 0051       a dispute decides where held money goes
```

So today a shop owner can buy their own listing, pay with their own card,
accept and ship to themselves, confirm it arrived, receive a transfer of their
own money less the platform fee, and then leave their own listing a review that
the marketplace presents as verified. **Every one of those claims is one this
codebase makes about itself**, and the hole makes them untrue.

---

## The rule went where ADR 0010 said it would

```text
the cart      lets the line in, and says whose it is
checkout      refuses, and refuses the whole basket
```

ADR 0010 was explicit: "It is a **checkout rule rather than a cart rule**." That
is followed here rather than revisited, and it turns out to be the design that
costs least.

**Nothing is added to `AddToCart`.** A guard there would be a second place the
rule lives, and the cart's door is not where money moves - it is where somebody
is browsing. What the cart does instead is answer honestly about the line, which
is what it already does for every other line that cannot be bought.

## A fifth `CartItemAvailability`, and it answers first

`CartItemAvailability` already existed to say "why a line cannot be bought, or
that it can", with three ways of being unbuyable. `YourOwnShop` is the fourth.

**It is checked before stock and before the listing being withdrawn**, and the
order is load-bearing. Told "out of stock" about their own listing, a seller
would restock it and try again; told "no longer for sale", they would republish
it. Only one of those answers never becomes true, and it is the one that has to
be given. The same reasoning `PublishProduct` uses for checking a takedown
before a missing category.

It is the odd case in that enum and the docblock says so. Every other case
describes the catalogue moving underneath a durable cart; this one was true from
the moment the line was added and cannot stop being true.

## What that bought, for free

Nothing in checkout changed. `PlaceOrders::revalidate()` already refuses any
line that is not available, through `CheckoutBlockedException`, so:

```text
the whole basket is refused     ADR 0011's all-or-nothing, unmodified
the blocked line is named       so the cart can mark it in place
the shop subtotal excludes it   subtotals already count only what can be bought
nothing is written             no order, no stock taken, the cart as it was
```

A rule that needed a new mechanism would have been a sign it was in the wrong
place.

## `is_your_own`, and why not `can_buy`

The product page publishes `is_your_own` so it can stop offering a control that
would lead to a cart line which can never be bought.

`can_buy` was the obvious name and is the wrong one. It would be a single word
for two unrelated refusals - `in_stock` already answers "is there any left" -
and it would have to be **true** for a signed-out visitor, who can buy perfectly
well once they sign in. One question per field, and the page combines this with
`in_stock` the way it already does.

**This does not move the rule to the browser.** Checkout refuses regardless of
what any page draws (ADR 0008); hiding the button is about not offering a dead
end, and a seller still sees their own price, as they should - a shop owner
looking at their listing as a shopper sees it wants to see what a shopper sees.

## And a limit on reports

Smaller, and here because it is the same kind of hole: a bound that was assumed
rather than enforced.

ADR 0054 noted that one open report per person per thing "bounds the obvious
abuse, and nothing bounds somebody reporting a thousand different listings once
each". Every report is read by a person, so the cost of that falls entirely on
the moderator - the same asymmetry `seller-application` is limited for, and the
same answer: **ten an hour, by account**, on both report endpoints.

By account rather than by IP, because a report belongs to an account and one
shared office should not exhaust everybody else's. The limiter counts attempts
rather than successes, so a refused duplicate still spends the allowance; what
is being limited is the traffic, not the reports.

## Testing

PHPUnit: a line from your own shop is `your_own_shop` in the cart, contributes
nothing to the subtotal, and **is answered ahead of both stock and withdrawal** -
asserted by selling out and then unpublishing the same listing and watching the
answer not move; another shop selling the same kind of thing is unaffected;
checkout refuses a basket holding one, refuses **the whole** basket with no order
written, no stock taken and the cart intact; and a shop owner buying from
somebody else is an ordinary shopper. Reporting is rate limited, asserted as a
429 carrying `Retry-After` after the allowance, the way `LoginTest` asserts it.

Vitest: the cart line says why, in the same place it says everything else. The
exhaustive `switch` in `cart-line.tsx` is what made that unmissable - its
comment already promised that "a fifth availability added to the API fails the
type check here", and it did.

---

## Not yet decided

- **Buying from a shop you are connected to but do not own.** A second account
  is enough to get round all of this, and always was. Nothing here pretends to
  solve collusion; it closes the case the system can actually see.
- **Reviews already written this way.** Nothing goes back over existing orders
  or reviews to find self-purchases. There are none in any real deployment
  because there is no real deployment, and a migration that guessed would be
  worse than none.
- **A shop owner's own listing in an order placed before this.** Same shape, and
  the same answer: nothing rewrites history.
- **Reporting from more than one account.** The limit is per account, so the
  bound on a determined person is however many accounts they will make. Tying it
  to anything else means identity, which is a much larger question.
- **What a seller sees on their own listing.** A sentence and a price. A link
  into the shop's own editor would be more useful and needs the listing's id,
  which the public resource deliberately does not publish.
