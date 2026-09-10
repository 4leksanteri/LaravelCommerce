# 0017 - Categories

Status: accepted - 2026-09-10

ADR 0009 left this open as "categories and search - nothing browses across shops
yet". This is the first half: something browses across shops now, by what things
are.

---

## The platform owns the list

Sellers choose from it. They do not add to it.

A category set every shop can extend stops being a way to find anything and
becomes fifty spellings of "bread" - which is the failure mode of free-text
tags, and the reason a marketplace with real discovery does not have them.

**There is no endpoint that writes a category yet.** They come from
`CategorySeeder`, which is idempotent and keyed on slug, so adding one is
editing an array and re-running rather than a migration. That is a deliberate
half-step: sellers need something to pick from and shoppers need something to
browse, and neither needs a CRUD screen to exist first.

It also means there is **no `CategoryPolicy`**. ADR 0008 says a policy method
with no caller is deleted, and every method one would carry today has no caller.
It arrives with the admin panel, which is the change that gives it something to
decide.

---

## Two levels, and no more

`parent_id` gives "Food and drink > Bread and baking", which is what a
navigation needs. A third level would want recursive queries and unbounded
breadcrumbs for a catalogue that has neither.

The depth limit is not enforced by a constraint - it is a property of the seeded
data and of `withDescendantIds()`, which looks one level down and does not
recurse. Relaxing it later is changing that method; introducing nesting later
would have been a data migration, which is why the column is here now.

Slugs are unique **globally**, not per parent, because a category URL is
`/categories/bread` with no parent in it. Two "Bread" under different parents
would be the same address.

---

## One category per listing

Not many-to-many. A product in four categories has no unambiguous breadcrumb and
no obvious place to appear, and the flexibility buys nothing a shopper can
perceive. It is also how a seller thinks about their own stock: this is a bread,
not a bread-and-gift-and-seasonal.

## Nullable in the column, required to publish

A draft can be anything - somebody typing up a listing has not decided yet - but
a published one has to be findable. **A listing in no category is in no
navigation**, which is not "on sale" in any useful sense.

That is the same shape as needing an approved shop to publish (ADR 0009):
drafting is free, going on sale has conditions. And it is refused the same way,
with a **409** rather than a 422 - nothing about the request is wrong, the
listing is not ready.

The rule is in the database as well as in `PublishProduct`:

```sql
CHECK (status <> 'published' OR category_id IS NOT NULL)
```

The action gives the seller an answer; the constraint is what holds if anything
ever writes the column directly. That is the difference between a rule and a
habit.

**The migration unpublishes listings that cannot satisfy it.** Every product
published before it had no category, and there is no sensible backfill - nothing
in a migration knows what a listing is. They go back to draft with their
catalogues intact and one request republishes them. Called out here because it
changes what is on sale.

## A schema rule still needs somebody to translate it

Writing this ADR turned up a defect rather than describing one. `null` is a
perfectly valid value for `category_id` - it is what every draft has - so the
form request cannot refuse it. Sending it for a **published** listing hit the
CHECK constraint, and the seller got a constraint violation rendered as a
**500**.

`UpdateProductDetails` now refuses it with a 409 and a sentence saying what to
do instead. The constraint was doing its job; nothing was turning its answer
into one a person could act on.

That is worth generalising: a rule in the database is the thing that holds, and
it is never the thing that explains.

---

## A category page is the first read that needs no shop

Every other public endpoint is scoped to a shop slug the caller already has,
which is no use to somebody arriving at the front door.

```text
GET /api/v1/categories                      the navigation, as a tree
GET /api/v1/categories/{slug}/products      across every approved shop
```

**A parent includes everything underneath it.** Somebody browsing "Food and
drink" expects the bread as well, and a parent whose own page is empty because
all the listings hang off its children is a navigation that punishes using it.

`Product::scopePublic()` still decides what a shopper may see, so an unapproved
shop's listings are as absent here as they are from its own storefront. A
category page is not a way around approval.

## Which forced two fields onto the public product

`shop_slug` and `shop_name` are new on `PublicProductResource`. They are
redundant on a shop's own storefront, where the caller supplied the slug -
and essential here, because a category page is the first place listings from
different shops sit next to each other and a card has to say whose it is.

---

## `children` is always a collection, even when empty

Found by reading the generated document, which is where these keep turning up.

A bare `[]` for the unloaded case published to the frontend as
`CategoryResource[] | string[]`: an empty array literal has no element type, so
the generator invented one. The same family as the `data: string[]` bug
`ProductCollection` records.

Both branches now return a collection of the same resource, and the type is
`CategoryResource[]`.

---

## Not yet decided

- **Managing them.** No endpoint creates, renames, reorders or deletes a
  category. Deleting one needs a rule about listings that reference it - the
  foreign key is `restrictOnDelete` so nothing can quietly orphan them, but a
  409 with a count is a better answer than a constraint violation.
- **Search.** The other half of what ADR 0009 left open. Browsing by category is
  not the same as looking for a word.
- **Counts on a category page.** Filtering a category by price, or sorting it,
  is the next thing a browsing page wants and none of it exists.
- **Counts.** A navigation usually says how many things are in each branch.
  That is a query per node unless it is cached, and nothing needs it yet.
