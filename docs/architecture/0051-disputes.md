# 0051 - Disputes

Status: accepted - 2026-09-13

Three ADRs stopped at this table. ADR 0012 said a return "is a dispute"; ADR
0014 said "a buyer whose parcel has not arrived after 28 days needs a dispute,
and disputes are not built"; ADR 0041 wrote down an abuse it could not solve for
the same reason. This is the floor under all of them.

It is also the half that makes escrow mean something. Holding money is only a
promise if there is an answer to "what happens when the two of us disagree".

---

## The window is exactly as wide as the money is held

```text
before shipping   nothing has gone wrong yet, and a buyer can simply cancel
shipped, held     a dispute, and the money can still go either way
settled           a reversal, which this application does not do
```

`Payment::isHeld()` - paid, not refunded, not yet transferred - is already the
single condition on both money actions, and it is the whole of the window here
too. Once a transfer has gone to a shop, pulling it back is a Stripe reversal: a
different object with its own failure modes, which ADR 0041 declined to build
and this does not build either.

So a dispute opens on a **shipped** order whose payment is held, and `Order::canBeDisputed()`
is the one definition. The action enforces it and `OrderResource` publishes it
as `can_dispute`, so the rule the API acts on and the rule the page draws a
button from are the same rule.

## Stopping the clock is the whole mechanism

A shipped order completes on `auto_complete_at` whether or not anything arrived,
and completing it transfers the money to the shop. An order being argued about
must not do that, so `AutoCompleteShippedOrders` skips any order with an open
dispute.

**The deadline itself is left exactly where it is.** Two reasons, and the first
is not negotiable: `orders_auto_complete_at_check` requires a shipped order to
have one, so clearing it is a constraint violation rather than a pause. The
second is that both parties should still see the date it would otherwise have
completed on - a dispute suspends the clock, it does not delete the order's
history.

The exclusion is a subquery from `Dispute`, not a `whereDoesntHave` closure, for
the reason `LeaveReview` gives: inside a relation closure the analyser is handed
a `Builder<Model>` and cannot check a column name against it.

## Two outcomes, because there are two places the money can be

```text
refunded    the order is cancelled, and the payment goes back in full
released    the order is completed, and the payment transfers less the fee
```

**Both reuse the actions that already do exactly that.** Refunding calls
`CancelOrder`, releasing calls `CompleteOrder`, and each carries the money
movement it always carried. There is deliberately no second way to pay anybody:
a dispute moves money down the same path an ordinary confirmation or
cancellation does, so there is one place where a transfer can go wrong.

There is no partial outcome. Partial refunds do not exist here (ADR 0041), and
inventing one for this would be a money path with no other caller.

## The platform becomes a third thing that can end an order

`OrderActor` gains `Staff`, which is what its own docblock said would happen the
day something let the platform move somebody else's order.

**It is a case rather than a reuse of `Deadline`.** A decision a person took is
not a clock running out, and collapsing the two would make "who ended this
order" unanswerable on exactly the orders somebody complained about.

The asymmetry survives. `completed_by` accepts `staff` and still refuses
`seller`: a dispute decided for a shop is the platform releasing the money, not
the shop releasing its own, which is the one thing ADR 0012 exists to prevent.

**A staff cancellation writes no `cancellation_reason`.**
`orders_cancellation_reason_check` allows one only from a seller, and the
dispute already carries the platform's note - which both parties are shown.
Storing it twice would be two records of one decision, and two is where they
disagree.

## One per order, and no way to withdraw one

`disputes.order_id` is unique. Deciding a dispute ends the order - refunded
cancels it, released completes it, and both are final - so a second one is not a
state this domain can reach, and the index says so rather than leaving it to the
code that happens to write them.

**Nothing withdraws one.** Money is held while it is open, and letting the
person who opened it also close it would make "resolved" mean two different
things: one where the platform decided, and one where somebody changed their
mind. A buyer who no longer wants it decided can say so on the order, where the
conversation already lives (ADR 0050), and the platform releases it.

## The buyer opens it; the platform decides

There is no `opened_by` column. Only a buyer can open one, so a column recording
who did would never vary - the same "a case nothing can arrive at" problem
`OrderStatus` warns about.

Being a party to the order is the whole permission on the buyer's side, so there
is no policy there: the order is resolved through `$user->orders()` and somebody
else's reference is a 404.

`DisputePolicy` covers the platform's side, and refuses **a member of staff who
is a party to the order** as well as anybody who is not staff. It is the same
guard `SellerPolicy::review` applies to an application, for a sharper reason: a
person deciding where their own money goes is not deciding anything.

`resolved_by` is recorded and is **not published**. Who decided is the
platform's answer rather than an individual's, and naming a member of staff on a
decision about somebody's money invites the complaint to follow them personally.

## What each side sees

```text
the buyer     their reason, the decision and its note, and can_dispute
the shop      the same dispute, reason and note included
the platform  a queue of what is open, oldest first
```

The parties read it nested on an order that already says what it is and what it
cost. The queue has no order around it, so `StaffDisputeResource` adds the
reference, the two names and the amount - enough to decide with, and not the
delivery address, which answers nothing about whether something arrived.

Both sides see the buyer's reason and the platform's note. A complaint the other
party cannot read is one they cannot answer, and a decision only one side can
read is how a marketplace ends up arguing with itself about what was said.

## Testing

PHPUnit: a buyer disputes a shipped order and the shop is told, with the reason
in the mail; an order that has not shipped cannot be disputed, and neither can
one whose money has settled; a second dispute is refused; somebody else's order
is a 404; **an open dispute stops auto-completion**, which is the mechanism the
whole chapter rests on; staff resolve it either way and the order ends
accordingly, with `completed_by` recording `staff`; resolving one twice is
refused; a caller who is not staff cannot read the queue or decide one; a member
of staff who is a party to the order cannot decide it; and both order resources
publish the dispute while only the buyer's publishes `can_dispute`.

Two of those were found by the analyser rather than by a test, and both would
have been crashes. Adding `OrderActor::Staff` left the `match` expressions in
`OrderCompleted` and `OrderCancelled` incomplete, so releasing a dispute to a
shop would have thrown while sending the mail; the same addition broke the
frontend's exhaustive switches in `order-timeline`, which is the property
publishing enums rather than bare strings exists to give.

Vitest covers what `DisputePanel` promises: it draws nothing when there is no
dispute and no window to raise one; it offers the form only when the API says
it can be raised; it asks before sending anything and says the dispute cannot
be taken back; it sends the reason and puts a 422 beside the field; a lapsed
session goes to sign in and back to the order; an open one says the money is
held; and the same decision is told to each side in its own words, keyed by the
resolution so a third outcome would be a type error rather than a blank space.

Playwright proves the whole loop across three sessions. The buyer raises one on
a shipped order and reads that the payment is held; a member of staff finds it
in the queue with both names and the amount, decides it, and it leaves the
queue; the buyer then reads the decision and the reasoning on their own order.
An order nobody has sent offers no way to dispute it at all, which is the window
asserted where a person would actually meet it. The queue redirects a signed-out
visitor, explains itself to anybody who is not staff, and offers them no link to
it.

---

## Not yet decided

- **The seller-side abuse ADR 0041 named.** A seller who cancels a shipped order
  that did arrive still refunds a buyer who keeps the goods. Closing it means
  either gating an honest seller's escape hatch behind a process - which
  punishes the common case to catch the rare one - or clawing a refund back,
  which is a reversal. Neither is done here.
- **Disputes after completion.** The money has gone, so unwinding one needs
  reversals. The window above is the honest extent of what this can do.
- **Partial outcomes.** Split decisions - "keep it, have half back" - are the
  obvious next ask, and need partial refunds first.
- **Evidence.** Nothing attaches a photograph of a damaged parcel. It is the
  same object-storage question ADR 0050 lists for messages, and would arrive
  with it.
- **A history of decisions.** The queue shows what is open, so a resolved
  dispute is only visible on its order. Staff have no way to look back over what
  the platform has decided.
- **Telling anybody it is coming.** Nothing warns a shop that a payout is about
  to be held, and nothing chases a dispute nobody has decided.
- **Mail is noisier than it should be.** Releasing to the shop sends both a
  resolution and the ordinary completion notice, because `CompleteOrder` sends
  its own. Quieting that needs the same debouncing ADR 0050 already wants.
