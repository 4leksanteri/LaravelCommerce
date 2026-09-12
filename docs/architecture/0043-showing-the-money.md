# 0043 - Showing the money

Status: accepted - 2026-09-12

Escrow has worked end to end since ADR 0041 and told nobody. A buyer's order
read exactly the same whether they had paid for it or not; a shop could see
that Stripe was ready to receive money and never that any had arrived.

This is what the API says about it. The pages that draw it follow.

---

## The buyer is told what happened to their money

```text
payment_status   what Stripe last said, or null when no intent exists yet
paid_at          when the charge succeeded
refunded_at      when it was given back
can_pay          whether there is still a card to enter
```

`payment_status` is the enum rather than its value, so the generated contract
is a union of the actual cases and a component switching on it is exhaustive.

**`can_pay` is the answer, not the inputs.** A browser deriving it would need a
copy of two rules - that only a pending order is payable, and that a paid one
is not - and the copy is what goes stale (root `CLAUDE.md` section 4). The rule
is `Order::canBePaid()`, so the field and the domain cannot disagree.

It exists because ADR 0042 left a real gap: an unpaid order stays visible to
its buyer precisely because that is where paying for it starts, and nothing on
the order led them back to the card form.

**A refund does not undo the charge.** `payment_status` stays `succeeded` on a
refunded order, because the charge did succeed; `refunded_at` is the second
event. The same distinction `Payment` already draws internally.

## The shop is told what it gets

```text
paid_at               a shop is shown no other kind of order (ADR 0042)
platform_fee_minor    what the marketplace keeps
payout_amount_minor   what arrives, or would
transferred_at        when it did
refunded_at           when it went back instead
```

**The fee has one definition**, and this is what moved to get it:
`Payment::platformFeeMinor()`. A shop looking at an order it has not been paid
for yet is asking what it will receive, which means the figure has to exist
before any transfer does - and computing it a second time in a resource is how
the number a seller was quoted stops matching the number Stripe is sent.

The recorded figure wins once there is one. What was kept is a fact about a
transfer that happened, not something to re-derive from a rate that has changed
since, and `TransferAndRefundTest` pins the rate for that reason. A test moves
the rate to 25% after a transfer and asserts the shop is still shown the 5% it
was actually charged.

`TransferToShop` now takes the fee from the same method rather than its own
`feeFor()`, which is the only behaviour change on the money path in this ADR.

## The payouts page gets a list

```text
GET /api/v1/seller/payout-account/transfers
```

Under `payout-account` because that is the page it feeds, and behind `seller`
like everything else about getting paid.

**It lists payments, not a `transfers` table of our own.** A transfer is one of
the things that happen to a charge, and Stripe owns whether it happened
(ADR 0031); a second table would be a second opinion to keep in step. What is
published is the three figures a seller needs to reconcile a payout - charged,
kept, received - and the order they belong to.

**Only transfers that happened.** Money being held is not a payout. It is on
the order it belongs to, where a seller can see why it has not moved.

**No Stripe ids.** `stripe_transfer_id` names an object in the platform's own
account, which a seller cannot look up and has no use for.

Two details are the same ones every list here has had to learn. The query
starts from `Payment::query()` rather than reaching through a relation, because
a scope forwarded by `__call` is something the OpenAPI generator cannot follow
and the endpoint gets published without its `meta` - twice now, and `make
api-check` caught both. And ownership is carried by the query rather than
checked afterwards: a payment belongs to an order, an order belongs to a shop,
so another shop's payouts are never in the set (ADR 0008).

## What is deliberately not here

**No total.** A running "you have been paid this much" is a sum, and the moment
it exists somebody will want it across every shop they own. Within one shop it
is safe - a shop's currency is fixed at application (ADR 0004) - so this is a
deferral rather than a prohibition, and the page can add one when it needs it.

**No held-money list.** What a shop is owed but has not been sent is a real
question, and it is answered per order today. A page for it is worth having
once there is evidence sellers want it, rather than because it is easy.

## Testing

`PaymentSurfacesTest`: a buyer sees paid, refunded, and that an unpaid order
still needs paying; a shop sees what it will receive before the money moves and
the fee that was actually taken after; the payouts list shows this shop's
transfers newest first, excludes money that is merely held, excludes another
shop's, and refuses a caller with no shop.

---

## Not yet decided

- **The pages.** Nothing renders any of this yet, which is the other half of
  this ADR and the next commit.
- **Telling a buyer why an order expired.** Still ADR 0042's open question: the
  cancellation mail does not say "because nobody paid".
- **A second attempt after expiry.** A buyer whose card failed twice starts
  again, because the order is gone and the basket is empty.
