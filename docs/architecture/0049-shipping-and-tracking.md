# 0049 - Shipping and tracking

Status: accepted - 2026-09-13

ADR 0019 inventoried four things the design export showed with no backend behind
them. Two were built (search, reviews) and this is the third: "shipping,
tracking - `shipped_at` and nothing else - no carrier, no tracking number".

`ShipOrder` said the same in its own words, and blamed the missing delivery
address. Addresses arrived with ADR 0021, so this is the other half of that
sentence.

---

## Two nullable columns, and everything follows from optional

```text
carrier           a known carrier, or nothing
tracking_number   what it is travelling under, or nothing
```

**Both optional, and that is the decision the rest hangs on.** A seller posting
an untracked letter must still be able to mark an order sent. Requiring a number
would either stop them or teach them to invent one, and an invented tracking
number is considerably worse than none: it sends the buyer to a carrier's site
to be told nothing exists.

**The dependency runs one way.** A carrier without a number is refused, because
the only thing it can produce is a link to a search for nothing. A number
without a carrier is kept and shown as text - somebody shipping with a courier
this marketplace cannot link to still has something the buyer can quote down a
telephone, and refusing it would push them into picking a carrier that is not
really carrying it.

Both rules are in the database as well as the request. `orders_carrier_check`
restricts the column to the enum exactly as `orders_status_check` does,
`orders_tracking_needs_shipping` keeps either from existing on an order that was
never sent, and `orders_carrier_needs_a_number` is where the one-way dependency
actually holds.

## A list of carriers, not free text

The whole reason to collect a number is to make it a **link**. A number a buyer
has to paste into a search engine is most of the way to useless, and a link
needs a known carrier - a string somebody typed is not one.

Membership asks the same question `PayoutCountry` asks: the platform is in
Finland and its shops trade around the Nordics and Europe, so the list is the
carriers those shops hand parcels to, plus the three globals that appear on
anything crossing a border.

**There is no `Other` case**, deliberately. "Other" is the absence of a carrier
this marketplace can link to, which is what `null` already means; a case for it
would be a value whose meaning is "ignore this value".

## The API answers where to follow it

`tracking_url` is published rather than assembled in the browser. A URL template
per carrier is a rule, and a copy of a rule in the frontend is the one that goes
stale the day a carrier changes its paths (root `CLAUDE.md` section 4). It also
means one place to fix when they do, and the number is encoded on the way in
because it ends up in an href.

The shop's own resource carries `carriers` - the list with the words to show for
each - in the same shape `PayoutAccountResource` publishes its countries. The
form that marks an order sent draws its options from the order it is already
looking at, rather than from a list copied into the frontend or an endpoint of
its own.

## A mail has one button

`MailMessage::action()` replaces whatever was there rather than adding beside
it, so asking for two kept the second and silently dropped the first - which was
the tracking link, the one thing this feature exists to deliver. Nothing failed;
the mail simply went out without it.

Only a test asserting on `actionText` caught it, which is the argument for
asserting on the button rather than on the prose around it.

Tracking wins where there is tracking, because it is what somebody opened the
mail to find. The order's page drops to a line below it and loses nothing.

## What the pages do

```text
the shop's order   "Mark as sent" opens a form, and the form may be empty
both timelines     the carrier and the number, on the step that says Sent
the buyer's mail   the link, as the one button a mail gets
```

**Asking at the moment of sending is the whole point.** The tracking number
exists in a seller's hand exactly when they mark the order sent; a separate
"add tracking" screen visited later is a screen nobody visits. So the button
that was one click becomes a form with two optional fields and a submit that
works empty - one extra click for an untracked letter, and the right moment for
everything else.

**The carriers come from the order.** `SellerOrderResource` publishes the list
with its labels, so a carrier added to the enum appears in the form without the
frontend knowing anything about carriers. The one thing the browser does hold is
what to _call_ each one on a timeline, which is a `Record` keyed by the enum -
so a carrier added there is a type error here until it has a name.

**An untracked parcel says nothing about tracking.** Not "no tracking": the step
already says it was sent, and a line that only ever announces an absence is a
line worth deleting.

## Testing

PHPUnit: a shop sends with a carrier and a number and the buyer is given
somewhere to follow it; the number is encoded into the link; an untracked parcel
is still sent and publishes all three as null; a carrier without a number is a
422 beside the field; a number without a carrier is kept and shown as text; a
carrier nobody has heard of is refused; the mail carries the carrier and the
link as its button; and the shop is given the carriers it may choose.

Vitest: the timeline names the carrier and number on a sent order, to each side
in the same words; a number given without a carrier still appears; and an
untracked parcel says nothing about tracking at all. The shop's form asks before
it sends anything, sends what was given, **marks an order sent with nothing
filled in**, and puts a refusal beside the field that caused it.

End to end: a shop marks an order sent through the form with a carrier and a
number, sees them on its own timeline, and the buyer finds the same sentence on
theirs - which is the whole feature, asserted in a browser rather than inferred
from two suites that each saw half of it.

---

## Not yet decided

- **Correcting a number after the fact.** It is captured at shipment and there
  is no way to fix a typo, which is a real support case and a second endpoint.
- **Delivery, as an event.** Nothing asks a carrier whether the parcel arrived;
  the buyer confirming is still the only signal, and auto-completion still runs
  on a clock rather than on a scan.
- **Shipping cost and labels.** The cost is written in
  [ADR 0057](0057-shipping-cost.md): a listing carries its own postage, a shop's
  parcel is charged once at the dearest thing in it, and the marketplace takes
  its fee on the goods rather than on the carriage. **Labels are still nothing**
  - buying postage and printing anything means carrier accounts and money moving
    the other way, which is the larger chapter this one meant.
- **More carriers.** Adding one is an enum case and a URL template, and worth
  doing when a seller asks rather than in anticipation.
