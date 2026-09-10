# 0014 - Completing an order

Status: accepted - 2026-09-10

How an order ends when nobody says anything, and what a seller can do about a
parcel that never arrives.

This amends ADR 0012 in one place and builds on ADR 0013 in another.

---

## A seller may now cancel a shipped order

ADR 0012 said: _"Nothing cancels a shipped order. That is a return, and it is a
dispute."_ **That was wrong, and auto-completion is what makes it wrong.**

Without it, a shipment that goes missing has nowhere to go. The buyer cannot
complete it - they received nothing - and the seller cannot cancel it, so the
order sits in `shipped` until the clock below declares it received. The rule
that was meant to keep returns out of the system instead guaranteed a false
completion.

So the seller can cancel it, and it is the one party who can: a buyer claiming
non-delivery to get out of an order is the case a dispute process exists for,
and there is none.

```text
Pending ──accept──▶ Accepted ──ship──▶ Shipped ──confirm──▶ Completed
   │                    │                  │        or the deadline passes
   │                    └──seller──────────┤
   └──either party cancels─────────────────┴──▶ Cancelled
```

## Cancelling after shipping does not return the stock

This is the part that would have been a defect if it had been missed.

Cancelling before shipping puts the units back, because they never left.
Cancelling afterwards must not: they are in a van or on somebody's doorstep, and
a shop that has posted something does not still have it. Returning them to
`stock` would sell them a second time.

```php
if (! $order->status->hasShipped()) {
    $this->returnStock($order);
}
```

If the goods do come back, the seller restocks the variant themselves. That is a
real event with a real date, and inferring it here would be the inventory
equivalent of assuming delivery.

---

## A shipped order completes on a deadline

Buyers forget. An order that stays `shipped` forever never releases a payout, so
something has to close it - and the only honest candidate is a clock, because
the alternative is letting sellers complete their own sales, which is the one
thing ADR 0012 refuses.

`orders:auto-complete` completes shipped orders whose `auto_complete_at` has
passed. Fourteen days after posting, by default.

**The date is stored on the order, not computed from `shipped_at` plus a
window.** Two reasons, and both matter:

- It moves when the buyer says their parcel is late, and a run that recomputed
  it would ignore every extension ever granted.
- Both parties can see it. "Completes automatically on the 24th" is a thing to
  tell somebody; a window in a config file is not.

`CHECK ((shipped_at IS NULL) = (auto_complete_at IS NULL))` - an order has a
completion deadline exactly when it has shipped.

---

## The buyer can push it back, twice

**Auto-completion is not safe without this.** A courier being a week late is
ordinary. A marketplace concluding from that week that a parcel arrived is not -
and once payments exist, that conclusion releases money for something nobody
received.

```text
POST /api/v1/orders/{reference}/completion-extension
```

Seven days each time, twice - so 28 days from posting before the marketplace
assumes delivery.

**It is deliberately not a dispute.** The buyer is not claiming anything went
wrong, only that it has not gone right yet, and the cheapest honest answer to
that is more time. Making them open a case to say "the post is slow" would be a
process where a date change will do.

Two details that are easy to get wrong:

**Extensions are added to the deadline, not to today.** Pushing from today would
mean a buyer who asks early gets less time than one who leaves it to the last
moment, which trains people to leave it to the last moment.

**The cap is real, and past it there is nothing.** A buyer whose parcel has not
arrived after 28 days needs a dispute, and disputes are not built. The message
says what will happen rather than offering a process that does not exist.

Only the buyer may extend. It is the seller's payout being held up, so the
seller must not be able to hold it up further - and the endpoint resolves
through `$user->orders()`, so a seller asking about their own sale is asking
about something they did not buy, and gets a 404.

---

## The second scheduled command, and the contract made structural

`orders:auto-complete` follows ADR 0013 exactly as `orders:expire` does. With
two of them, the shared part stopped being a description and became a trait:

```php
$this->exclusively('orders:auto-complete', function (): int { ... });
```

`RunsExclusively` takes the cache lock, refuses to run twice at once, exits
**zero** when the lock is held - overlapping is expected under at-least-once
delivery - and releases it in a `finally`. A third scheduled command gets those
properties by using the trait rather than by remembering them.

The two commands take different lock keys, so one running never holds up the
other. There is a test that asserts exactly that, because a shared key would be
an easy and silent mistake.

---

## What still has no answer

**Nobody is told any of this.** An order cancelled by its seller after shipping,
one expired by `orders:expire`, and one completed by `orders:auto-complete` all
reach their buyer as a status change with no explanation and no email. The
platform now ends orders on its own in two different ways and says nothing about
either.

There is a cluster of questions here that should be answered together rather
than piecemeal, and this ADR deliberately answers none of them:

```text
who cancelled it        buyer, seller, or the clock
why                     a reason, where a seller cancelled
who completed it        the buyer, or the clock on their behalf
telling anybody         no mail is sent for any order event
```

Each has been deferred once already (ADR 0012, ADR 0013). Doing them one at a
time as each new automatic action arrives would produce four half-columns; they
are one change about attribution and notification.

**A dispute is still the missing floor under all of it.** Cancelling a shipped
order, extending past the cap and unwinding a completed order are all the same
question - what happens when the two parties disagree about what arrived - and
nothing answers it.
