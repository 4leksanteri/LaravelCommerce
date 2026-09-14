# 0052 - Suspending a shop

Status: accepted - 2026-09-13

ADR 0051 gave the platform its first power to intervene: staff can end an order
and decide where held money goes. Nothing followed from the pattern of those
decisions, though. A shop could lose ten disputes and keep trading exactly as
before, because there was no way to stop one.

`SellerStatus` had already named the condition for fixing that: "there is
deliberately no Suspended case yet - suspending a trading shop raises questions
about open orders and pending payouts that have no answer until those exist."
Orders, payouts and disputes all exist now, so this is the answer.

---

## One case does all of it

```text
Pending ──approve──▶ Approved ──suspend────▶ Suspended
   │                     ▲                       │
   │                     └─────reinstate─────────┘
   └────reject────▶ Rejected ──resubmit──▶ Pending
```

**Nothing below is written anywhere as a suspension rule.**
`Seller::scopePublic()` asks for `status = approved` rather than for "not
rejected", so a fourth case removes:

```text
the shop's page          Seller::scopePublic()
its listings             Product::scopePublic(), through the same scope
search and browse        the same scope again
its photographs          ProductImage, through Product::isPublic()
publishing anything      PublishProduct, which asks seller->isPublic()
opening a payout account OpenPayoutAccount, which asks the same
```

That is what the enum's own docblock means when it says there is no second
`is_public` flag to fall out of step. The alternative - a `suspended_at` column
that every public query had to remember to check - is the shape this codebase
keeps refusing, and `scopePublic` exists precisely so the day somebody forgets
cannot happen.

The test for it asserts the storefront, the listing and search all go, rather
than asserting the column changed. A design that claims to get six things right
for free should be asked for all six.

## It stops new trade, and touches nothing already agreed

This is the question the enum was waiting on, and the answer is narrower than
"the shop is closed".

```text
stopped     the storefront, publishing, opening a payout account
untouched   open orders, money held against them, payouts already due
```

**A suspended shop still owes what it has already sold.** Its orders are not
cancelled, the `seller` middleware still admits it, and it can accept, ship and
be paid for them exactly as before - because a buyer whose money is held has to
be able to receive their parcel, confirm it arrived, or dispute it. Cancelling
a suspended shop's open orders would refund buyers who are about to receive
goods, and strand the ones already in the post.

Saying so is part of the feature rather than a footnote: the suspension mail and
the shop's own page both state it, because a seller who assumed the orders were
void would stop posting parcels people have paid for, and turn one suspension
into a row of disputes.

## The way out that had to be closed

`ApplyToSell` resubmits a rejected application by setting the status back to
pending and clearing the decision. A suspended shop is **not public**, so
without a guard it fell straight through that path: a shop the platform had
stopped could put itself back in the queue and be approved by somebody who never
saw the suspension.

The guard goes **before** the `isPublic()` check, and the order is the whole
point - a check written after it would never be reached. `ShopApplicationBlocker`
gains a `Suspended` case for the same reason: returning null would have offered
the application form as the way out.

There is no way back through the queue. The platform stopped it, and the
platform lifts it.

## Reinstating is its own action

`ApproveSeller` refuses anything already reviewed, and a suspended shop is
reviewed - it was approved before it was stopped. Routing a reinstatement
through it would have meant loosening that guard, which exists to stop two
reviewers both recording a decision.

It also says something true: this shop is not an application being decided for
the first time.

## What is recorded, and what is published

```text
suspension_reason   required, and shown to the shop and to staff
suspended_at        the date, tied to the status by constraint
suspended_by        recorded, and deliberately not published
```

**Its own reason column rather than `rejection_reason`.** They are different
events at different points in a shop's life, `sellers_rejection_reason_check` is
written about a rejection, and reusing it would have made `SellerResource`'s
comment that the column is "only ever set on a rejection" untrue.

**`suspended_by` is recorded because stopping somebody's business is the
decision here most worth being able to account for later**, and not published
for the reason a dispute's `resolved_by` is not: the decision is the platform's
rather than an individual's, and naming a member of staff on it invites the
complaint to follow them personally. It cannot live in `reviewed_by` either -
that is the original approval, and overwriting it would lose the fact that the
shop was ever approved at all.

`sellers_suspension_is_whole` ties all three to the status as equivalences in
both directions, so a suspended shop with no date, a date on a trading shop, and
a reason with nothing to explain are each refused by the database.

## Its own policy question, and its own refusal

`SellerPolicy::suspend()` applies the same two conditions `review()` does -
staff, and never your own shop - and is deliberately a separate method. They
answer different questions, one about an application and one about a business
already running, and they will stop agreeing the day staff stop being one
undifferentiated group, which ADR 0037 already lists as open.

`ShopSuspensionNotAllowedException` is not `SellerAlreadyReviewedException`,
which means something else: another reviewer decided first. A suspension can
happen long after a shop was reviewed, and the two refusals are about different
things. It carries `status` so the queue can redraw without fetching again.

## Testing

PHPUnit: staff suspend a trading shop and it is told why; **the shop, its
listing and its search results all leave the storefront**, which is the claim
the whole design rests on; a suspended shop cannot publish anything, and what it
had published is not unpublished - it is simply not public, because its shop is
not; it can still read its orders and its own shop page, because it still owes
what it sold; **it cannot apply again**, and the blocker says why; reinstating
puts the listing back on sale without anything touching it; only a trading shop
can be suspended and only a suspended one reinstated, each a 409 carrying the
status; a shopper cannot suspend anything; staff cannot suspend their own shop;
and a reason is required with a floor on its length.

---

## Not yet decided

- **A history of suspensions.** The columns are tied to the status, so lifting
  one clears it: there is no record that a shop was ever suspended, which is
  exactly what somebody deciding whether to suspend it again would want.
- **Appeals.** A shop reads why it was stopped and can reply to nothing. The
  conversation machinery exists (ADR 0050) but is scoped to an order.
- **Anything short of stopping the whole shop.** Written in
  [ADR 0054](0054-moderation.md), which is the chapter this named. A listing and
  a review can each be taken down on their own, so closing the business is no
  longer the only tool against one bad listing. A message still cannot be
  removed, and that is now a decision rather than a gap: an order's conversation
  is dispute evidence, so a message that could be removed is evidence that could
  be removed by the party it incriminates.
- **Payouts in flight.** A suspension does not touch money already owed, which
  is deliberate; whether it should ever hold a payout is a different question
  and needs an answer about who is owed what.
- **Suspending with a deadline.** Every suspension here is indefinite until
  somebody lifts it. "Stopped for a week" has no representation.
