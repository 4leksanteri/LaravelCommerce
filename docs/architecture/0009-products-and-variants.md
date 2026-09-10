# 0009 - Products and variants

Status: accepted - 2026-09-10

The catalogue. Settles where a price lives, which is the decision everything
about ordering and payment will rest on.

---

## Price and stock live on variants, and nowhere else

Every product has at least one variant. A product with nothing to choose from
still has exactly one, created with it and usually called "Default".

```text
products          name, description, slug, seller, status
product_variants  product, name, price_minor, stock, position
```

Products have **no price column**. Asking a listing what it costs is a question
with no single answer the moment it has two sizes, so it is not a question the
products table answers.

Three arrangements were possible and two were rejected:

- **No variants, price on the product.** Simplest today. Adding variants later
  means moving price and stock to another table _after_ orders reference
  products - a data migration touching money, done on live data.
- **Price on the product, variants overriding it.** Looks like a compromise. In
  practice every read asks "is there a variant, and if so whose price wins",
  and every checkout has to get that answer right. Two places a price can live
  is two places it can be wrong.
- **Price only on variants.** What this does. One place, always.

**Orders will reference a variant, never a product.**

---

## What is not on a product

Three things were proposed and do not belong:

- **`currency`.** A shop's currency is fixed (ADR 0007) and a product belongs
  to one shop, so a column here would be a second copy that can disagree with
  the first. `Product::currency()` reads it through the relation.
- **`accepted_at`, `shipped_at`.** These describe an _order's_ lifecycle. A
  product is a listing that sits there indefinitely; an order is the thing that
  gets accepted and shipped. They arrive with `orders`.
- **`archived` as a status.** Unpublishing already means "keep it, stop selling
  it". A third status would be a second way to say draft.

---

## Publishing needs an approved shop, and the storefront checks again

`PublishProduct` refuses when the shop is not approved. Without it, applying to
sell and publishing immediately would put products on the marketplace with
nobody having reviewed the shop, which is what approval is for.

That refusal is **409, not 403** (ADR 0008): the seller is entitled to publish
their own products, and what is in the way is a fact about their shop.

`Product::scopePublic()` requires _both_ halves independently - the listing
published and the shop approved:

```php
$query->where('status', ProductStatus::Published)
      ->whereHas('seller', self::approvedSeller(...));
```

The two are not redundant. The action gives the seller an answer; the scope
protects the storefront, and it is the one that holds if a shop is ever
suspended after its products were published.

Drafting, by contrast, does **not** need an approved shop. Somebody waiting on
review can prepare their catalogue, and a draft is not visible to anybody.

---

## Deleting is soft

`DELETE` sets `deleted_at`. Order history will eventually point at these rows,
and a hard delete would orphan it - somebody's receipt should not stop making
sense because a seller tidied up.

It is also what "delete" means to a seller: take it out of my shop. Every
public query excludes trashed rows, so it disappears immediately.

Slugs are checked against trashed rows too, so restoring one cannot collide.

---

## Slugs are unique per shop, not globally

Two shops may both sell a rye sourdough, and neither should have to call theirs
`rye-sourdough-2` because the other got there first. The unique index is on
`(seller_id, slug)`, and the public path carries both:

```text
/api/v1/shops/koskela-bake-house/products/rye-sourdough
```

Renaming a product does not move its slug. Somebody has the link.

---

## A shopper is told availability, not inventory

`PublicProductResource` publishes `in_stock`, a boolean. The seller's own view
publishes the number.

An exact live count is a competitor's inventory report, and it invites a race
that the checkout has to win anyway - reserving stock at order time is the real
control, not a number on a page.

---

## Named resource collections, because the contract depends on it

**A list endpoint returns a named `ResourceCollection`, never
`Resource::collection(...)`.** This is not tidiness; it was found by looking at
the generated document.

`ProductResource::collection($products)` returns Laravel's
`AnonymousResourceCollection` - unnamed, because there is no class to name.
Scramble cannot see through it, and every list endpoint was published to the
frontend as:

```json
{ "data": { "type": "array", "items": { "type": "string" } } }
```

An array of strings. The pagination envelope was right and the contents were a
lie, and the generated TypeScript believed it.

With `ProductCollection extends ResourceCollection`, the same endpoint
generates `data: ProductResource[]`.

These classes carry no behaviour and are not meant to. If one ever needs
collection-level data - a total, an aggregate - that is where it goes.

---

## Nested routes use scopeBindings()

```php
Route::patch('/{product}/variants/{variant}', ...)->scopeBindings();
```

Load-bearing, not decorative. Without it `{variant}` resolves globally, and a
seller could edit another shop's variant by putting its id after the path of a
product they do own. There is a test that does exactly that and expects a 404.

---

## Not yet decided

- **Images.** A catalogue without photographs is not a marketplace, and file
  upload has its own decisions - storage, validation, resizing, what a public
  URL looks like. Its own ADR.
- **Categories and search.** Nothing browses across shops yet.
- **Reserving stock.** ~~`stock` is a number that anybody can read; nothing
  decrements it, because nothing orders yet.~~ **Decided in
  [ADR 0011](0011-checkout-and-orders.md)**: checkout takes stock at placement,
  behind a row lock, in the same transaction that writes the order. Nothing
  releases it again yet.
