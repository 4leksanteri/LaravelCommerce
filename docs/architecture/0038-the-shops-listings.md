# 0038 - The shop's listings

Status: accepted - 2026-09-12

A shop's catalogue, writing a listing up, and everything that is edited on one
afterwards: what it says, how it is sold, its photographs, and whether it is on
sale. The API for all of it has existed since ADR 0009 and ADR 0016; this is its
frontend, and one addition to the catalogue listing.

---

## Three pages

```text
/seller/listings              the catalogue, newest first, narrowed by status
/seller/listings/new          writing one up
/seller/listings/[listing]    one listing, in four parts
```

The id in the address is the product's id, because that is what the seller
endpoints bind. The slug is the shop's public address for it and is not moved
when the listing is renamed, so it is a poor key for a page that renames things.

**The catalogue narrows by status**, the third queue to do so after the shop's
orders (ADR 0036) and the review queue (ADR 0037). "Drafts" is the half of a
catalogue that still needs work and "On sale" is what shoppers can find.

**The API changed for it**: `GET /seller/products` took no status at all, and
now takes one through a form request, with an unknown status refused as 422
rather than ignored. The page turns that back into the whole catalogue, since
only a hand-edited address produces one.

## One option at creation, the rest afterwards

The API requires at least one variant when a product is created, because a
product with none has no price and cannot be bought. It accepts many; the form
offers one.

Somebody writing a listing is describing a thing. Sizes, colours and straps are
easier to get right against a saved listing - where each row saves on its own
and a mistake costs one request - than in a form that grows while it is being
filled in and saves everything at the end.

## The listing's page is four separate writes

What it says, how it is sold, its photographs, and whether it is on sale. Each
section saves on its own, because each is a different endpoint: `PATCH
/seller/products/{id}`, the variants, the images, and the publication.

One Save across the lot would have to decide what to do when the third write
failed after the first two had gone through - and the honest answer, that some
of it saved, is worse than four buttons that each mean one thing.

## A price is typed in major units and sent in minor

`price_minor` is what the API stores and nobody types 2499 for 24.99. So this
is the one place in the application where the browser turns what somebody typed
into money, and `parseMoney` does it **on the string**: `24.99 * 100` is
2498.9999... in binary, and a `Math.round` over the top would hide that until
the day it did not.

This is not the frontend computing money (ADR 0004, `apps/web/CLAUDE.md`
section 8). Nothing is totalled, converted or discounted - a price is read from
a field and passed through. `moneyInputValue` does the reverse for an edit form,
and a round trip through both is tested.

**A price that cannot be read is refused here**, which is the only refusal on
these pages that is not the API's. There is no figure to send, so there is no
request to make; the message sits beside the field like the API's own, and both
appear together when both have something to say. Both separators are accepted,
because a keyboard in Helsinki writes 24,99.

## Publishing is the API's answer, and the hint is not a rule

`can_publish` is ownership and nothing else. Whether the listing may actually go
on sale - an approved shop, a category chosen - is a fact about the world rather
than about the person, so the API answers with a 409 and its message is shown as
it was sent (ADR 0008).

The category field's hint says a category is needed before a listing can go on
sale. That is guidance, not enforcement: nothing is disabled, nothing is checked
here, and a listing with no category still offers the button and still gets the
API's refusal.

**Taking it off sale keeps the listing.** Deleting asks first and is a soft
delete: the row stays so that order history keeps something to point at, and
nothing in this application brings it back.

## Photographs

Uploaded as `FormData` through the same `apiFetch` as every other write, with
**the content type deliberately not set** - the browser writes it, with the
boundary, and setting it by hand produces a body the API cannot parse. CSRF and
the session are handled where they always are (ADR 0003).

**The API owns what an image is.** Type, size and the limit per listing are its
rules: a file it will not take is a 422 beside the field, one photograph too
many is a 409 with its own message. The limit is repeated on the page so
somebody is told before they are refused, and enforced only by the API.

**A draft's photograph is served against a signature that expires** (ADR 0016),
and those must not go through `next/image`: the optimiser caches by URL and
would keep serving one after its signature had died. `next.config.ts` already
refuses any URL with a query string; these are rendered unoptimised, from the
signed URL itself, and stop working exactly when the signature does.

The alt text is saved on its own, because it is usually written after the
upload, and an empty box is sent as null - "nobody wrote one" rather than "this
photograph is decorative".

## Somebody else's listing is not found

The API answers 403 for a product belonging to another shop, which is a
deliberate choice about ids (ADR 0008). This page draws the not-found page for
that and for an id that never existed, because to this seller they are the same
thing.

## Testing

PHPUnit: the catalogue narrows to one status, and an unknown status is refused.
Everything else these pages do was already covered.

Vitest: the price conversion in both directions, including a round trip and the
refusals; the create form's request body; the variants editor saving minor
units, adding an option and showing the API's 409 for the last one; the image
upload being multipart with no content type, the 409 for the limit, the 422
beside the field, and null for an empty description; publishing, unpublishing,
the 409, and deleting after a question.

Playwright: one journey - write a listing up, add an option, upload a real
one-pixel PNG, put it on sale, follow it to the storefront, take it off again -
plus the unreadable price, the filters, deleting through the page, another
shop's id, and phone width with axe. Everything created is deleted in a
`finally`, because this runs against the demo shop other specs buy from.

---

## Not yet decided

- **Reordering photographs.** `position` exists on the API and nothing here
  sets it: the first upload is the cover. Dragging a gallery is a real piece of
  work and the API is ready for it.
- **Reordering options.** The same, for variants.
- **Editing stock from the catalogue.** Stock is on the listing's own page, so
  correcting six of them is six pages.
- **Seeing a draft as a shopper would.** The link appears only once a listing is
  public; there is no preview.
- **Searching the catalogue.** It is paged and unsearchable, which is fine at 25
  listings and not at 500.
- **Bulk anything.** No multi-select, no publish-all.
