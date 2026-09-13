# 0046 - An order nobody paid for

Status: accepted - 2026-09-13

ADR 0042 gave an unpaid order a clock of its own and left two things open: the
buyer is not told **why** it went, and there is no way to try again. Both are
closed here.

---

## The buyer is told which clock ran out

```text
unpaid, expired    "cancelled because it was not paid for in time"
paid, expired      "the shop did not accept it in time"
```

Until now both said the second. A buyer whose card was declined was told that
the shop had not answered, which is a sentence about somebody who never saw the
order - and it sent them to the wrong place to fix it.

**Nothing new is stored to say which.** `cancelled_by` is already `deadline`
for both, and the payment answers the rest: an order a deadline ended that was
never paid ran out on the short clock, and one that was paid ran out waiting for
its shop. A column, or a third `OrderActor`, would be a second copy of something
the payment already knows.

The same correction is on the order's own page. `OrderTimeline` said "the shop
did not accept it in time" under every deadline cancellation, and now reads the
clock the same way.

## The shop is not told at all

An unpaid order is invisible to its shop (ADR 0042), so mail about one would
describe an event that never reached them: an order they were never shown, with
stock "back on sale" that never left it. It also says that somebody tried to buy
from them and failed, which is not theirs to know.

So expiry now tells the buyer always, and the shop only when the order was one
it could actually see.

## The basket comes back, not the order

The order cannot be revived: it is cancelled and its stock has been returned,
and reviving it would take that stock twice. What was lost with it was the
basket - checkout empties the cart inside its own transaction (ADR 0011) - so a
declined card meant finding every item again.

`RestoreBasket` puts the lines back:

```text
from       the order's own snapshot: name, variant, quantity, what it cost
into       the buyer's cart, locked, additive, never overwriting
skips      a line whose variant has since been deleted
```

**Additive on purpose.** A variant already in the cart gains the quantity, and
nothing is removed: the buyer may well have gone shopping again while the order
sat there unpaid.

**It costs nothing to be wrong about.** A cart reserves no stock and holds no
prices, so restoring commits nobody to anything - what a line costs and whether
it can still be bought is read when the cart is next shown (ADR 0010). That is
what makes this safe to do automatically rather than behind a button.

`added_price_minor` is set from the order's `unit_price_minor` rather than
today's catalogue. It is a snapshot for saying "this has gone up since", and
what this person last agreed to pay is the honest thing to compare against.

**Restored before the mail that mentions it**, so the sentence is never the only
true thing about it. Like the refund, the restore is guarded: a cart that will
not take the lines does not turn a finished expiry into a failure.

## Testing

PHPUnit: an order that expires unpaid puts its basket back; a restored line adds
to a basket that was refilled meanwhile rather than replacing it; a paid order
that expires leaves the basket alone; and the expiry mail tells only the buyer,
saying it was not paid for.

Vitest: the timeline tells a buyer their unpaid order was not paid for, and
still blames nobody for a paid one the shop let lapse.

---

## Not yet decided

- **Nothing triggers any of this.** `orders:expire` is still run by whatever
  somebody runs it with (ADR 0013), so in production an unpaid order holds its
  stock until then.
- **A buyer is not told the moment it is about to expire.** Thirty minutes is
  short, and a "your order is about to be cancelled" mail would arrive after
  most people had given up anyway.
- **The restored basket is not pointed at.** The mail says the items are back;
  nothing takes the buyer to the cart from the cancelled order's page.
