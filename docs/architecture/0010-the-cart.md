# 0010 - The cart

Status: accepted - 2026-09-10

The basket. Settles what a cart line is, what it remembers, and what it
deliberately does not: a price. Everything here is a consequence of one
position - **the catalogue is the source of truth until checkout writes an
order.**

---

## One cart per account, and no status column

`carts.user_id` is unique. "One active cart per customer" is therefore not a
rule the application applies; there is nowhere to put a second one.

There is no `status`, and the omission is deliberate. A status implies a second
state to be in, and the only candidate - "converted" - describes something
orders do not need. An order snapshots what was agreed (root `CLAUDE.md`
section 7), so it will carry its own lines and the cart is emptied. A status
enum with one case in use is a table waiting for a workflow that does not exist.

A single `cart_items.user_id` with no `carts` table at all was considered and is
very nearly as good. The cart earned its own row for two reasons: it is the row
that gets locked when two requests change one basket at once, and `updated_at`
on it is the one cart-level fact anything has needed - it is what an abandonment
reminder would read.

---

## A line references a variant, never a product

A listing with two sizes has two prices and two stock counts, so "this product,
quantity 2" does not name anything that can be bought. That was settled in
ADR 0009; this is where it starts to matter.

```text
cart_items   cart, variant, seller, quantity
             + added_price_minor, product_name, variant_name   (snapshot)
```

`(cart_id, product_variant_id)` is unique, so adding something already in the
cart raises its quantity rather than making a second line. That is what a
shopper pressing "add" twice means.

---

## The snapshot is for display and for change detection. It is not the price.

This is the part worth being precise about, because a cart that quotes its own
stored price is a common design and it is wrong here.

**What a line costs is read from the variant every time the cart is shown.** If
a seller raises a price, the cart charges the new one and says so:

```json
{
  "unit_price_minor": 720,
  "added_price_minor": 650,
  "price_changed": true
}
```

The alternative - honouring the price at the time it was added - is a promise
the marketplace has not made and cannot fund. Nothing was agreed when somebody
put an item in a basket; the seller is not paid, no stock was set aside, and a
cart can sit for a month. The agreement is made at checkout, and **that** is
when a price is snapshotted onto an order and stops moving.

So why store `added_price_minor` at all? Because without it the change is
undetectable, and a shopper discovers it at the payment screen. One column buys
the ability to say "this went up while it was in your basket", which is the
difference between a surprise and a decision.

`product_name` and `variant_name` are stored for the same class of reason: so a
line whose variant no longer exists is still recognisable.

---

## The variant reference is nulled on delete, not cascaded

A seller removing a variant would otherwise delete it out of every shopper's
cart, silently. The item is simply gone next time they look, with nothing to
explain it.

```php
$table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
```

The line survives with its snapshot, reports `no_longer_for_sale`, and reads as
something a person recognises. It is the same reasoning that makes products
soft-deleted (ADR 0009), one level down.

That is also what makes `cart_items.seller_id` worth its column. It is derivable
from the variant while the variant exists - which is exactly the point. A line
whose variant is gone still has to be grouped under its shop and priced in that
shop's currency. A product never moves between shops, so it cannot drift.

---

## Grouped by shop, and there is no grand total

```json
{
  "item_count": 3,
  "has_unavailable_items": false,
  "shops": [
    { "shop_slug": "aalto-bakery",   "currency": "EUR", "subtotal_minor": 1300, "items": [...] },
    { "shop_slug": "bergman-coffee", "currency": "SEK", "subtotal_minor": 12900, "items": [...] }
  ]
}
```

Sellers price in their own currency (ADR 0004, ADR 0007), so a basket spanning
three shops is three subtotals in three currencies and a single figure across
them does not exist.

**The response shape is what keeps that from being an easy mistake to make.** A
flat list of lines with one total at the bottom would have made the wrong total
the obvious thing to add, and it would have been wrong the first time anybody
bought from two shops. Here there is nowhere to put it, and
`CartTest::test_a_cart_spanning_two_shops_is_grouped_with_a_subtotal_each`
asserts the exact key set so it stays that way.

Each group will become one order and one payment.

A subtotal counts **available lines only**. It is the number a shopper is about
to be charged, not a sum of everything in the box - and every line reports its
own total and its own availability, so nothing is hidden by the omission.

---

## Not for sale is 404. Not enough stock is 409.

Two refusals, two statuses, and the difference is not cosmetic.

**A variant that is not for sale is resolved through `Product::scopePublic()`,
so it is simply not found.** A draft, a deleted listing or an unapproved shop
answers 404 - the same answer the storefront gives for the same product. There
is one definition of "for sale" and the cart does not write a second one.

There is deliberately **no `exists` rule** on `variant_id` in the form request.
With one, an id that does not exist would answer 422 while an unpublished one
answered 404, and the difference between the two would tell somebody guessing
which ids are real.

**Not enough stock is 409**, with how many can be had:

```json
{ "message": "Only 3 of these are left.", "available": 3 }
```

`"quantity": 50` is a perfectly valid integer; what is wrong is that there are
three. That is the state of the world rather than the shape of the request,
which is the line ADR 0008 draws - and it is the difference between a frontend
showing "check this field" and one offering "reduce to 3".

Publishing `available` narrows ADR 0009's refusal to put exact stock in front of
shoppers, and does not abandon it. The number appears only when it is smaller
than what was asked for, which is when the shopper has to be told it to fix
their cart. It does not close the hole - somebody can still probe stock by
asking for a large number - and that is accepted, because reserving stock at
order time is the real control, not a number withheld from a page.

---

## Nothing is reserved

`stock` is still a number that nothing decrements. Adding to a cart takes no
inventory, and two shoppers can hold the last one at the same time.

That is not an oversight, it is ADR 0009's open question still open: **how stock
is held between "add to basket" and "paid" is an ordering decision**, and it is
where marketplaces oversell. It arrives with orders, and this is the state it
has to be designed against - a durable cart that never held anything.

What exists instead is honesty about it. A line whose stock fell reports
`insufficient_stock` and how many remain, and the subtotal stops counting it.

---

## A cart is durable, so a line answers for itself

The catalogue moves under a cart that has been sitting for a week. Every line
therefore reports which of four cases it is in:

```text
available            can be bought
no_longer_for_sale   unpublished, deleted, shop no longer approved, variant gone
out_of_stock         still listed, none left
insufficient_stock   some left, fewer than this line asks for
```

Three ways of being unbuyable rather than one, because the shopper does
something different about each: find it elsewhere, wait, or reduce the quantity.

This is the **answer**, not the inputs (root `CLAUDE.md` section 4). The frontend
does not receive a status, a stock count and a shop state to re-derive
availability from.

Mechanically, `CartItem::purchasableVariant` is a `belongsTo` **constrained by
`Product::scopePublic()`**, so it is null both when the variant row is gone and
when its listing is no longer for sale. Availability is then one null check
rather than a walk up to the product and the shop with a second copy of "what
the storefront shows" written along the way.

---

## Reading a cart does not create one

`GET /cart` answers for an account that has never added anything without
inserting a row. The resource is built from the **lines** rather than from a
`Cart` model for exactly this reason: an empty cart is a perfectly good cart,
and a read that writes would insert one per visit.

Every mutation, by contrast, answers with the **whole cart** - including
`DELETE`, which returns 200 and the emptied cart rather than 204. Any change
recomputes a subtotal and can change another line's availability, so a client
given 204 would have to immediately fetch what it just changed.

---

## There is no CartPolicy

Every lookup starts from the authenticated user's cart, so another account's
line is never in the query to begin with. That is ADR 0008's "let the query
carry it", and here it is complete: a policy method would have no decision left
to make, and a method with no decision is one ADR 0008 says to delete.

Line ids do appear in paths, and they are resolved through `$user->cart->items()`
rather than by route model binding. **Implicit binding resolves globally**, so
`{item}` would otherwise be any line in the database. Somebody else's line
answers 404 rather than 403, because a 403 confirms the id names something real.

---

## A domain 409 has to be told to the generator

Found by reading the generated document, like the last one.

`VariantNotPurchasableException` is a domain exception and deliberately knows
nothing about status codes - `bootstrap/app.php` decides that it renders as 409
(`apps/api/CLAUDE.md` section 10). The generator therefore cannot infer it, and
the published contract said these endpoints could not answer 409 at all.

The fix states it at the HTTP boundary, where the translation already happens:

```php
#[Response(status: 409, description: self::CONFLICT, type: self::CONFLICT_BODY)]
public function store(AddCartItemRequest $request, AddToCart $add): JsonResponse
```

The domain exception stays free of HTTP, and the contract stops lying.

**Every other domain 409 in this repository has the same gap** - publishing
into an unapproved shop, applying to sell twice, reviewing a shop twice,
removing the last variant. They are not fixed here; that is its own change.

---

## Not yet decided

- **Guest carts.** A cart belongs to an account, so a shopper must sign in
  before adding anything. A cart keyed by cookie, and merged on sign-in, is a
  real decision with its own failure modes and it has not been taken.
- **Buying from your own shop.** Nothing stops a seller adding their own
  listing. It is a checkout rule rather than a cart rule, and it belongs with
  orders.
- **Abandonment.** `carts.updated_at` is maintained and nothing reads it. No
  cart is expired or cleaned up.
- **Where the quantity ceiling belongs.** 999 per line is a sanity bound in the
  form request, not a business rule. A seller wanting to cap an order at two is
  a different feature.
