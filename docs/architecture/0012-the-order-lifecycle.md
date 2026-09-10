# 0012 - The order lifecycle

Status: accepted - 2026-09-10

An order was placed and nothing could happen to it. This is what happens to it,
who may make each thing happen, and what a cancellation does to the stock
checkout took.

---

## Five states

```text
Pending ──accept──▶ Accepted ──ship──▶ Shipped ──confirm──▶ Completed
   │                    │
   │                    └──seller cancels──┐
   └──either party cancels─────────────────┴──▶ Cancelled
```

Every case is reachable. An enum case nothing can arrive at is a rule that reads
as though it applies when nothing applies it - the same objection ADR 0008 makes
to a policy method with no caller.

## `accepted`, not `processing`

Both were on the table. `accepted` names an event: a seller deciding to fulfil
this, at a moment, with `accepted_at` recording it and `cancelled` as its
natural opposite. `processing` names an activity, has no moment and no opposite,
and is vague about who is doing what.

It is also the column that was proposed for products and refused there
(ADR 0009) on the grounds that it describes an order's life. This is that
order's life.

---

## The two asymmetries

These are the whole of the design, and both are about who is exposed.

**A buyer may cancel only while nobody has committed.** Once a seller has
accepted, they may have set stock aside, bought materials or started work.
Calling it off is no longer the buyer's alone to do - and the seller still can,
right up until it ships, because after acceptance they are the party who would
be let down by it.

**Only the buyer completes.** Completion is the buyer saying they received what
they paid for, and it is what will release a payout. A seller who could complete
their own order could declare their own money releasable, which is the one thing
an escrow marketplace exists to prevent.

That second rule is enforced structurally rather than by a check: there is no
seller completion endpoint, and the buyer's one resolves orders through
`$user->orders()`. A seller asking to complete their own sale is asking for
something they did not buy, and gets a 404.

Nothing cancels a shipped order. That is a return, and returns are disputes,
which are deliberately not built.

> **Reversed by [ADR 0014](0014-completing-an-order.md).** A seller may cancel a
> shipped order, and auto-completion is what forced it: without the escape
> hatch, a shipment that goes missing sits in `shipped` until the clock declares
> it received. Cancelling after shipping does **not** return the stock - the
> goods left the building.

---

## Cancelling gives the stock back

Checkout takes stock at placement (ADR 0011). A cancellation that did not return
it would be inventory quietly deleted from a shop, and this is the first thing
in the application that puts any back.

`CancelOrder` reads the quantities from the **order's own lines** rather than
from anything current, because what was taken is what was agreed and the
catalogue may have moved since. It locks the variants in id order - the same
order checkout takes them in, so a cancellation and a checkout touching the same
variants cannot deadlock against each other - and it locks the order row before
checking the status, so two cancellations racing cannot both pass and both hand
the stock back twice.

A line whose variant has since been deleted returns nothing, because there is
nowhere to return it to. The order still cancels; a seller who removed the
variant has already stopped counting it.

**This does not close the stock gap.** An order nobody cancels still holds its
stock forever, because nothing expires one and there is no payment to fail. What
exists now is a way out that a person can take, not one the system takes.

---

## Out of order is 409, not 403

Shipping something unaccepted, accepting twice, confirming receipt of something
unshipped: all 409.

The caller is a party to the order and entitled to act on it. What is in the way
is where the order has got to, which is the state of the world rather than the
shape of the request or a fact about the caller (ADR 0008).

The body carries the status:

```json
{ "message": "Accept this order before shipping it.", "status": "pending" }
```

so a client can re-render the order without fetching it again - which is usually
how it got here, having drawn a button from state that had since moved.

---

## Two audiences, two resources, and still no policy

ADR 0011 predicted that the seller's view of orders would be "the change that
will bring a policy with it". **Building it showed the prediction was wrong**,
and the reason is worth recording.

The two audiences have separate routes. Buyer routes resolve orders through
`$user->orders()`; seller routes sit behind the `seller` middleware and resolve
through `$seller->orders()`. Neither can name a row belonging to the other side,
so a reference that does answers 404 rather than 403.

What is left after that is not a permission question. "May a buyer cancel an
accepted order" is a **state** question, answered by `OrderStatus` and refused
with a 409. A policy method wrapping it would restate the state machine in a
second place, which is precisely what ADR 0008 warns against.

What would bring a policy back: an endpoint serving both audiences, or platform
staff acting on somebody else's order. Neither exists.

The allowlists differ in two places, and that is why `SellerOrderResource` is a
class rather than a flag on `OrderResource`:

```text
buyer_name           seller sees it; a shop has to know who it is sending to
checkout_reference   buyer sees it; a seller has no business knowing their
                     buyer was shopping elsewhere at that moment
```

The `can_*` fields answer **for the viewer**, so the same order gives the two
sides different answers - `can_cancel` is true for a seller and false for a
buyer once it is accepted. That is the "send the answer, not the inputs" rule
(root `CLAUDE.md` section 4) doing real work: a browser deriving this from
`status` would need a copy of the asymmetry above, and the copy would go stale.

---

## The state machine is in the database

```sql
CHECK (CASE status
    WHEN 'pending'   THEN accepted_at IS NULL AND shipped_at IS NULL AND ...
    WHEN 'shipped'   THEN accepted_at IS NOT NULL AND shipped_at IS NOT NULL AND ...
    ...
    ELSE false
END)
```

Each status says exactly which timestamps must be set and which must not, so the
two cannot disagree: no shipped order without a shipping date, and no date left
behind on something cancelled before it got there.

`ELSE false` fails closed. Adding a status to the enum without teaching this
constraint about it makes every write of that status fail loudly, rather than
silently skipping the check for exactly the state nobody has thought about yet.

Timestamps are set once and not cleared. A completed order still records when it
was accepted and when it shipped.

---

## Not yet decided

- **Auto-completion.** ~~A buyer who never confirms leaves an order shipped
  forever.~~ **Built in [ADR 0014](0014-completing-an-order.md)**: fourteen days
  after posting, with the buyer able to push it back twice.
- **Expiring a pending order.** Still nothing releases the stock of an order
  neither party touches. Cancellation is a way out that a person takes; there is
  no way out the system takes.
- **A reason for cancellation.** A seller cancels without saying why, and the
  buyer is told nothing beyond that it happened. `sellers.rejection_reason` is
  the precedent for how that would look.
- **Notifying anybody.** No email is sent when an order is placed, accepted,
  shipped or cancelled. Every one of those is something a person is waiting to
  hear about.
- **Filtering the seller's queue.** `GET /seller/orders` returns everything,
  newest first. What a seller actually wants is "what needs doing", which is a
  status filter.
- **Disputes and returns.** Explicitly out of scope. They are what would let
  anything move after `shipped`.
