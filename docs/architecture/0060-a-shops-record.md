# 0060 - A shop's record

Status: accepted - 2026-09-15

Four ADRs closed with the same open item, and three of them gave the same
reason in almost the same sentence.

ADR 0052: "**A history of suspensions.** The columns are tied to the status, so
lifting one clears it: there is no record that a shop was ever suspended, which
is exactly what somebody deciding whether to suspend it again would want."

ADR 0054: "**A history of what a shop has had taken down.** Each removal is on
its listing, and nothing aggregates them - which is exactly what somebody
deciding whether to suspend the shop would want, and what ADR 0052 wanted for
suspensions too."

ADR 0059: "**A history of appeals.** Decided ones leave the queue and nothing
lists them, so 'has this shop argued about this before' has no answer - the same
gap ADR 0052 and ADR 0054 each left for their own decisions."

ADR 0051: "**A history of decisions.** The queue shows what is open, so a
resolved dispute is only visible on its order. Staff have no way to look back
over what the platform has decided."

One table closes all four.

---

## It cannot be derived, and that is the whole reason it exists

The obvious implementation is a read across what is already stored. It does not
work, because **reversing a sanction erases it**:

```text
ReinstateShop        nulls suspended_at, suspension_reason, suspended_by
DecideAppeal::lift   nulls removed_at, removal_reason, removed_by
```

and neither is careless. `sellers_suspension_is_whole` and
`products_removal_is_whole` tie those columns to a status as equivalences in
both directions, so a trading shop that still carried a suspension reason, or a
restored listing that still carried a removal, would be **refused by the
database**. Keeping them is not an option; it is a constraint violation.

So a shop suspended three times and reinstated three times is, in the schema,
indistinguishable from a shop that has never been stopped. The rows in
`platform_decisions` are written when each decision is taken, and nothing ever
touches them again.

Disputes, reports and appeals do keep their own rows - but each is keyed to its
subject through a morph, not to a shop, so "what has this shop had decided
against it" was still a question nothing could answer.

## Append-only, and enforced as such

There is no `updated_at` column, no endpoint that edits a row and no endpoint
that deletes one. `PlatformDecision::UPDATED_AT` is null for that reason rather
than for tidiness.

A record somebody can revise is not a record. The nearest thing to a write is
the `RecordDecision` action, which six other actions call and nothing else.

## Written inside the transaction that decides

Every call sits inside the `DB::transaction` of the action taking the decision.
That is the reasoning `DecideReport` already gives for taking a listing down
beside the report that ordered it: a decision without its record, or a record
with no decision behind it, is the one inconsistency nobody could explain
afterwards.

Six write points, and the pairing matters as much as the events:

```text
SuspendShop            shop_suspended
ReinstateShop          shop_reinstated
DecideReport           listing_removed
DecideAppeal           listing_restored, appeal_upheld, appeal_dismissed
ResolveDispute         dispute_refunded, dispute_released
```

**Recording only the sanctions would have been worse than recording nothing**,
because a record that says a shop was suspended and never says it was let back
is a half-truth that reads as a whole one. Every case comes in a pair.

`DecideAppeal` lifts a suspension by calling `ReinstateShop`, so the
reinstatement is recorded once, where it happens, rather than twice.

## What this forced, and it was worth forcing

`ReinstateShop::handle()` took only a `Seller`. **Nothing recorded who let a
shop back** - `suspended_by` names who stopped it and is then nulled - so
lifting a suspension was the one staff decision here with no attribution at all.
It now takes the member of staff, which both callers already had to hand.

## It is the shop's record, and a hidden review is deliberately not in it

Hiding a review is a decision about a **buyer's words**, not about the shop
whose listing they were left under. A record that counted it would show somebody
weighing a suspension three strikes that the shop's own customers had earned,
which is worse than showing them nothing.

So `review_hidden` is not a case in `DecisionKind`, and an appeal about a hidden
review records nothing either. That leaves those decisions unrecorded, and the
honest reason is the one `stripe_events` already gives: "Only events that are
acted on are recorded. Everything else is acknowledged and forgotten, and a
table of events nobody reads is not an audit log." There is no author-facing
record to read them from, so writing them would be rows with no reader.

`seller_id` is therefore not nullable. A decision with no shop to count against
has no reader here, and is not written.

## An appeal is recorded against what it argued about

Not against itself. The subject of an `appeal_upheld` row is the shop or the
listing, because that is what a reader wants named - and because pointing the
record at the appeal would make every reader resolve a morph through a morph to
find out what it concerned.

It also keeps the subject types to three: a shop, a listing, a dispute.

## What is published, and what is not

**Who decided is recorded and not published.** The column is there; the resource
does not carry it, for the reason a dispute's `resolved_by`, a suspension's
`suspended_by` and an appeal's `reviewed_by` are not: the decision is the
platform's rather than an individual's, and naming somebody invites the argument
to follow them.

`kind` is the enum, so the contract carries a union of the eight actual cases.
The words each is drawn with live in the frontend, exactly as `ReportReason`'s
do - that is presentation, not a rule.

`counts_against_the_shop` **is** the API's, and is its own field rather than
something the page derives from `kind`. Half of these are the platform deciding
in the shop's favour, and a record that counted those would answer "how many
times has this shop been in trouble" with the times it was cleared.

## Newest first, which no queue here is

The four staff queues are work to do, and every one of them puts the longest
wait first. This is a record, read by somebody about to decide something, and
what happened most recently matters most. It is the one list on this platform
ordered the other way, and that is deliberate rather than an oversight.

## Two things arrived with the page that needed them

**`SellerPolicy::view`.** That policy carried a standing note saying there was
deliberately no `view` method, because nothing called one, and that it would
"arrive with the endpoint that needs it". This is that endpoint. It is staff
without `review`'s second condition, because reading is not deciding and an
owner can already see everything in their own record through `/seller`.

**`GET /admin/sellers/{seller}`.** A page about one shop has to be able to name
it, and the queue was the only place staff could read a shop from - which would
have meant paging through a list to find a shop whose id is already in the URL.
`ShopReviewCard` used to say that no such endpoint existed and that the card was
why none was needed; that comment has been corrected rather than left standing.

## Testing

PHPUnit, 15 tests: **every sanction is driven through the endpoint that takes
it**, never written onto a model with `forceFill` - a fixture that sets the
columns directly records nothing, and every test would then pass while proving
nothing.

A suspension outlives being lifted, and the shop's own columns are asserted null
beside the two rows that remain; the reason the shop was given survives on the
record; a takedown outlives the appeal that undoes it, with the listing's
removal columns asserted null; a dismissed appeal is recorded and the listing
stays down; the record says which decisions count against the shop; **hiding a
review is on nobody's record**, and neither is an appeal about one; a dispute
refunded counts against the shop and one released does not; the record is
staff-only and a guest is refused rather than shown nothing; the exact key set;
who decided is stored and never published; and a deleted listing leaves the
record standing with its subject reported gone.

`PaginationTest` walks it with the other paginated endpoints, which is a list
maintained by hand - a new endpoint that is not added to it is one that test
quietly stops covering.

Vitest: the card names a decision in words rather than in the API's case, quotes
the reason, links a subject that has somewhere to go, leaves one that does not
as plain words, says when the subject has been deleted, and draws no quotation
when no reason was given.

---

## Not yet decided

- **A record for somebody who is not a shop.** Hidden reviews and appeals about
  them are unrecorded, as above. What is missing is an author-facing record, and
  until there is one the rows would have no reader.
- **Looking across shops.** ADR 0051 wanted a look back over "what the platform
  has decided", and this answers it one shop at a time. There is no list of
  every decision the platform has taken, which is what somebody auditing the
  moderators rather than the shops would want.
- **Approvals and rejections.** Not recorded, because nothing erases them:
  `reviewed_at` and `reviewed_by` survive a suspension and survive it being
  lifted. This table is for what would otherwise be lost, and duplicating a fact
  the shop already carries would give it two places to disagree with itself.
- **A tally the shop is weighed by.** The page counts what counts against the
  shop on the page it is showing, and nothing aggregates across pages or
  suggests a threshold. Automating "three strikes" is a policy decision nobody
  has taken.
- **Retention.** Nothing ever deletes one, and no rule says what happens to a
  record when an account closes (ADR 0058) or how long it is kept.
