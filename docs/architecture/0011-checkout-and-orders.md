# 0011 - Checkout and orders

Status: accepted - 2026-09-10

Where the catalogue stops being the source of truth.

ADR 0010 settled that a cart quotes no price of its own and reads everything
from the variant every time it is shown. This is the other end of that
sentence: **an order is the snapshot, and it never reads the catalogue again.**

---

## One order per shop

A basket spanning three shops becomes three orders.

That is not a convenience. Each is an agreement with a different seller,
denominated in that seller's currency (ADR 0004, ADR 0007), and each will be
settled by a different payment and paid out to a different account. A single
order row spanning two shops would have to hold two currencies and two
counterparties, and there is no honest way to total it.

The cart already made this visible by grouping and refusing a grand total. This
is the same shape, persisted.

---

## Checkout takes no request body

There is nothing to send. The cart is on the server, the prices are on the
server, and the total is summed from what the catalogue says under lock.

**Nothing a client sends contributes a figure to what somebody is charged**, and
there is no field it could send that would. That is the strongest form of "the
API decides" (root `CLAUDE.md` section 4) available here, and it is free.

---

## All or nothing

One unavailable line refuses the whole checkout. Orders are not placed for the
shops that happened to be fine.

The buyer pressed one button, under one basket and one set of subtotals.
Quietly buying part of it and leaving the rest behind means they have to work
out which part went through, from a response they were not expecting. Refusing
is the answer they can act on: fix the line, press it again.

The refusal is **409** (ADR 0008). The caller is entitled to check out and sent
nothing invalid - a cart is not a payload - and what is in the way is the state
of the catalogue. It carries the lines that blocked it:

```json
{
  "message": "Some of these can no longer be bought. Nothing has been ordered.",
  "items": [
    {
      "id": 12,
      "product_name": "Rye Sourdough",
      "variant_name": "Large",
      "availability": "insufficient_stock",
      "available": 2
    }
  ]
}
```

so a frontend can mark them in place rather than showing "something went wrong"
over a cart of nine things.

---

## The order of operations, and why it is that order

Everything below happens in one transaction.

```text
1  lock the cart            a second checkout waits, then finds it empty
2  lock every variant       in id order
3  revalidate every line    against the stock just locked
4  write one order per shop snapshotting what was agreed
5  take the stock
6  empty the cart
```

**The cart is emptied last, and inside the transaction.** That is what "clear
the cart only after successful order creation" has to mean to be worth
anything: a failure at any step leaves the cart exactly as it was, with no stock
taken and no order half-written. Emptying it in a second request, or after the
transaction commits, is a window in which somebody loses their basket and gets
nothing.

**Variants are locked in id order.** Two shoppers checking out baskets that
share two variants would otherwise be able to take them in opposite orders and
deadlock. Taking them in the same order means the second simply waits.

**The cart lock is what makes a double checkout safe.** A second request on the
same cart blocks at step 1, and when it proceeds the cart is empty - so it gets
a 409 rather than a duplicate set of orders. There is no idempotency key, and
this is the reason one is not needed for the double-submit case.

---

## Revalidation reuses the cart's own definition

The stock read when the lines were loaded is replaced by the stock read under
the lock, and then `CartItem::availability()` answers - the same method the cart
page uses.

Re-deriving "can this be bought" inside checkout would be a second definition of
it, and the two would drift. This is the same reasoning as
`Product::scopePublic()` being the only definition of what the storefront shows.

---

## Stock is taken at placement, not at payment

This closes the question ADR 0009 and ADR 0010 both left open.

`PlaceOrders` decrements `product_variants.stock` inside the checkout
transaction, behind the row lock, with the `stock >= 0` CHECK constraint as the
last line of defence. Two people cannot both order the last one.

The alternative - decrementing when a payment succeeds - leaves the window
between "order placed" and "payment cleared" unprotected, which is exactly the
window an oversell happens in. Placement is the moment the buyer was told they
had it.

**The cost is real and is not paid yet.** There are no payments, so an order sits
`pending` forever, and the stock it holds is held forever. Nothing expires an
unpaid order and nothing releases its stock; today the only way back is a data
fix. Cancellation and expiry arrive with payments, and they are the first thing
that has to arrive with them.

---

## What is snapshotted, and what is only a link

```text
order_items   product_name, variant_name, unit_price_minor, quantity
orders        currency, total_minor
```

Every one of those is written once and never read from the catalogue again. A
seller may afterwards rename the product, move the price or delete the listing,
and not one figure on a receipt changes.

The names are taken **at checkout**, not from what the cart remembered from when
the item was added. The cart shows the current name too - that is what "the
catalogue is the source of truth until checkout" means for a name as much as for
a price - so what is frozen is what the buyer was looking at when they pressed
the button.

`order_items.product_variant_id` is nullable and nulled on delete. It is a link
and not a source: it exists so a buyer can be offered "order this again", and
its absence costs a link rather than a fact. A seller tidying their catalogue
must not reach into somebody's order history.

There are no structured variant options - a variant has a name - so
`variant_name` is one column rather than a JSON blob nothing would put anything
in. If options ever become structured, they are snapshotted the same way.

## `currency` on an order, when products deliberately have none

ADR 0009 refused a `currency` column on products, because a product belongs to
one shop and the column would be a second copy that could disagree.

An order gets one anyway, and the difference is what the row is for. A product
is a live view of what a shop sells and should read the shop's current answer. An
order records what was agreed, and must not depend on the shop's currency
staying what it was - it is fixed today, and "today" is not a guarantee a
receipt should rest on.

## `total_minor` is stored; a line total is not

The order's total is the figure a payment will be made against. It is stored so
that it cannot move if line arithmetic ever changes, and `CheckoutTest` asserts
it equals the sum of its lines - a stored total that has drifted from what it
totals is the sort of defect nothing else would notice.

A line total is exactly `unit_price_minor * quantity` and is **not** stored. A
stored copy would be a third number that can disagree with the two it came from.

It is called `total` and not `subtotal` because it is what the buyer owes. It
equals the sum of the lines only because there is no shipping and no tax; when
those arrive they become their own columns and this one includes them, without
the name having to change.

---

## An order is addressed by reference

```text
GET /api/v1/orders/K7M2QXV9RT
```

A sequential id in a URL publishes how many orders the marketplace has taken,
and invites somebody to walk it - refused, but asked. A reference is also what a
person actually has: it is on their confirmation, and it is what they quote in
an email about a problem.

The alphabet excludes I, L, O, U, 0 and 1. A reference gets read down a
telephone and typed back in, and those are the characters that come back wrong.

This is the sort of column that is painful to add later, because every order
already placed needs one backfilled - the same argument ADR 0009 makes about
moving a price after orders reference it.

---

## `pending`, and why an order gets a status column when a cart did not

ADR 0010 argued against a status on the cart, so a single-case enum here needs
an answer.

A cart's status would have been **derived**: "converted" is a restatement of "an
order exists", and a column that restates a fact recorded elsewhere is a column
that can disagree with it. `pending` is not derived from anything. It is the
only record in the system that an order has not been paid for, and leaving it
out would mean every order silently claiming to be settled.

It is also read rather than stored and forgotten - `OrderResource` publishes it,
so a buyer is told their order is awaiting payment rather than being shown a
list of purchases that may or may not have gone through.

`paid`, `shipped` and `cancelled` arrive with payments, each with the timestamps
and transition rules that make it mean something. None of them is written down
in advance.

---

## Buyer-side only, and no policy yet

`GET /orders` and `GET /orders/{reference}` resolve through `$user->orders()`,
so another person's order is never in the query. Somebody else's reference
answers **404** rather than 403 - a 403 confirms it names a real order, which is
the thing the reference is meant to keep quiet about.

There is therefore no `OrderPolicy`. A method on one would have no decision left
to make, and ADR 0008 says a policy method with no decision is deleted.

**The seller's view of the same rows is not built.** It is a different audience
with a different allowlist - a seller sees the buyer, and must not see the other
shops in that person's basket - and it is the change that will bring a policy
with it.

`verified` is on checkout and deliberately not on the cart. Filling a basket is
browsing; the confirmation, the receipt and everything about a dispute go to an
address, and buying something is when one nobody has confirmed becomes a
problem.

---

## Not yet decided

- **Payment.** The whole of it. An order is placed and nothing charges for it.
- **Releasing stock.** Nothing cancels or expires an unpaid order, so stock
  taken at placement is held indefinitely. This is the most pressing gap above.
- **A quoted total.** The price charged is the price at the moment of checkout.
  The cart flags a change beforehand, and there is no confirmation step that
  pins a total the buyer has seen and refuses to exceed it. A buyer checking out
  from a stale page is charged the current price without being asked.
- **Addresses and delivery.** An order has no address on it, and nothing is
  shipped anywhere. `shipping_minor` and a delivery address arrive together.
- **Idempotency keys.** A double submit of one cart is safe because the cart
  lock makes the second attempt find an empty cart. A retried request that lost
  its response is not the same problem and is not solved.
- **Buying from your own shop.** Still nothing stops it (ADR 0010). Checkout is
  where the rule belongs, and it has not been written.
