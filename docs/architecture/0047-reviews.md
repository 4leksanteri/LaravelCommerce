# 0047 - Reviews

Status: accepted - 2026-09-13

The design export has twenty-eight references to ratings and no domain behind
any of them (ADR 0019). This is the domain.

---

## A review is about the listing, and earned by an order

```text
who      somebody with a completed order containing it
what     a rating of one to five, and words if they have any
how many one per buyer per listing, forever
```

**It hangs off the product, not off the line that bought it.** An order line
points at a variant, and that pointer is nulled when the seller deletes it
(ADR 0011) - so a review attached to one would lose its subject the day
somebody tidied their catalogue. A review is about the thing, and outlives
whichever option the buyer happened to choose.

**Completion is what earns it, and paid is not enough.** A card is charged
before anything is posted. Completion is the buyer confirming the parcel
arrived, and it is the event that releases the money to the shop (ADR 0014), so
it is the strongest statement this domain has that a person actually received
what they are about to talk about.

That makes **verified purchase structural rather than a badge**. There is no way
to write a review here without a completed order behind it, so there is no
unverified kind to distinguish it from, and `ReviewResource` publishes no
"verified" flag because every one of them is.

**The entitling order is stored on the review.** Re-deriving it later would let
a seller make a standing review look unearned by deleting a variant, since the
line's link to the catalogue is the nullable one.

## Two refusals, both 409

```text
nothing you received is this   409
you have already said your piece 409
```

Neither is a 403. A buyer is entitled to review what they buy, and what is in
the way is where their orders have got to - the same distinction ADR 0008 draws
for publishing a listing from an unapproved shop. Answering 403 would tell
somebody they are barred from something a completed order lets them do
tomorrow.

Both are a `DomainRefusal`, so neither is written to the log (ADR 0045). Being
told "you have already reviewed this" is the marketplace working.

The second is enforced by the unique index rather than by a read before the
write. Two requests arriving together both find nothing and both insert, and the
database is the only party that can see both.

## Rewriting is allowed, deleting is not

A verdict that cannot be corrected is one people hesitate to leave, and the
rating somebody gives in their first week of owning a thing is often not the one
they would give in their third. So `PATCH` replaces the whole verdict - which is
also why the body stays optional on an update: "no words" has to be able to mean
"I have removed what I said".

Nothing is stamped to record the change. `updated_at` moving past `created_at`
says it, and a reader is told: a review that has been rewritten is a different
thing from one that has stood since the day it was left.

**Deletion is deliberately absent.** A shop whose worst review can be argued
away is a shop whose ratings mean nothing, and who may erase one is an argument
between two parties this codebase cannot hear. It stays open rather than
guessed at.

## The rating is an aggregate, carried by a scope

`Product::scopeWithRating()` adds the average and the count in one join, and
**every public query applies it**: the shop's storefront, the listing's page,
search and a category. A card shows a rating, so a page of twenty-four cards
would otherwise be twenty-four counts.

Reading it back distinguishes two things that both look like null:

```text
attribute absent   the query forgot withRating() -> ask the database
attribute null     nobody has reviewed it        -> say so
```

A page that dropped the scope would otherwise publish "no reviews" about a
listing with forty. The fallback is a real query, so the answer is never wrong -
only slower, and only where somebody forgot.

## What the caller is told about their own standing

`can_review` and `your_review` are on the listing's **own page** and nowhere
else. Nobody reviews from a grid of cards, so a list answers `false` and costs
nothing; the page asks properly, in one query.

Both are the API's answers rather than rules the browser re-derives - it cannot
see somebody's order history, and a copy of "completed, containing this, not yet
reviewed" in the frontend is the copy that goes stale (root `CLAUDE.md`
section 4).

## The author is shortened

A product page is public and indexable. A full legal name published against a
purchase is an exposure nobody opted into by buying a camera, so a review shows
"Aino V." - enough to tell two reviewers apart, which is all the name is for
here.

## Two traps the generator set again

**`can_review` published as a string, and the documented remedy did not fix
it.** It was read straight off a promoted constructor property, which the
generator could not resolve, so it guessed `string` - exactly as `can_edit`
once did. `apps/api/CLAUDE.md` section 8 prescribes a private method with a
declared `: bool` return type for this, which is what `OrderResource` uses for
`can_pay`. That was applied here and changed nothing.

The difference appears to be what the body returns. `OrderResource::canPay()`
returns a call on a typed property, which the generator follows; this one
returned a promoted constructor property, which it seemingly does not. The
declared return type is not what it reads.

**`rating` and `your_review` published as always-present**, because a declared
return type carries no null either - the same three-field lesson ADR 0043 hit.

So the rule earned across both ADRs is one rule: **the `/** @var */` annotation
is the mechanism that carries a type into the contract.** A declared return
type is a convention worth keeping for readers, and it happens to work often
enough to look like the mechanism, which is how it got written down as one.
Check the generated output rather than trusting either.

## What the pages show

```text
a card             the rating, and nothing when there is none
the listing        the average, the reviews, and the form when it is earned
the form           five radios, words that can be removed
```

**A listing nobody has reviewed draws no stars.** An empty row of grey ones
reads as "rated zero" rather than "not rated", which is a worse lie than saying
nothing - so `RatingStars` renders nothing and the page says the sentence
instead.

**The number is the truth and the stars are the impression.** Five shapes
cannot say 4.3, so the figure is always written beside them. The stars are
drawn as SVG rather than typed as a glyph, because source here is ASCII (root
`CLAUDE.md` section 15) and a star character would fail `make charset`.

**The rating token arrives now**, which is what ADR 0019 said would happen: it
gets its own name when reviews exist and not before. It starts as the same
amber `caution` is, because that is the export's rating hue, and it is named
for its job so a star can stop looking like a warning later.

**The form is five radios rather than clickable stars.** Real inputs with real
labels are reachable by keyboard and announced as "3 of 5" without any of the
work a custom star widget needs to be accessible.

Whether the form appears at all is `can_review`, and it is the API's answer.
The browser cannot see an order history, and a copy of "completed, containing
this, not yet reviewed" in the frontend is the copy that goes stale.

## Testing

PHPUnit: somebody who received an order can review it and somebody who did not
cannot; an order that has only been sent does not earn one; a second review is
refused; words are optional and a rating outside one to five is not accepted;
the author can rewrite and nobody else can; the listing and its cards publish
the average and the count, and a listing nobody has reviewed says so rather than
zero; the reviews read publicly; and the listing tells a buyer whether they may
review it and what they already said.

The average is asserted at **4.5** as well as at a whole number. A whole average
hides the one bug worth catching here, which is an aggregate rounded to an
integer somewhere between PostgreSQL and the page.

## The demo catalogue writes some

`make seed-demo` leaves seven reviews across four listings, and **each one is
earned the way a real one is**: a completed order, built with factories, and then
the review written through `LeaveReview` itself - so the entitlement rule is
exercised rather than stepped around. A row inserted straight into `reviews`
would be demo data the application has no way to produce, which is the same
argument the seeder already makes for putting its photographs through
`StoreProductImage`.

Factories rather than checkout, because checkout would take stock that the same
seeder has just put back and would call Stripe on every run - and `make seed-demo`
runs before every `make e2e`. Nothing here reaches the network: completion
releases the money through `TransferToShop`, which returns early for a shop with
no payout account, and no demo shop has one.

The reviews come from **invented buyers rather than the demo shopper**, whose own
review the end-to-end suite leaves and then rewrites on every run after. There is
one review per buyer per listing, so a seeded one under that account would take
the only review it is allowed and leave the suite nothing to write.

Most of the catalogue is left unreviewed deliberately, and one review is a rating
with no words. A listing nobody has bought yet is the ordinary case and has to
look right too.

---

## Not yet decided

- **Shop ratings.** A shop's own average across its listings is the obvious next
  aggregate and a different question - one bad listing is not one bad shop.
- **A seller's reply.** Every marketplace has them, and they need their own
  rules about who may edit what afterwards.
- **Reporting and moderation.** Nothing flags a review, and staff have no
  endpoint that touches one.
- **Sorting and filtering by rating.** Search ranks by relevance and browse by
  newest; neither knows what anything is rated.
