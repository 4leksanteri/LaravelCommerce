# 0020 - Search

Status: accepted - 2026-09-10

The other half of what ADR 0009 left open, and the endpoint the design export
depends on most: somebody who knows the model of the camera they want has
neither a shop slug nor a category.

---

## PostgreSQL, not a search service

Meilisearch and Typesense are better at this. They do typo tolerance, they rank
better, and they are built for search-as-you-type.

They are also a **second store holding a copy of the catalogue**, which is wrong
for exactly as long as nobody notices. Every write to `products` becomes a write
somewhere else that can fail independently, and reconciling them is a job
somebody has to own.

PostgreSQL is already here holding sessions, cache and the queue, and root
`CLAUDE.md` section 13 says a service arrives in the change that gives it a job
to do. This job does not need one. If search quality ever becomes the thing
losing sales, that is the change that buys the service.

## A generated column, not a trigger

```sql
ALTER TABLE products ADD COLUMN search_vector tsvector
GENERATED ALWAYS AS (
    setweight(to_tsvector('english', coalesce(name, '')), 'A') ||
    setweight(to_tsvector('english', coalesce(description, '')), 'B')
) STORED;
```

The vector is derived by the database, on write, always. There is no code path
that can forget to update it because there is no code path that updates it -
which is the same argument the CHECK constraints elsewhere rest on.

Two details are load-bearing:

**The configuration is named.** `to_tsvector('english', ...)` rather than
`to_tsvector(...)`. The one-argument form reads the session's
`default_text_search_config`, which makes it STABLE rather than IMMUTABLE, and
PostgreSQL refuses to build a generated column from it. The error does not
mention text search.

It is also named in exactly one place in PHP - `Product::SEARCH_CONFIG` - because
a query using a different configuration still runs, still returns rows, and
quietly stems differently from the index.

**`setweight` is not decoration.** Name in band A, description in band B, so a
listing called "Olympus OM-1" outranks one that mentions an OM-1 in passing.
Without it every match scores identically and "ranking" is whatever the planner
returns.

The cost is a `tsvector` on every `SELECT *` from `products` - a kilobyte or two
per row. An expression index would avoid that, at the price of duplicating the
expression between the DDL and every query that hopes to hit it. Correctness
first: the column is one definition, and the index is unmissable.

## `websearch_to_tsquery`

People type search syntax whether or not anybody supports it. This understands
quoted phrases, `or`, and a leading `-` to exclude, combines bare words with
AND, and - unlike `to_tsquery` - never throws on malformed input. A search box
that 500s on `&&&` is a search box somebody will find.

---

## `q` is optional

`/search` with no term is "everything on the marketplace, newest first".

That is deliberate and it earns its keep twice: the search screen has something
to render before anybody types, and it is the closest thing this marketplace has
to a front page. It also means the shop storefront is no longer the only way to
see more than one listing at a time.

Relevance ordering applies only when there is a term. Ranking an unfiltered
listing by `ts_rank` sorts it by a score every row shares.

`published_at DESC, id DESC` is always the final tiebreak. `ts_rank` produces
plenty of ties, and without a stable tiebreak two requests for the same page can
return different rows.

## What is still invisible

`Product::scopePublic()` decides, exactly as everywhere else. A draft, an
unapproved shop, a deleted listing: absent. **Search is not a way around
approval**, and there is a test that says so.

---

## A defect found while testing, and left in

The Snowball English stemmer reduces **"lens" to `len`** and **"lenses" to
`lens`**. It reads the trailing `s` of "lens" as a plural marker. The two never
meet, so **searching for a lens finds no lenses**.

On a marketplace that sells camera lenses that is one of the likeliest queries
there is.

It is left alone because every fix is a real decision rather than a tweak:

- A **synonym dictionary** is the correct answer and needs a file on the
  database server's filesystem, which is an operational commitment.
- Appending `:*` to the final term fixes this and turns the whole thing into
  prefix search, so "cam" matches "camera". That is a different product - good
  for search-as-you-type, worse for precision - and not a decision to make by
  accident while fixing a stem.

`SearchTest::test_the_stemmer_does_not_connect_lens_to_lenses` pins the current
behaviour, so whoever fixes it gets a failure and changes it deliberately rather
than rediscovering the quirk.

---

## Not yet decided

- **The stemmer gap above.** The first thing to revisit.
- **Searching shop names.** "Northlight Analog" finds nothing, because a
  generated column cannot reach another table. It needs either a denormalised
  copy of the shop name on `products` or a second query unioned in.
- **Sorting and filtering.** Price, condition, newest-versus-relevant. The
  export shows a search screen with filters and this endpoint has one -
  category. The rest arrive with the screen that needs them.
- **Suggestions and typo tolerance.** Neither is possible here without either a
  trigram index or the service this ADR declined.
- **Anything about what people searched for.** No logging, so there is no way to
  find out that "lens" returns nothing.
