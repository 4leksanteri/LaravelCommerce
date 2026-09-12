# 0041 - Moving the money

Status: accepted - 2026-09-12

ADR 0040 took a payment and held it. This is where it goes: out to the shop when
the buyer confirms the parcel arrived, or back to the buyer when the order is
called off. It is the half that makes the escrow claim on every page true.

---

## Completion releases it, and nothing else does

```text
completed    transfer to the connected account, less the platform fee
cancelled    refund in full
```

Not acceptance, not shipping, and nothing a seller can do alone. `CompleteOrder`
is reachable only through the buyer's own routes, and the deadline acting on
their behalf (ADR 0014) - which is the structural reason a shop cannot release
its own money, restated here because this is the change that gives it teeth.

## The fee is taken here, and recorded

`STRIPE_PLATFORM_FEE_BPS` is 500 basis points. The transfer is the order total
less that, and what was kept is written onto the payment at the moment it was
taken - a fact about that transfer, rather than something to derive later from a
rate that may have changed since.

The arithmetic is integer throughout: basis points multiplied before dividing,
with the remainder dropped rather than rounded. The drop favours the shop, which
is the right direction for a fraction of a cent nobody can pay (ADR 0004).

## Both refuse quietly, and `payments:settle` is the other half of that bargain

Four things stop a transfer: the order was never paid, it has already been sent,
the money was refunded, or the shop's Stripe account cannot receive anything
yet. Two stop a refund: nothing was paid, or it has already gone back.

**None of them is an error**, and none of them fails the thing that called it.
The order is completed or cancelled and committed before the money is touched,
so a Stripe outage must not turn a buyer's confirmation into a 500 - it would
read as "nothing happened", which is the one thing that is not true.

The cost of that choice is money sitting on the platform that should have moved.
`payments:settle` collects it: bounded, locked, idempotent, non-zero exit only
when something actually failed (ADR 0013). A shop that cannot be paid yet is
counted as **waiting** rather than failed, because saying red every run would
hide the run that matters.

It is also the only way a shop that finished its Stripe verification _after_
making a sale ever gets paid for it. Nothing about the order needs to change;
the transfer simply could not run at the time.

## A refund is in full, including one for goods that have left

There is no partial cancellation in this application, so there is no partial
refund to describe. The amount is not even sent: Stripe refunds an intent in
full when none is given, which is one fewer figure this application can get
wrong.

**A shipped order is refunded too.** ADR 0012 let a seller cancel after
shipping, as the escape hatch for a parcel that never arrives, and once money is
involved that is a refund for goods that are in a van or on a doorstep. The
buyer is made whole because the shop chose to call it off, and the shop carries
the loss it chose. Stock is not returned, which was already the rule.

**The abuse is real and is not solved here.** A seller could cancel an order
that did arrive, and the buyer would keep the goods and the money. That is a
dispute, and there are no disputes - so it is written down rather than papered
over. What exists instead is the record of who cancelled and why (ADR 0035),
which is what a dispute would be built on.

## Money that has been transferred is not pulled back

A transfer and a refund are mutually exclusive, by database constraint. An order
is completed or cancelled and never both, so this cannot arise from the
lifecycle - the constraint is there for the code that has not been written yet,
not for the code that has.

Undoing a transfer is a reversal, which is a different Stripe object with its
own failure modes, and nothing here does one.

## A refund is not a status

Stripe leaves the PaymentIntent `succeeded` and records a separate Refund
against it, because the charge did succeed: returning the money is a second
event rather than a correction of the first. `payments` mirrors Stripe
(ADR 0031), so it says the same - `status` stays `succeeded` and `refunded_at`
says it came back.

The alternative, a `Refunded` case on `PaymentStatus`, would have made the
constraint tying `paid_at` to a succeeded status false, and would have had this
application disagreeing with Stripe about what happened.

## Testing

PHPUnit, against `FakeStripe`: a completed order transfers the total less the
fee to the shop's connected account, with an idempotency key derived from the
order; a shop whose account is not active is left alone and nothing is sent; a
cancelled order is refunded in full; a shipped cancellation is refunded as well;
neither runs twice; and `payments:settle` picks up both kinds and counts what it
could not do yet as waiting rather than failed.

---

## Not yet decided

- **The surfaces.** Nothing shows any of this: the buyer's order page does not
  say refunded, the shop's queue does not say paid, and the payouts page lists
  no transfers. That is the next change, and it is the last part of payments.
- **Disputes**, above. The platform carries them (ADR 0015), and there is no
  state, screen or notification for one.
- **Reversals**, above.
- **Partial refunds.** No partial cancellation exists to need one.
- **Telling anybody.** A refund sends no mail of its own; the cancellation it
  came from does (ADR 0035), and it does not mention money.
- **`transfer.created` and `charge.refunded` webhooks.** Both writes record
  what they did, so nothing is missed today. A refund that failed
  asynchronously at Stripe would not be noticed.
