# 0031 - Payout accounts

Status: accepted - 2026-09-11

Where a shop's money will go: a Stripe connected account, opened and verified
through this API rather than on a page Stripe hosts, as ADR 0015 decided. No
money moves yet. This is the account it will move into.

---

## Only an approved shop opens one

`POST /seller/payout-account` opens the shop's account at Stripe. A shop that
staff have not approved is refused with a 409, and the resource's `can_open`
says so before anybody tries. Verification collected for a shop nobody has
reviewed would be a person's identity, gathered for a shop that may never
trade.

That settles half of a question ADR 0015 left open, in the cautious direction:
approval first, then payouts. The other half, whether an approved shop without
a verified account may publish, stays open. Nothing charges yet, so nothing
needs stopping yet.

`can_open` and the action that refuses are two readings of one rule.
`test_can_open_agrees_with_what_opening_does` walks every kind of shop through
both, as CheckoutBlockerTest does for checkout.

## What the account is asked for

The controller settings are ADR 0015's, and OpenPayoutAccount lists them. Three
choices were made here.

- **The `transfers` capability and nothing else.** ADR 0015 charges on the
  platform and transfers to the shop when an order completes, so a shop's
  account is never charged against and never needs `card_payments`. Asking for
  less is also less to verify.
- **Individuals only.** A company brings representatives, owners and directors,
  each a Person at Stripe with a verification of their own. That is a build of
  its own, and a secondhand marketplace is mostly people.
- **Two answers are the platform's.** Stripe asks every account what kind of
  business it is and what it sells. Every shop here sells secondhand equipment
  (ADR 0019), so the platform answers: merchant category 5931, and a one-line
  description naming the shop. The alternative, the shop's public URL, is the
  better answer in production and is `localhost` in development.

Opening carries an idempotency key, so a retry after a network failure is the
same request to Stripe rather than a second account. The SDK adds one by itself
only when retries are switched on globally, and they are not.

## Stripe refuses this shape until the platform profile says so

`requirement_collection: 'application'` is not ours to choose alone. It is
gated on the Connect platform profile, and until that profile says this
platform collects and reviews the requirements, **every account creation is
refused**:

```text
Please review the responsibilities of collecting requirements for connected
accounts at https://dashboard.stripe.com/settings/connect/platform-profile.
```

Checked on 2026-09-12 against a test key, with the payload this action sends:
the call was refused and no account was made. Nothing in the code was wrong,
and no amount of reading it would have said so - the setting lives in a
dashboard.

Two answers in that profile have to match what is built here, and Stripe's
setup wizard defaults to the opposite of both:

```text
account creation      onboarding hosted by you, not by Stripe
account management    your own pages, not the Express Dashboard
```

The second follows from `stripe_dashboard: ['type' => 'none']`: there is no
Express dashboard to send anybody to.

**Once the profile said so, the same payload was accepted.** Checked the same
day, against the same test key: the account came back with the controller this
action asks for, `transfers` requested and inactive, `payouts_enabled` false,
and this still to collect for an Italian individual:

```text
external_account
individual.address.city, individual.address.line1, individual.address.postal_code
individual.dob.day, individual.dob.month, individual.dob.year
individual.first_name, individual.last_name
```

That list is what the pages here have to work through, and it is Stripe's to
change - which is why `currently_due` is read from the account rather than
written down as a form.

**The alternative was considered and not taken.** Express accounts, with
`requirement_collection: 'stripe'` and Stripe's hosted onboarding, are what the
wizard proposes and would delete the collection endpoints entirely - no name,
date of birth, ID number, IBAN or document would reach this application at all.
That is a smaller and safer integration, and it costs the branded flow this ADR
chose. It stays the fallback if Stripe declines to enable platform-collected
requirements for this platform.

## The row is a copy, and holds nothing about the person

`payout_accounts` holds what Stripe last said: the account id, the country, the
transfers capability, whether payouts are enabled, the outstanding
requirements, why the account is restricted and by when, the last four digits
of the bank account, the evidence of the terms being accepted, and when all of
that was copied.

A name, a date of birth, a home address, an ID number, an IBAN and an identity
document all pass through on their way to Stripe and are not kept.
`test_nothing_the_seller_sent_is_kept` sends each of them and then reads the
row back looking for them.

The requirements are stored as Stripe sent them and interpreted on read, in
`PayoutAccount`. Teaching this API a new requirement then applies to every
account at once, rather than to each one the next time it happens to change.

**The status is derived, never stored.** A rejection outranks everything,
because nothing the seller sends changes it. Something due outranks what is
left, because it is the one state the seller can act on. After that the account
either works, meaning it accepts transfers and pays them out to a bank, or
Stripe is still checking it. `PayoutStatus` has the diagram.

**Reading never calls Stripe.** `GET /seller/payout-account` is the stored
copy, so the page loads while Stripe is slow and on a stack with no key at all.
Every write calls Stripe, and its response is drawn from the account Stripe
hands back.

## Stripe's requirements, in this API's words

Stripe says what it needs as dotted paths that vary by country and change over
time. `PayoutField` translates them into a form: three paths are one date of
birth, five are one address, and `external_account` is an IBAN.

```text
due           what to ask for, as PayoutField, in the form's order
unsupported   what Stripe asked for that no field answers, verbatim
errors        what Stripe could not verify, beside the field it is about
```

`unsupported` is the part worth defending. A seller asked for something this
API cannot take is stuck, and naming the requirement is how that gets noticed
and built, rather than discovered by somebody who cannot get paid.

**A refusal goes beside the field that caused it.** Stripe names the parameter
it objected to, such as `individual[dob][year]`, and PayoutField maps it back,
so an IBAN Stripe rejects is a 422 on `iban`. A refusal naming a parameter no
field sends is this application's mistake rather than the seller's. It is
rethrown, and answers 500, rather than being shown to them as theirs to fix.

## The terms are accepted here, and recorded here

With no Stripe-hosted page, accepting Stripe's Connected Account Agreement is
this application's to record (ADR 0015): when, from which address, in which
browser. Opening an account requires it, and Stripe asking again after it
changes its terms appears in `due` as `terms`.

**The address is only as good as the proxy, and it was found not to be.** Next
sets `x-forwarded-for` with `??=`, so a value the browser sends survives the
hop, and `trustProxies('*')` believes it. The address recorded here, and the one
the sign-in and registration limits key on, can be chosen by the client. That
predates this change and belongs to the proxy (ADR 0003), so it is listed below
rather than fixed here.

## Identity documents pass through

The upload's temporary file is streamed to Stripe's Files API, uploaded as the
connected account, attached to the person's verification, and deleted by PHP
when the request ends. Nothing is written to a disk of ours.

`mimes` reads what a file is rather than what it is called, and a test caught
that Laravel's fake uploads do not: they report their type from their name. A
fake `passport.jpg` full of text was a JPEG, and the test passed against a rule
that read nothing. It uses a real file now.

## Webhooks

`POST /webhooks/stripe` is the one route called by something other than a
person using the site. The signature is the credential, checked against the raw
body the proxy streams through.

- **Once.** The event id is the primary key of `stripe_events`, written in the
  same transaction as the event's effect. A failure leaves no record and
  answers 500, so Stripe's retry is acted on. A retry after a success inserts
  nothing and does nothing.
- **Only `account.updated`.** It is how the application learns that Stripe has
  finished checking somebody, or that a verified shop has been asked for more.
- **Fetched again, not read from the event.** Stripe does not promise order,
  and a late event carries an account older than the one already stored.
  Asking for the current account makes the order irrelevant.

## Keeping a person's details out of logs

The first run of the trace test failed. The exception the SDK throws for a
refusal carried, in its trace, the whole parameter array of the call: somebody's
IBAN, one error tracker away from leaving.

`zend.exception_ignore_args` is now on in both images, which drops arguments
from every trace, and `UpdatePayoutDetails` marks the details
`#[SensitiveParameter]` for anywhere that setting is not. The test asserts
against the trace itself, so it holds whichever of the two is doing the work.

## The client refuses to exist wrongly

Without `STRIPE_SECRET`, the first write fails with a message saying where to
find a key. With a key that is not a test key it fails the same way: there is
no live key in this project (ADR 0015), and refusing one here means a key pasted
from the wrong tab cannot open real accounts for anybody.

## Testing it without Stripe

`FakeStripe` is installed as the SDK's HTTP client. The StripeClient, its
services, the objects it builds and the exceptions it throws are all real, and
the tests assert on the parameters that would actually leave, encoded as Stripe
receives them.

The three actions were also run once against
[stripe-mock](https://github.com/stripe/stripe-mock), which validates each
request against Stripe's published spec. Opening, the personal details and the
document passed. The IBAN did not: stripe-mock refuses `external_account` as a
dictionary on an account update, while the SDK's own types for the version it
pins accept an array or a string, and Stripe documents the dictionary form. The
first run against a real test key settles which is right. If it is stripe-mock,
the fix is a bank account token, created first and passed by its id.

---

## Not yet decided

- **A real test key.** Done. A test key is in place, and the account this action
  creates was accepted by Stripe once the platform profile was changed - above.
- **The page.** Built in [ADR 0039](0039-the-payouts-page.md).
- **The forwarded address**, above. It matters to the rate limits as much as to
  this.
- **Companies**, above.
- **Whether an unverified shop may publish** (ADR 0015).
- **British bank accounts.** The form asks for an IBAN, and a GBP account in the
  UK is usually a sort code and an account number. Whether Stripe takes a
  British IBAN for GBP payouts is unverified.
- **Settlement currency.** ADR 0015 expects a charge in the shop's currency to
  transfer without conversion. That depends on the platform holding a balance
  in each currency, which is worth checking against a real account before
  charges are built rather than after.
