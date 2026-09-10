# 0021 - Addresses

Status: accepted - 2026-09-10

ADR 0019, taking inventory of what the design export assumes the API has, said
the order tracking screen _"will look convincing and mean little until addresses
exist."_ This is the address.

Tracking itself is still not built - `shipped_at` and nothing else, no carrier
and no number. What changes here is which half is missing: a parcel now has
somewhere to go, and what nobody records is it going.

---

## Two places, and the second one is the point

```text
addresses          a buyer's address book. Editable, deletable.
orders.shipping_*  where this parcel went. Frozen, forever.
```

**There is deliberately no `address_id` on an order.** A buyer who moves house
edits their address book, and with a reference every order they had ever placed
would silently start claiming it went somewhere it did not.

That is the same argument that freezes names and prices onto `order_items`
(ADR 0011), applied to the one other thing a receipt has to be true about. A
receipt says what was bought, for how much, and where it went; none of those may
move afterwards.

It also settles deletion without a rule. Nothing points at an address, so
removing one is a hard delete that takes nothing with it - no soft delete, no
409, no restrict.

## The field set is Stripe's

`name`, `line1`, `line2`, `city`, `region`, `postal_code`, `country`, `phone`.

That is not laziness. This marketplace is heading for Stripe Connect
(ADR 0015), and a schema that already matches what the payment processor expects
is one translation nobody has to get right later.

**`region` and `postal_code` are both nullable**, and this is where naive address
schemas go wrong. Plenty of countries have no state or province worth recording,
and several have no postal codes at all - Ireland had none until 2015, and the
UAE still does not. Requiring either makes the form unfillable for somebody, and
what they do about it is type "N/A" and put that on a parcel.

`name` is the recipient rather than the account holder, because people send
things to their partner, their office and their parents.

## Half an address is the state worth forbidding

The snapshot columns on `orders` are nullable, which is honest rather than
convenient: an order placed before addresses existed has none, and there is no
backfill that is not an invention - nothing in a migration knows where a parcel
went. There happen to be zero such orders. The schema does not pretend that
could not have been otherwise.

What is forbidden is the state that would actually be a bug:

```sql
CHECK (
    (shipping_line1 IS NULL) = (shipping_name IS NULL)
    AND (shipping_line1 IS NULL) = (shipping_city IS NULL)
    AND (shipping_line1 IS NULL) = (shipping_country IS NULL)
)
```

Written as equivalences so it catches both halves: an address with no recipient,
and a recipient with no address. The genuinely optional fields are free to be
null on their own, because for somewhere in the world each of them legitimately
is.

## Country codes are shaped, not verified

`CHECK (country ~ '^[A-Z]{2}$')`, and that is all. It does not confirm the code
names a real country, so `ZZ` gets through.

Verifying against the ISO 3166 register needs a dependency carrying the list, and
the mistakes that actually happen are "United Kingdom", "gb" and "FIN" - all of
which this catches. When shipping rates or customs need a real country, that is
the change that buys the package.

---

## Checkout takes a request body now

ADR 0011 has a section titled "Checkout takes no request body", and it is worth
being precise about what has changed, because the claim underneath it has not.

What it actually said was: **nothing a client sends contributes a figure to what
somebody is charged.** That is still true. The cart, the prices and the totals
are read from the server under lock, and `address_id` is not a figure.

```text
POST /api/v1/checkout   { "address_id": 12 }
```

An **id from the buyer's own book**, not an address inline. One path creates an
address and one shape it can be in; a checkout that could invent one would be a
second, less validated way to make the same row.

There is no `exists` rule on it. Ownership is what matters, and `PlaceOrders`
resolves it through `$buyer->addresses()` - so somebody else's id and one that
was never issued give the same **404**. An `exists` rule would have split those
into 422 and 404 and told a guesser which ids are real, which is the same trap
ADR 0010 avoided with `variant_id`.

**One basket, one destination.** Every order in a multi-shop checkout freezes the
same address; a cart that could ship to three places is a different product.

## Who sees it

The buyer, on their own order. The seller, on theirs - they cannot post a parcel
without knowing where it goes.

A seller therefore sees the address the moment an order exists, including one
they then cancel. That is unavoidable in a marketplace that ships physical
things, and it is the reason a payment gate matters: once payments exist, the
question of whether an _unpaid_ order should reveal an address is worth asking.
It cannot be asked usefully yet.

---

## Not yet decided

- **A default address.** There is none, and a checkout has to pick. The list is
  newest-first, which is a reasonable default for a client to preselect and not
  the same thing as the buyer choosing one.
- **Labels.** "Home", "Work". One nullable column, and nothing needs it yet.
- **Billing addresses.** Deliberately absent. Stripe collects one with the card,
  and it belongs to the payment rather than to the parcel.
- **Validation or normalisation against a real address database.** Nothing
  checks that the street exists, and postcodes are not normalised.
- **Everything tracking still needs**: a carrier, a tracking number, and a
  dispatch record. This unblocks them; it is not them.
