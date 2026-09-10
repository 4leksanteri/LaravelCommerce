# 0004 - Money and currency

Status: accepted - 2026-09-10

Written before any money exists in the schema, because every one of these is
expensive to retrofit and cheap to establish.

---

## Integer minor units

A price is stored and transported as an integer number of minor units, with an
explicit currency beside it.

```text
2499   EUR      is 24.99 euro
2499   JPY      is 2499 yen, because JPY has no minor unit
```

Never a float. Never a decimal string that something later parses into a float.
`0.1 + 0.2` is a defect in a marketplace, not a curiosity, and it is the kind
that surfaces as a one-cent discrepancy in a payout six months in.

In PostgreSQL that is `bigint`. `bigint` rather than `integer` because a
four-byte integer tops out at about 21 million euro in cents, and an aggregate
over a year of orders reaches that sooner than anybody expects.

In PHP that is `int`, which is 64-bit on every platform this runs on. In
TypeScript it is `number`, which is exact for integers up to 2^53 - far beyond
any real amount - **provided nothing divides**.

---

## Currency is a column, not a setting

Every monetary amount carries its currency. There is no default currency, no
platform currency, and no column called `amount` without a `currency` beside
it.

Sellers price in their own currency. That is the requirement that makes
everything below follow.

---

## Never sum across currencies

An aggregate is grouped by currency, always.

```text
Wrong:   SELECT SUM(total) FROM orders
Right:   SELECT currency, SUM(total) FROM orders GROUP BY currency
```

A basket spanning three shops in two currencies has no single total. The
interface shows the breakdown, exactly as it shows a per-seller order
breakdown, because that is what is true.

Where a single figure is genuinely needed - a platform revenue dashboard, say -
it is a **display conversion**: computed with a rate that is recorded alongside
the result, labelled as approximate, and never used to settle anything. A
converted figure never becomes an amount somebody is charged or paid.

---

## An order is per seller

A basket spanning three shops becomes three orders and three payments. This is
a money decision as much as a domain one: each order is in one currency, held
by one seller, settled once.

It removes the question of what a multi-currency basket total means, because
the basket is a view and the orders are the records.

---

## Snapshot what the buyer was shown

Price, shipping, tax and any fee are copied onto the order at the moment it is
placed.

A product's price changes tomorrow. What somebody agreed to today does not.
Reading a price back through a relation - `$order->product->price` - is a
defect: it reports today's price for a historical order, and it does so
quietly.

The same applies to a shop's name and a seller's payout terms, wherever the
order needs to say what they were at the time.

---

## Rounding is deliberate and tested

Every place a division happens - a platform fee, a tax split, a partial refund
across line items - names its rounding rule and has a test.

The specific hazard is that a split must sum back to the whole. Rounding each
share independently loses or gains a unit, and the difference has to land
somewhere on purpose rather than by accident.

Never introduce a division into a money path without deciding, in that change,
who absorbs the remainder.

---

## The frontend formats; it never computes

`Intl.NumberFormat` turns `2499` and `EUR` into text a person reads. That is
the whole of the browser's involvement with money.

- No arithmetic on amounts in TypeScript. Not a subtotal, not a discount, not a
  running basket total.
- If a figure is needed, the API computes it and sends it. It has the exact
  types, the tax rules and the rounding decisions; the browser has none of
  them.
- A figure computed in the browser will eventually disagree with the one the
  API charges, and the buyer will believe the one they were shown.

---

## Not yet decided

Deliberately open, to be settled by ADRs written when each is built:

- Which currencies are supported, and whether a seller may change theirs.
- Where display conversion rates come from, and how stale one may be.
- How the platform fee is expressed, and whether it varies by seller.
- Escrow: how long funds are held, and what a dispute does to that clock.

What is settled is the representation. Those decisions are built on top of it,
and none of them should require changing how an amount is stored.
