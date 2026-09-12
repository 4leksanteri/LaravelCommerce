# 0042 - An unpaid order

Status: accepted - 2026-09-12

`pending` has meant one thing since ADR 0011: waiting for the shop to accept.
Since payments arrived it has quietly meant two, and this separates them.

ADR 0015 predicted both halves of this and ADR 0040 deferred them together,
because doing either alone leaves the other wrong.

---

## What `pending` was hiding

```text
placed, nobody has paid       holds stock, nobody has committed to anything
paid, waiting on the shop     holds stock, and a person owes an answer
```

An order is written before it is paid - stock is taken when it is written
(ADR 0011), and the intent is confirmed afterwards (ADR 0040). Between those
two moments the row exists and looks, to everything downstream, exactly like an
order a shop should be getting on with.

## A shop never sees one

The queue and the single-order lookup both go through `Order::scopePaid`, so an
unpaid order is absent from the list and answers **404** on its own page. Not
403: to this shop it does not exist yet, and saying "it exists and you may not
see it" would be a fact about somebody else's payment.

Carrying it in the query rather than checking afterwards is deliberate, and the
same reasoning `Seller::scopePublic` gives: a condition that is part of the
query cannot be forgotten by the next endpoint that needs it.

**The queue applies it from the model, not through the relation**, and that
cost a build to learn. `$seller->orders()->paid()` works perfectly at runtime
and forwards through Laravel's `__call`, which the OpenAPI generator cannot
follow - so the endpoint was published as an unpaginated array, losing the
`meta` the frontend reads to draw its pages. `apps/api/CLAUDE.md` section 4
already named this trap for `$shop->products()->public()`; this is the second
endpoint to walk into it, and `make api-check` is what caught both.

**Accepting one is refused anyway**, with a 409 read under the same lock as the
status. Nothing in the interface can reach it, so this is for a page that was
open when a card was declined, or a client calling the endpoint directly. It is
not a 404, because by then the caller is holding an order of their own that
simply has not been paid for.

Shipping needs acceptance and a seller's cancellation needs the order to be
visible, so neither needs a guard of its own. A guard with nothing that can
reach it is a rule nobody is applying (`apps/api/CLAUDE.md` section 8).

## The buyer still sees it, because they have to pay it

Only the shop's side narrows. The buyer's list, the order's own page and the
confirmation all show an unpaid order exactly as before - it is theirs, it is
where the card form lives, and hiding it would hide the thing they need to
finish.

## Two clocks, and the shorter one is the stock hold

```text
unpaid       ORDER_UNPAID_EXPIRES_AFTER_MINUTES   30 minutes
waiting      ORDER_PENDING_EXPIRES_AFTER_HOURS    72 hours
```

`orders:expire` now cancels on either, in one bounded pass. The three days were
always about a person being slow to answer, which is a reasonable thing to wait
for; nobody should hold the last of something for three days without paying,
and `config/orders.php` said in as many words that this number would shorten
when payments arrived.

The short clock is what ADR 0010 called the missing hold on the cart. It is not
on the cart - a basket holds nothing - but the effect is the one that was
wanted: stock is reserved for as long as it takes to type in a card, and not
longer.

An expired unpaid order is cancelled like any other, so its stock goes back and
both sides are told (ADR 0035). Its refund is a no-op: there was no money.

## The end-to-end suite pays for what it places

`placeOrder` now pays through `POST /checkouts/{reference}/payment` with
`pm_card_visa`, Stripe's own test payment method, confirmed by the API exactly
as the card form's would be.

That is what makes the suite exercise the whole path in a real browser run:
checkout, payment, a shop accepting, shipping, completing, and the transfer
that follows. It also means every spec that touches an order now depends on
Stripe being reachable, which is the same bargain ADR 0040 already took for
checkout.

The card form itself is still not driven: its fields are in Stripe's frame, and
typing into that frame tests Stripe.

## Testing

PHPUnit: a shop's queue omits an unpaid order and its page answers 404; the
same order is visible to its buyer throughout; accepting one is refused with
409; an unpaid order expires on the short clock while a paid one waits on the
long one; and an expired unpaid order returns its stock.

Every existing test that put an order in front of a shop now pays for it first,
which is the honest version of what those tests were always describing.

### Which is how we found the suite talking to Stripe

Making the fixtures write a payment turned up seven errors that moved between
runs and disappeared when the same suite was run again. They were all the same
insert, refused by `payments_order_id_unique`, and the row already there had
been written by checkout.

The suite had been using whichever keys Compose passed the container, so every
checkout in it opened a real PaymentIntent and a real customer. What made it
intermittent rather than simply wrong is the idempotency key
`buyer-customer-{id}`: `RefreshDatabase` hands out the same buyer ids on every
run, each with a new random email, and Stripe refuses a key reused with
different parameters. So a run succeeded at Stripe for the buyers whose keys
were fresh and failed for the rest, checkout swallowed the failures by design
(ADR 0040), and the orders whose checkout had succeeded already had a payment.

A green run was therefore evidence of nothing, and the keys being spent was
what made it green.

```text
tests/bootstrap.php   pins sk_test_suite, and keys that belong to nobody
TestCase::setUp       installs FakeStripe for every test, not on request
FakesStripe           replaces it for a test that queues answers, and does
                      not put the real network client back afterwards
```

`StripeStaysOffTheNetworkTest` asserts both halves without asking for a fake,
which is the only way to assert a default.

The end-to-end suite is the deliberate exception and still pays through Stripe:
it runs against the stack, where the keys are the real ones and the point is
that the whole path works.

---

## Not yet decided

- **The surfaces**, still. Nothing shows paid, refunded or transferred
  (ADR 0041), and the buyer's own view does not lead them back to an unpaid
  order beyond the confirmation page.
- **Telling a buyer their order expired unpaid.** The cancellation mail says it
  was called off; it does not say "because nobody paid", and the distinction
  matters to somebody whose card was declined.
- **`paid` as an order status.** Still not one, and still for the reason
  ADR 0015 gives: fulfilment and money are different questions.
- **A second attempt after expiry.** The order is gone and the basket is empty,
  so a buyer whose card failed twice has to start again.
