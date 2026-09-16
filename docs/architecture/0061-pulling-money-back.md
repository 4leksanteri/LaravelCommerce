# 0061 - Pulling money back

Status: accepted - 2026-09-16

ADR 0041 wrote this bound down and named what it would take to lift:

> **Money that has been transferred is not pulled back.** ... Undoing a transfer
> is a reversal, which is a different Stripe object with its own failure modes,
> and nothing here does one.

Four open items across three ADRs have been waiting on it. ADR 0041's own
"Reversals"; ADR 0051's "the seller-side abuse ADR 0041 named" and "disputes
after completion"; ADR 0059's "appealing a dispute". It is also what partial
refunds (ADR 0015) would be built on.

This is the reversal.

---

## It is possible because of a decision taken a year of ADRs ago

ADR 0015 chose **separate charges and transfers**, and said why: a direct charge
lands in the seller's balance at the moment of payment, and "that is not escrow,
it is a payment". Because the money is transferred rather than routed, there is
a Transfer object to reverse.

Had that gone the other way, this ADR could not exist.

## A reversal is recorded, never an undo

The obvious implementation clears `transferred_at` and pretends the transfer
never happened. It is wrong twice over.

`payments_transfer_is_whole` ties `transferred_at`, `stripe_transfer_id` and
`platform_fee_minor` together as equivalences, so clearing one means clearing
all three - including the record of what the marketplace kept. And ADR 0060 has
just finished making the case that a sanction which erases itself leaves a shop
looking like one nothing ever happened to.

So a reversal is a second event beside the first:

```text
transferred_at, stripe_transfer_id, platform_fee_minor   untouched
reversed_at, stripe_transfer_reversal_id                 written beside them
```

The transfer did happen. The fee was taken. Both remain true, and the money
coming back is a third fact rather than a correction of the first two - which is
exactly the reasoning ADR 0041 gave for a refund not being a `PaymentStatus`
case.

## `payments_not_both_ways` is narrowed, not dropped

It said an order is completed or cancelled and never both, and that is still
true of the lifecycle. What it also forbade, unintentionally, was the only
sequence that can return money a shop already holds:

```sql
CHECK (transferred_at IS NULL OR refunded_at IS NULL OR reversed_at IS NOT NULL)
```

Both timestamps may now coexist, and only when a reversal explains why. A
payment that is transferred and refunded with nothing in between is still
refused - that would be a shop paid and a buyer made whole for the same order,
with nothing to say where the money came from.

## The order stays completed

A dispute decided for the buyer used to cancel the order. After completion it
cannot: `orders_timeline_check` refuses `cancelled` while `completed_at` is set,
and the way round would be to clear `completed_at` - erasing that the buyer
confirmed receipt, which is the erasure this codebase keeps deciding against.

The alternative was a sixth `OrderStatus`. `OrderStatus`'s own docblock warns
that every case must be reachable, and ADR 0051 records what happened when
`OrderActor` gained one: it "broke the frontend's exhaustive switches in
`order-timeline`, which is the property publishing enums rather than bare
strings exists to give". A sixth status would ripple through every switch on
both sides to describe something the payment already describes.

So the order completed, and a later decision moved the money back. Both are
true, both are recorded, and neither has to pretend to be the other.

## Two outcomes became four

`ResolveDispute` used to branch on the resolution alone. Where the money already
is now matters as much:

```text
             money still held          money already at the shop
refunded     cancel, then refund       reverse, then refund; the order stays completed
released     complete, then transfer   nothing to do
```

**Released on an order already paid for is a no-op, and needed naming.** There
is no money to move, and falling through to `CompleteOrder` would have thrown on
an order that is already complete. The decision is still recorded on the shop's
record (ADR 0060) and both sides are still told.

**The pair is two calls, not a new action.** `ReverseTransfer` then
`RefundPayment`, both idempotent, both refusing quietly. Wrapping them would
have been the second way to pay somebody that ADR 0041 refuses to have - and
keeping them separate is what lets `payments:settle` finish a pair interrupted
between them.

## Three questions that used to be one

`Payment::isHeld()` answered all of them while money could only move one way:

```text
isHeld()          paid, not transferred, not refunded - the escrow position
canBeRefunded()   paid, not refunded, and either never transferred or since reversed
canComeBack()     paid and not refunded - is there anything a decision could move
```

`isHeld()` is deliberately unchanged. It gates what stops an account closing
(ADR 0058) and it is the escrow question every page asks; a reversed payment is
**not** held, because the money is owed to the buyer rather than waiting on an
outcome. `canBeRefunded()` is what the refund asks, and is wider by exactly one
case. `canComeBack()` is what the dispute window asks, and is wider again -
money at the shop can still come back, it just takes two steps.

## The window, and why it has a far edge

A dispute existed only while the money was held, because that was as far as a
decision could reach. It now reaches past completion, for
`orders.dispute_after_completion_days` - thirty.

**Bounded, deliberately.** Money that can be taken back at any time is money a
shop can never treat as its own, which costs honest sellers more than an
unbounded window would ever catch. Thirty days is long enough to open a parcel
that sat in a hallway and short enough that a season's earnings settle.

**Measured from completion rather than dispatch**, because completion is the
moment the buyer said it arrived - or the deadline said so for them. A parcel
confirmed early and opened late is the case this exists for, and measuring from
dispatch would give the least time to the buyer who waited longest.

It is product design rather than deployment tuning, so it sits in
`config/orders.php` without an environment variable, beside the two extension
values that made the same argument.

## The defect this found in `payments:settle`

The settle command is the safety net under every money call that is allowed to
fail quietly. It had two bugs the moment a reversal existed, and neither would
have announced itself:

- **It could not see an interrupted pair.** Its query looked for payments with
  `transferred_at IS NULL`, and a reversal leaves that set on purpose. A
  reversal that succeeded followed by a refund that failed would have left the
  buyer's money on the platform indefinitely, with the net that exists to catch
  exactly that looking straight past it.
- **It decided what to do from the order's status.** A post-completion dispute
  leaves the order `completed`, which the old reading meant "transfer it" - so
  the recovery path would have sent a second payment to a shop that was being
  asked to give the first one back.

Both are fixed by asking the dispute instead: an order whose dispute was decided
`refunded` and whose payment has not been refunded is money owed back, whichever
half of the pair did not happen.

## Testing

PHPUnit: the reversal sends no amount and carries an idempotency key of the
order's own, because reversing twice would debit a shop money it never received;
**the transfer, its id and the fee all survive it**; a dispute decided for the
buyer after completion reverses and refunds and leaves the order completed; one
decided for the shop after completion moves nothing at all; the window admits a
settled order inside thirty days and refuses one outside, on the action and on
the buyer's page alike; and `payments:settle` finishes an interrupted pair from
either half **without sending a second transfer**.

The test that used to assert the old bound -
`test_an_order_whose_money_has_settled_cannot_be_disputed` - was rewritten
rather than deleted. What it guards now is that the window still has a far edge.

---

## Not yet decided

- **Partial refunds and partial outcomes.** ADR 0015 and ADR 0051 both want
  them, and a full reversal is the wrong primitive: "keep it, have half back"
  needs a partial reversal and a partial refund together. This builds the whole
  one because there is no partial cancellation to need the other.
- **Appealing a dispute.** ADR 0059 left it open because reversing a decision
  needed a reversal. The blocker is gone; the appeal endpoint for a dispute is
  still not written, and deciding one twice needs its own rules about who may.
- **A `transfer.reversed` webhook.** ADR 0041 already lists `transfer.created`
  and `charge.refunded` as unhandled, and this adds a third. A reversal that
  failed asynchronously at Stripe would not be noticed here.
- **A connected account without the funds.** Stripe refuses a reversal it cannot
  cover, and this treats that like any other refusal - quietly, and retried by
  `payments:settle` forever. Nothing escalates it to a person, and a shop that
  has withdrawn its balance is exactly the case where somebody should be told.
- **Telling the shop its money went back.** The dispute's own resolution mail
  says what was decided, which is the substantive news, but no notification
  mentions the reversal itself - the same gap ADR 0041 records for refunds.
- **The buyer's page says nothing about the reversal**, deliberately: what a
  buyer is owed an answer about is `refunded_at`. If that ever proves too quiet,
  the field is there to publish.
