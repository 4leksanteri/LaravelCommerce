# 0040 - Taking a payment

Status: accepted - 2026-09-12

ADR 0015 decided the shape of payments and built none of it. This is the first
half: money taken from a buyer and held on the platform. Transferring it to a
shop and refunding it are the second half, and are not built here.

---

## What ADR 0015 already settled, and is not revisited

```text
one PaymentIntent per order    an order is one shop in one currency
charge, hold, then transfer    completion releases the money, not payment
the platform is the merchant   so it carries the dispute liability
webhooks are the truth         idempotent on event.id
test mode, and only test mode
```

Three of the five things it left open are answered here. The other two,
transfers and refunds, belong to the half that moves money out.

## One card entry for a basket, not one per shop

A basket spanning three shops is three orders, three currencies and three
intents. ADR 0015 left the buyer's side of that open, naming the better and
harder option. This takes it.

```text
browser   collects the card once, in Stripe's frame, and gets a pm_...
API       confirms the first intent with it, on-session
Stripe    saves the card against the buyer's customer
API       confirms the rest against that card, off-session
```

**The first one has to land before the others are attempted.** A card needing
3DS comes back `requires_action`, and nothing can be charged against a card
that has not been authenticated. Confirmation stops there, the browser
authenticates with the client secret it was given, and calls the endpoint
again - which resumes, because a payment that already succeeded is skipped.
The endpoint is therefore safe to call repeatedly, which is what makes a
half-finished basket recoverable rather than stuck.

**Cards only.** Stripe's other methods are mostly redirect flows, and a
redirect away from the site in the middle of paying for three orders is a
return path with three states to rebuild. Cards authenticate in place, which is
what makes one card entry workable at all.

**A buyer gets a Stripe customer the first time they pay**, because a payment
method cannot be reused without one. It holds an email and an id. An account
that never buys anything never appears in Stripe.

## The browser holds the card, and this application never sees it

Stripe.js collects the card inside its own frame and hands back a payment
method id. `pm_...` is the only thing the endpoint takes, and card numbers,
expiry dates and security codes never reach this application - which is what
keeps it out of PCI scope.

**The publishable key is sent by the API**, with the payment that needs it,
rather than given a `NEXT_PUBLIC_` name and inlined into the browser bundle.
Both work; this way every Stripe setting lives in one place and the web
application holds no Stripe configuration at all.

**The client secret is stored.** It is the handle the browser confirms an
intent with, and the alternative is a call to Stripe for every unpaid order
every time a page is drawn. It is not a credential of this application's: it
authorises exactly one intent, is useless without the publishable key, and is
shown only to the buyer whose order it is - and never once that order is paid,
because there is nothing left to confirm.

## The row is a copy, and Stripe is right

`payments` mirrors what Stripe last said about an intent, exactly as
`payout_accounts` mirrors an account (ADR 0031). Where the two disagree, Stripe
wins and a webhook brings the row into line.

**`payment_intent.succeeded`, `.payment_failed` and `.canceled` are the truth**,
and what the browser reports is a hint: a browser that closes mid-confirmation
still produces the event. Each is acted on once, through the same
`stripe_events` table and the same transaction as its effect, and none of them
trusts the event's copy of the intent - the intent is fetched, because Stripe
does not promise to deliver events in order.

That handler moved out of `Actions\Payouts` and into `Actions\Stripe`. It was
never about payouts; it was about Stripe having something to say, and payments
arrive through the same signature check and the same idempotency.

**Stripe has no `failed` status**, which is the one case in `PaymentStatus`
that is not a rename. A refused card leaves an intent at
`requires_payment_method` with a `last_payment_error`, indistinguishable from
one nobody has tried to pay unless the error is read. Those mean very different
things to a buyer, so they are two cases here.

## `paid` is still not an order status

`orders.status` is about fulfilment - accepted, sent, completed - and stays
that way (ADR 0015). Whether the money arrived is a different question, and it
is answered by the order's payment.

That separation is why this change adds no column to `orders` and no case to
`OrderStatus`.

## What is charged is never what a client sent

The intent's amount is the order's own total, snapshotted at checkout from
prices read under lock (ADR 0011). The request body carries a payment method id
and nothing else - there is no field on it that could change a figure.

**Creating an intent is idempotent twice over**: an order that already has a
payment is left alone, and the creation carries an idempotency key derived from
the order's reference, so a retry after a network failure returns the same
intent. A duplicate intent is a second way to charge somebody, which makes this
the one place in the application where that key is not optional.

Intents are created **after** the checkout transaction commits. Inside it, a
call to Stripe would hold a lock on every variant in the basket for the length
of a network round trip. If it fails, the orders still stand and the payment
endpoint creates what is missing on the next read.

## The fee is configuration, and is not taken yet

`STRIPE_PLATFORM_FEE_BPS` is 500 basis points - five per cent - deducted from
the transfer when an order completes. Basis points rather than a percentage
because money is never a float (ADR 0004), and configuration rather than a
constant because what a marketplace charges should not need a deploy.

Nothing reads it yet. It is written down here because the value was decided
with the rest of this, and the transfer that spends it is the next change.

## A shop that cannot be paid can still be bought from

ADR 0015 asked whether Stripe verification should become a second gate beside
staff approval. It should not gate **buying**: the platform holds the money for
every order regardless, and a shop finishing its Stripe setup a day after its
first sale costs nobody anything. The transfer is what cannot happen, and that
is the seller's problem to see on their payouts page.

Gating checkout on it would also make every seeded demo shop unbuyable, which
is a straightforward signal that the rule is wrong rather than merely
inconvenient.

## Testing

PHPUnit covers what this application decides: an intent per order at checkout
with the order's own total, the idempotency key, one customer per buyer, the
first confirmation on-session and the rest off-session, a run that stops when a
card needs authenticating, a decline recorded as Stripe's own sentence rather
than thrown, a second payment attempt on a paid checkout refused with 409, and
another buyer's checkout answering 404. Stripe is `FakeStripe` throughout, so
the parameters asserted on are the ones that would leave for Stripe.

The webhook tests extend to payments: a succeeded event brings the row into
line, the same event twice is acted on once, and a failure leaves no record so
Stripe's retry is acted on.

---

## Not yet decided, or deliberately deferred

- **The web half.** No card form exists yet; this change is the API. The
  checkout page still places orders and says nothing was charged, which is
  true until the next change.
- **Transfers and refunds.** The money moves out on completion, less the fee,
  and is refunded on cancellation. Both are the next change, and both need the
  reconciling ADR 0015 names: a seller may cancel an order they have already
  shipped, and that is a refund of goods that have left.
- **Unpaid orders in a seller's queue**, and the short expiry that goes with
  them. ADR 0015 says an unpaid order must not appear to a shop and must not
  hold stock for three days. `payments.unpaid_expires_after_minutes` is
  configured and nothing reads it yet: the queue change and the expiry command
  land together, because doing either alone leaves the other half wrong.
- **The end-to-end suite now reaches Stripe.** Every checkout it drives creates
  a customer and an intent on the platform's test-mode account, because the
  development stack has a real test key. They are harmless test-mode objects
  and they accumulate, and the suite is now slower and dependent on Stripe
  being reachable. The alternative - a fake at the network in end-to-end as
  well - means the stack under test is no longer the stack that ships, so it
  was not taken. Worth revisiting if the suite starts failing for Stripe's
  reasons rather than this application's.
- **An order totalling nothing.** Stripe has a minimum charge, and a free order
  cannot have an intent at all. Nothing in the catalogue is free today.
- **Disputes.** The platform carries them (ADR 0015) and there is no screen,
  no notification and no state for one.
