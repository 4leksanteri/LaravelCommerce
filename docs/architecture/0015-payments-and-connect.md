# 0015 - Payments, and which Connect

Status: accepted - 2026-09-10

The largest decision in the project, and one of the few that cannot be walked
back cheaply.

Nothing is built yet. This records what will be built and why, because the
account type is fixed at the moment an account is created and is not a
configuration flip afterwards.

> **Amended by [ADR 0031](0031-payout-accounts.md).** The connected account is
> now built. A shop opens one once staff have approved it, Stripe is asked for
> the `transfers` capability only, and verification is collected through this
> API. Charges, transfers and refunds are as described below, and still not
> built.

---

## Stripe Connect, not Stripe

A marketplace holds money that belongs to somebody else. Collecting funds from
buyers and remitting them to sellers out of a single merchant account is the
aggregator pattern, and it is regulated activity - money transmission in the
US, a licensed payment institution or e-money under PSD2 in the EU. It is also
against Stripe's own terms for a single account.

Connect exists so the platform is not the licensed party: the seller's money
relationship is with Stripe and its banking partners.

A ledger of seller balances in our own database, with payouts we arrange, is not
a shortcut around that. It is the same regulated activity plus double-entry
accounting, payout batching and reconciliation that Connect would have provided.
**The "simpler" option is the bigger build.**

---

## Custom accounts, and the honest reason

```php
'controller' => [
    'fees' => ['payer' => 'application'],
    'losses' => ['payments' => 'application'],
    'requirement_collection' => 'application',
    'stripe_dashboard' => ['type' => 'none'],
],
```

`requirement_collection: 'application'` is the fork. It means **this platform
collects the verification information**, rather than handing the seller to a
Stripe-hosted page. Stripe still runs the verification; we own every screen.

The SDK is explicit about what that changes:

> A platform can only access a subset of data in a person for an account where
> `account.controller.requirement_collection` is `stripe`, which includes
> Standard and Express accounts.

So the choice also decides how much we can see and prefill.

**The reason for choosing it is not that it is better.** For a marketplace
meaning to trade, Express is the right answer: Stripe hosts onboarding, owns
KYC, and the platform still controls fees, payout schedule and the day-to-day
interface. Less work, less liability, same commercial outcome.

Custom is chosen here for two reasons that are worth being straight about:

- **This is a project to learn from, and it is never deployed.** Everything runs
  in Stripe test mode. There is no real seller, no real money and no real
  verification, so the liability that would normally decide this carries no
  weight.
- **The sibling project already does it the other way.** AdonisCommerce uses
  Express with hosted onboarding and the Express dashboard. Building the same
  solution twice demonstrates less than building both.

An ADR that invented a technical justification here would be a worse record than
one that says "deliberately the harder path, and here is what it costs".

## What the platform takes on

- **Requirements are dynamic and cannot be hardcoded.** The form is whatever
  `account.requirements.currently_due` says, which varies by country, by
  business type, and over time as Stripe's rules change. This is the bulk of the
  work, and most of it is in the interface rather than here.
- **Identity documents pass through our infrastructure** on their way to
  Stripe's Files API. Test mode uses token files, so nothing real is handled,
  but the shape is ours.
- **Terms of service acceptance is ours to record**, with IP and timestamp,
  because no Stripe-hosted page is doing it.
- **`account.updated` is load-bearing rather than a nicety.** It is how we learn
  a verified seller has gone back to `currently_due` after a rules change.
- **Losses sit with the platform**, per `losses.payments: 'application'`.

---

## One PaymentIntent per order, and ADR 0004 is why

A cart spans shops, and shops price in different currencies (ADR 0004,
ADR 0007). A PaymentIntent has exactly one currency.

**So there cannot be one payment for a basket.** Not as a preference - it is
what "never sum across currencies" means when it meets a payment processor.
One PaymentIntent per order, which is already one per shop, in that shop's
currency.

The structure this needs was built before there was any reason to know it.

## Separate charges and transfers, because completion releases the money

The lifecycle already says a payout is released when a buyer confirms receipt
(ADR 0012, ADR 0014). That rules out direct charges, where funds land in the
seller's balance at the moment of payment - that is not escrow, it is a payment.

```text
checkout     charge on the platform, in the shop's currency, held
completed    transfer to the connected account, less the platform fee
cancelled    refund
```

Because each charge is already in the shop's own currency, the transfer needs no
conversion. That falls out of the per-order split rather than being arranged.

The cost is that **the platform is merchant of record and carries the dispute
liability.** That is the price of holding funds, and it is the honest one for a
marketplace whose whole promise is that it holds them.

---

## What this changes in what already exists

**A second gate on a seller.** Staff approval exists; Stripe verification is a
new one, and a shop can pass the first without the second.
`Seller::scopePublic()` is currently the single definition of what the
storefront shows, and this adds a condition to it or sits beside it. Deciding
which is part of building it - a shop that cannot take money should probably
still be able to draft a catalogue, exactly as an unapproved one can.

**`pending` splits in two.** Today it means "waiting for the seller". It becomes
"waiting for payment" and then "waiting for the seller", with two very different
clocks: minutes for an unpaid order, days for an unaccepted one. That is the
moment `ORDER_PENDING_EXPIRES_AFTER_HOURS` shortens sharply, as its own comment
predicts.

It is also what finally makes the stock model coherent. Checkout reserves stock
(ADR 0011) and nothing releases it except a cancellation; a short unpaid expiry
is the hold that ADR 0010 said was missing.

**`paid` is still not a status.** A payment record holds the PaymentIntent and
its state; `orders.status` stays about fulfilment. But an unpaid order must not
appear in a seller's queue.

**Refunds collide with a rule just added.** ADR 0014 lets a seller cancel a
shipped order, which was the right call for a lost parcel. Once money is
involved that cancellation is a refund of goods that have already left, and the
two need reconciling.

**Webhooks are the one endpoint that must be publicly reachable.** Unlike the
scheduler case in ADR 0013, Stripe has to reach us, so it belongs under
`/api/v1/webhooks/stripe` and goes through the Next.js proxy legitimately. Two
things were checked before committing to that:

- The proxy streams `request.body` without parsing it, so the exact bytes
  Stripe signed arrive intact. Signature verification would fail against a
  re-serialised body.
- Stripe sends no Origin or Referer, so `statefulApi()` never engages and the
  CSRF middleware never applies.

Handling must be **idempotent on `event.id`**, because Stripe retries.

---

## Test mode, and only test mode

There is no live key anywhere in this repository and there will not be. The
whole of this exists in Stripe test mode, which is why the KYC burden above is
academic: verification is a form with token documents.

---

## Not yet decided

- **How a multi-shop basket is paid for.** One PaymentIntent per order is
  settled; whether the buyer enters a card once and each is confirmed
  off-session, or confirms each in turn, is a **frontend** decision and there is
  no frontend. Off-session confirmation is much better and materially harder,
  because 3DS on any one of them needs a redirect.
- **The platform fee.** Nothing decides what the marketplace takes.
- **Payout schedule.** Whether transfers go out on completion or accumulate.
- **Refunds.** Partial, full, and what they do to the order lifecycle.
- **Whether an unverified shop may publish.** See the second gate above.
