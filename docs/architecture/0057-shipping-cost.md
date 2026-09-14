# 0057 - What a parcel costs to send

Status: accepted - 2026-09-14

Until now delivery was free. Not free as a decision - free because nothing
charged for it, and every shop quietly absorbed the postage on everything it
sold. ADR 0049 built the carrier and the tracking number and named this as the
gap it was leaving: "Nothing charges for postage or buys a label, which is a
larger chapter than this one."

This is that chapter, minus the labels.

---

## ADR 0011 wrote the design down in advance

Two sentences, both from the checkout ADR, decided most of this before it was
built:

> It is called `total` and not `subtotal` because it is what the buyer owes. It
> equals the sum of the lines only because there is no shipping and no tax;
> **when those arrive they become their own columns and this one includes them,
> without the name having to change.**

> there is still no `shipping_minor`, so delivery is free and every total is the
> sum of its lines.

So: `orders.shipping_minor` is its own column, `total_minor` includes it, and
the name did not change. Following that rather than reopening it is the whole
reason the payment path needed no work at all - `OpenPaymentsForCheckout`
charges `total_minor`, and it charges the right thing on the day postage exists.

`Order::recalculatedTotalMinor()` gained the postage too, because
`CheckoutTest` asserts the stored total equals what it totals. Had it been left
out, that assertion would have failed for every order that costs anything to
send - which is the assertion doing exactly its job.

## Per listing, charged once per shop

```text
products.shipping_minor   a live figure the seller edits, in the shop's currency
orders.shipping_minor     a snapshot, and never read from the catalogue again
```

**Per listing, because the design export says so.** It puts "+ EUR 6.90 tracked
shipping" on a card and carries "Free shipping" as a per-listing attribute in
its own mock data. A flat rate per shop was the smaller change and the wrong
one: a shop selling both a guitar pedal and a turntable would either overcharge
on the pedal or lose money on the turntable.

**Charged once per shop, and the dearest thing decides.** One order per shop is
one parcel (ADR 0011), so what the parcel costs is set by the largest thing
going into it. The two alternatives are both worse:

```text
summing every line   three things in one box pays three postages, which
                     penalises exactly the shopper a marketplace wants
a flat shop rate     see above
```

That also makes the cart honest before checkout: each shop group shows its own
postage and its own total, and there is still no figure across shops, because
that is the rule `CartShopResource` exists to make structural (ADR 0004).

**Nought means free, not unset.** There is no nullable column and no "has
postage" flag: a listing whose seller has not touched the field posts free, the
export treats free delivery as an ordinary state, and every listing that existed
before today was in fact sold with delivery free. A default of nought says all
three at once.

## The fee is taken on the goods, never on the carriage

This is the decision that could most easily have been made by accident.

`Payment::platformFeeMinor()` took basis points of `amount_minor`, and
`amount_minor` is the order total - which now includes the postage. Left alone,
the marketplace would have kept five per cent of every stamp, silently, from
the first order after this shipped.

Postage is not revenue. It is a cost the shop pays a carrier and passes on, so
`Payment::goodsMinor()` subtracts it and the fee is taken on what is left. A
shop receives the goods less the fee, **plus every penny of the postage**, and
its order page says so in as many words - without that line the two figures
beside each other look like a cut of the carriage.

It is reached through `Order::payment()`, which is now chaperoned. Every path
that computes a fee already holds the order - the resource, `TransferToShop`,
`SettleOutstandingPayments` - so the relation is in memory and this costs
nothing. That is the same trick `Product::images()` and `Cart::items()` use.

## The constraint that dictated the write order

```sql
CHECK (shipping_minor <= total_minor)
```

`PlaceOrders` writes each order with a total of nought, writes its lines, then
fills the total in. Setting the postage at the insert would have broken this
constraint against a total that was not known yet - so both are written in the
same final update. A constraint that forces the correct order of two writes is
worth more than the arithmetic it checks.

## What a refund does with it

Nothing special, and deliberately. `RefundPayment` refunds the intent in full
without naming an amount, so the postage comes back with the goods.

Before anything is posted that is plainly right. After a shipped order is
cancelled it means the shop is out both the parcel and the stamp - which is the
same trade ADR 0012 already made when it let a seller cancel after shipping, and
the same one `RefundPayment` already documents: "the shop carries the loss it
chose". Postage does not change that bargain, it just makes it slightly dearer.

## The generator trap, for the third time

`orders.shipping_minor` is a `bigInteger` cast to `integer`, sitting two lines
from `total_minor`, which is a `bigInteger` cast to `integer`. One was published
to the frontend as a number and the other as a **string**.

Nothing about the column, the cast or the resource explains the difference. What
explains it is that `Product::$shipping_minor` had a `@property int` annotation
and `Order`'s did not - so the annotation is again the mechanism, exactly as
`apps/api/CLAUDE.md` section 8 says and as ADR 0043 and ADR 0047 each found.

It surfaced as seven TypeScript errors in files that were entirely correct:
`Operator '>' cannot be applied to types 'string' and 'number'`. The contract
was lying and the pages reading it were right, which is the failure mode the
generated pipeline exists to make loud rather than silent - and it is the third
time this exact trap has been paid for. **Check the generated output.**

## Testing

PHPUnit: postage is charged once and snapshotted; a basket from one shop pays
the **dearest** postage once rather than the sum; two shops each charge their
own; a listing nobody set a rate on posts free; and the figure does not move
when the shop reprices it afterwards. The cart publishes each group's postage
and its own total while still having nowhere to put one across shops. And the
marketplace takes no cut of the carriage - asserted as 65 rather than 89 on an
order of 1300 goods plus 490 postage, with 1725 going to the shop.

Vitest: the new-listing form sends postage as minor units and sends nought when
it is left alone.

Every fixture that existed before this posts free, so every figure asserted
anywhere else in the suite is unchanged - which is what a default of nought is
worth.

---

## Not yet decided

- **Labels.** Nothing buys postage or prints anything. ADR 0049 named it
  alongside the cost and it is still a separate chapter, and a much larger one:
  it means carrier accounts and money moving the other way.
- **Shipping methods.** The export shows a named service - `shipMethod` - and
  there is one rate per listing here. Standard against tracked, with two prices,
  is a real feature and not a field.
- **Weight, size and zones.** The rate is a number the seller picks. Nothing
  computes it from what the thing weighs or from where it is going, so a seller
  posting abroad either averages or refuses by hand.
- **Free over a threshold.** "Free delivery over 50" is the commonest rule in
  retail and needs a second figure per shop.
- **Postage on a cancelled-after-shipping order.** Refunded in full, as above.
  Whether a shop should keep the stamp it actually spent is arguable and is the
  sort of thing a dispute decides rather than a column.
