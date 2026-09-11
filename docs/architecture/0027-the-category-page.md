# 0027 - The category page

Status: accepted - 2026-09-11

The second page the header linked to that is no longer a dead end, and the one
every category card on the home page leads to.

---

## A place, not a filtered search

`/search?category=audio` returns the same listings as `/categories/audio`, and
the category is still its own page, because the two are different things.

A search result is one of an unbounded number, changes by the hour and is
`noindex` (ADR 0026). A category is durable. It has an address worth sharing and
worth a search engine keeping, a breadcrumb that says where it sits, and the
subcategories beside it. The API already gave it its own endpoint for the same
reason (ADR 0017), and this page is the thing that endpoint was for.

The two meet in one place: a "search in Audio" box, which hands over to the
search page with the category already chosen. `q` comes first in that form, so
the address it produces is the one `searchHref` would build for the same search.

## The API decides whether a category exists

An unknown slug is a 404 from `GET /categories/{slug}/products`, and becomes
this application's not-found page. The page does not look the slug up in the
category tree to decide - that would be a second answer to a question the API
has already answered.

The tree is still fetched, for the name, the breadcrumb and the subcategories.
If it fails to load while the listings succeed, the page renders them under a
plainer heading rather than calling a real category missing.

Both `generateMetadata` and the page need the tree. `serverFetch` opts out of
Next's fetch cache on purpose, because it carries a session, so the tree is read
through React's `cache` - one request to the API per page, not two.

## Sideways is one click, up is the breadcrumb

A top-level category shows its children as pills. A subcategory shows its
siblings, itself among them, so moving from Headphones to Turntables is one
click rather than a trip back up. Going up is what the breadcrumb is for, so the
pills do not also offer it.

The API includes everything beneath a category (ADR 0017), so Audio already
lists the turntables and headphones filed under its children.

---

## Built with what search taught

The listings sit under a visually hidden `h2` from the start. `ProductGrid` says
it belongs under one, because every card is an `h3` and the search page failed
axe's heading-order check for leaving that out (ADR 0026).

Two things were extracted at their second caller rather than copied: `PillLink`,
the pill the search filters already used, and `listingCount`, which words the
API's `meta.total`. `categoryHref` never writes page 1, for the reason
`searchHref` does not.

Its specs and checks passed on their first run: every listing under Audio
including its subcategories, narrowing to Headphones and back up by the
breadcrumb, search within, the empty Bicycles category, a 404 for a category that
does not exist, and all three category pages at phone width and through axe.

---

## Not yet decided

- **Deeper trees.** Categories are two levels at most (ADR 0017), so a
  breadcrumb of three links and one row of siblings is the whole design. A third
  level would need both rethought.
- **Descriptions.** A category is a name. A landing page worth indexing would
  usually say something about what is in it, and there is nowhere to put that.
- **Canonical addresses for later pages.** Page 2 of a category is indexable
  like page 1, with no `rel=canonical` saying how they relate.
