# 0022 - What a page looks like

Status: accepted - 2026-09-11

Seven endpoints paginate. Nothing had ever said what a page is, so each one was
publishing Laravel's default envelope, and the default envelope cannot be
published by this API.

This was found by reading the generated types before starting the frontend,
which is the first time anything had looked.

---

## What was being sent

```json
"links": { "first": "http://0.0.0.0:3000/api/v1/search?page=1", "next": null },
"meta":  {
    "path": "http://0.0.0.0:3000/api/v1/search",
    "links": [{ "url": null, "label": "&laquo; Previous", "active": false }]
}
```

**`0.0.0.0:3000` is where the Next.js server binds inside its container.** It
reaches the paginator because the proxy forwards its own host and
`trustProxies(at: '*')` believes it, and it went to every browser that asked for
a list. Root `CLAUDE.md` section 9 forbids publishing an internal hostname in as
many words; section 4 says the API's own address is server-side only. This was
doing both, six times per response.

It is also useless. No browser can reach that address, so a client following
`links.next` would get nothing.

## Four numbers, and no URLs

```json
"meta": { "current_page": 1, "last_page": 4, "per_page": 24, "total": 87 }
```

**The API does not know the origin a browser reached it on, and must not guess.**
The browser calls relative `/api/v1/...` paths on the Next origin and the proxy
forwards them (ADR 0003). `ProductImage::url()` reached exactly this conclusion
for images and wrote it down; this is the same rule applied to the one other
place that was building URLs.

The client already knows the path, because it made the request. It asks for the
next page by putting `?page=` on the one it used.

`meta.links` is gone as well, and for a different reason: an array of
`&laquo; Previous` labels with an `active` flag is Blade pagination markup
expressed as JSON, and this application renders nothing. `from` and `to` are
gone as arithmetic over the four that remain.

`PaginatedCollection` is the only definition. A list endpoint extends it or it
is not paginated, and there is a test that walks every one of them.

## `page` had to be declared

The response now says a set has four pages. Nothing said how to ask for the
second one: Laravel reads `page` straight off the request rather than through a
form request, so no code mentions it and Scramble had nothing to read.

A `#[QueryParameter]` on each paginated action, with the description held once
on `PaginatedCollection` so seven copies cannot disagree. **Half a contract is
worse than none**, because the half that is present looks complete.

Out of range is an empty set rather than a 404. A client asking for page 40 of 4
has usually just had something deleted underneath it, and that is an ordinary
race rather than an error screen.

---

## Checkout is not a page

`OrderCollection` served both the buyer's history and the response to checkout,
on the grounds that they were the same shape. They stopped being the same shape
the day a page grew a `meta`, so checkout got its own `PlacedOrderCollection`.

The distinction is real rather than technical. A history is a window onto a list
that continues; a checkout response is **every** order the button produced, and
a client that received half a basket and had to ask for the rest could not draw
a confirmation. A basket spans a handful of shops, so there is no size for a
page to protect against.

---

## The contract can be self-consistent and still untrue

ADR 0006 says `make api-check` proves the committed document is what the code
generates, and lists "it does not validate responses at runtime" under what it
does not do. This is that gap, arriving.

Six endpoints picked up the new envelope and one did not. `GET
/shops/{slug}/products` reached its scope through a relation -
`$shop->products()->public()` - which goes via Laravel's `__call` forwarding,
and Scramble cannot follow that. The chain came out untyped, so the document
called a paginated listing a plain array. Both halves were internally
consistent. The frontend was still being told the wrong thing.

The controller now starts from `Product::query()` and filters by `seller_id`,
which is what the category listing already did - the same kind of endpoint,
filtered by a different dimension - and keeps the scope on a builder where the
reader and the generator can both see it.

The test that covers this reads `openapi.json` and asserts the document agrees,
and it was checked by putting the defect back: the runtime tests still passed
and only the contract test failed, which is the point of having it.

---

## Not yet decided

- **Cursor pagination.** `LengthAwarePaginator` counts the whole set on every
  request. That is fine at this size and is the wrong tool for an infinite
  scroll over a large catalogue, which is what the design export's search screen
  implies.
- **A client-chosen `per_page`.** Fixed per endpoint, between 20 and 25. A
  client that could ask for 10,000 has a denial of service.
- **Sorting.** Every listing has one order, decided by the endpoint. Nothing
  takes a `sort` parameter.
- **Total counts leaking.** `total` on the review queue tells staff how many
  applications exist, which is fine, and any future count over somebody else's
  data needs the same question asked (ADR 0011 raises it for co-purchase).
