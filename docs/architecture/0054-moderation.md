# 0054 - Moderation

Status: accepted - 2026-09-14

ADR 0052 closed with a gap it named precisely: "A single listing cannot be taken
down, and a review or a message cannot be removed - so the only tool against one
bad listing is closing the business. Moderation at that grain is the obvious
next chapter." ADR 0047 listed the same gap from the other side: "Nothing flags
a review, and staff have no endpoint that touches one."

This is that chapter. It gives the marketplace a way to act on one bad thing
instead of one bad shop.

---

## Two halves, and the order between them matters

```text
anybody signed in   reports a listing or a review, and nothing happens to it
platform staff      decide, and upholding is what takes the thing down
```

**Reporting is not accusing.** A report changes nothing about what it points at:
the listing stays on sale and the review stays on the page until a person
decides. The alternative - hiding on report, even temporarily - would hand
anybody with two accounts the power to close a competitor's shop window for as
long as a queue takes. A marketplace where a rival can turn your listings off is
worse than one where a bad listing survives an afternoon.

That is the whole reason the decision is a separate step with a separate
audience, rather than a threshold of reports doing it automatically.

## What can be reported is what can be seen

Both public endpoints resolve their subject through the storefront's own scopes -
`Seller::scopePublic()`, then `Product::scopePublic()` - rather than fetching a
row and checking it afterwards.

```text
a draft                         404
a listing in an unapproved shop 404
a suspended shop's listing      404, through the same scope as ADR 0052
a review already hidden         404
```

Three things follow for free, and none of them is written down as a rule:

- **Nobody can report what is not in front of them**, so reporting cannot be
  used to probe for what exists.
- **A hidden review cannot be reported again**, because `visible()` is in the
  lookup.
- Suspension propagates here on the day it was written, because this asks the
  same scope everything else asks.

It is the shape this codebase keeps choosing: a condition carried by the query
cannot be forgotten by the next endpoint (ADR 0008).

**There is no `ReportPolicy::create`.** Anybody signed in may report anything
public, so a method that could never refuse would be a rule nobody applies. What
stops a report is the lookup, long before a policy would be asked.

## Upholding a report is the takedown

There is deliberately **no endpoint that removes a listing on its own**. Every
removal answers a report, which means every removal has a reason somebody gave
and a decision somebody recorded. A takedown with no report behind it would be
the one moderation action with no trail.

```text
a listing   status back to Draft, published_at cleared, and removed_* stamped
a review    hidden_* stamped; the row stays, and its author still sees it
```

**Neither is a delete**, and for different reasons in each case.

A listing keeps its order history, because receipts reference it and ADR 0011
already froze what was agreed onto the order. A review keeps its row so that its
author keeps their one-per-listing slot - otherwise hiding somebody's review
would quietly hand them a fresh one, which is moderation undoing itself. There
is a test for exactly that, and it has to earn the review through a real
completed order or it passes without exercising the rule.

**Hiding is the answer ADR 0047 was missing.** That ADR refused deletion because
"who may erase one is an argument between two parties this codebase cannot
hear". The platform is the third party it did not have. The author keeps their
words and may still edit them; nobody else sees either version.

## A takedown is sticky, which is the point

`products_removed_is_not_published` refuses a removed listing that is still on
sale, and `PublishProduct` refuses to republish one. Without that the seller
puts it back a minute later - exactly the hole suspension had to close for shops.

The check is **first** in `PublishProduct`, ahead of the approved-shop and
category rules, and the order is load-bearing: those two are refusals the seller
can fix by waiting or by choosing, and this one is not. Telling somebody to pick
a category for a listing that will never go back on sale sends them to fix the
wrong thing.

**`can_publish` stays true on a removed listing**, and that is the existing
design rather than an oversight. `ProductPolicy` is ownership, and it
deliberately keeps facts about the world out of itself - an unapproved shop is
refused the same way, as a 409 from the action rather than a 403 from the policy.
What the seller's view gains instead is `was_removed_by_staff` and
`removal_reason`, because a button that will never work and no explanation is
a shop owner with nothing to fix and nothing to appeal.

## The morph, and what it costs

`reports` is the first `morphTo` in this repository. A listing and a review are
both reportable, and a message is named as the next candidate, so the
alternative was two nullable foreign keys today and three tomorrow.

**A morph carries no foreign key**, which is a real consequence rather than a
theoretical one: a seller may delete a flagged listing between the report and
the decision. So `subject` is nullable in the resource and the queue says the
thing has gone rather than rendering a blank row - a shop deleting what it was
reported for is itself worth knowing. Upholding one whose subject is gone is a
409; dismissing it is fine, because there is simply nothing left to uphold.

It also cannot be eager-loaded through in one query, so the queue names the
nested relations per type with `loadMorph`. That runs after pagination rather
than as a closure inside `with()`, because such a closure has to narrow its
parameter to `MorphTo` and so cannot satisfy a signature promising any
`Relation` - and it is reached through `getCollection()`, since the paginator
only forwards it by `__call`, the same forwarding Scramble cannot follow.

## One open report per reporter per thing

A partial unique index, `WHERE reviewed_at IS NULL`, enforced by the database
rather than by a read before the write - for the reason `LeaveReview` gives:
two requests arriving together both find nothing and both insert, and the
database is the only party that can see both.

**Open reports only**, deliberately. A listing that was fine in March may not be
in June, so somebody whose first report was dismissed is not barred from the
subject forever. Two different people reporting the same thing is not merely
allowed, it is the signal the queue exists to collect.

## Who is told, and who is not

```text
the owner     hears, and hears why, when their thing comes down
the reporter  hears nothing, ever
```

Telling the reporter the outcome would make every dismissed report an argument
and every upheld one a scoreboard. The decision is the platform's, and the
reporter's part ended when they said their piece.

`reviewed_by` is recorded and **not published**, for the reason a dispute's
`resolved_by` and a suspension's `suspended_by` are not: naming a member of
staff on a decision invites the complaint to follow them personally.

The note is required either way. On an upheld report it becomes the reason the
seller or the author is sent; on a dismissed one nobody is written to, but it is
what the next member of staff reads when the same thing is reported again - a
queue whose dismissals say nothing makes everybody re-decide from scratch.

## The reasons are a short list, and `other` needs words

`Counterfeit`, `Prohibited`, `Abusive`, `Spam`, `Other`. The reason is what
sorts a queue, and for most reports it is the whole of it. `Other` is the one
case that carries no meaning on its own, so a note is required with it -
otherwise the queue fills with "something else" and nothing after it.

## Messages are reportable, and not removable

Deliberately, and this is a decision rather than a gap.

An order's conversation is **dispute evidence** (ADR 0050, ADR 0051). Staff
deciding where held money goes read it to decide, so a message that could be
removed is evidence that could be removed by the party it incriminates. The
thing to build there is a report that reaches staff without deleting anything,
and it is not built here because the queue and the decision it needs are the
ones this ADR just wrote.

## The trap this one set

**`visible()` had to be said twice.** `Product::reviews()` applies the scope, so
the rating average, the count and both of their fallbacks exclude a hidden
review without any of them mentioning hiding. But the public reviews list starts
from `Review::query()` rather than from the relation - deliberately, because a
scope reached through a relation forwards via `__call` and Scramble publishes
the endpoint as unpaginated (ADR 0022, and the `meta` lesson in `PaginationTest`).

Starting from the model to keep the contract honest also steps around the scope.
Without `->visible()` spelled out there, hiding a review would take it out of
the average and off the product page and leave it sitting in the reviews list -
the takedown silently not working on the one screen it most needs to. It was
found by reading the endpoint beside the model, not by a failing test, which is
why it is written here.

## Testing

PHPUnit: somebody signed in reports a listing and a review, and a guest gets 401;
reporting the same thing twice is refused and somebody else may still report it;
a dismissed report can be made again; `other` needs words; **nothing happens to
the listing when it is reported**; a draft, an unapproved shop's listing, a
hidden review and a review quoted under the wrong listing are each 404; the queue
is staff-only, lists open reports oldest first, and loses them when decided;
upholding takes a listing off sale and off the storefront and it cannot be
published again; upholding a review hides it from the list and from the rating,
and **its author still cannot leave a second one**; dismissing changes nothing;
deciding twice is 409; staff cannot decide a report they made; the owner is told
and the reporter is not; and both resources answer whether the viewer may report.

---

## Not yet decided

- **Reporting a message.** Named above as a decision rather than a gap, but the
  report endpoint for one is not written.
- **A history of what a shop has had taken down.** Done in
  [ADR 0060](0060-a-shops-record.md), with the identically worded items ADR 0051,
  ADR 0052 and ADR 0059 left. A takedown is recorded against the shop when it is
  taken, which matters because an upheld appeal clears `removed_at`,
  `removal_reason` and `removed_by` - so the listing itself forgets entirely.
  **Hiding a review is deliberately not on that record**: it is a decision about
  a buyer's words, and counting it would show a shop strikes its own customers
  had earned.
- **Appeals.** Done in [ADR 0059](0059-appeals.md), which closed this and the
  identically worded item ADR 0052 left, with one mechanism. A seller whose
  listing came down, and an author whose review was hidden, can now answer back
  against the reason they were given.
- **Undoing a takedown.** Also done in [ADR 0059](0059-appeals.md), and done
  _through_ appeals rather than beside them. Upholding an appeal is the only
  reversal there is: clearing a removal or a hiding has no other endpoint, so
  every reversal answers somebody's argument and carries a decision somebody
  recorded - the same accountability this ADR gave the takedown itself. A
  reinstated listing comes back as a **draft**, because the removal is what
  `PublishProduct` refuses on; restoring the seller's ability to sell the thing
  is not the same as making that choice for them.
- **Reporting a shop, rather than its listings.** Suspension exists and is
  staff-initiated; nothing lets a shopper say the whole shop is wrong.
- **Rate limiting reports.** Done in
  [ADR 0056](0056-buying-from-your-own-shop.md): ten an hour by account, on both
  report endpoints. The partial unique index still bounds reporting the same
  thing twice; this bounds the traffic, which is what falls on the moderator.
