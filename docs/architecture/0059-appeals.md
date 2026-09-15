# 0059 - Appeals

Status: accepted - 2026-09-15

Two ADRs closed with the same open item, worded almost identically.

ADR 0052: "**Appeals.** A shop reads why it was stopped and can reply to
nothing." ADR 0054: "**Appeals.** A seller reads why their listing came down and
can reply to nothing. It is the same gap ADR 0052 left, and the same machinery
would close both."

It was the same gap, and one mechanism closes both. ADR 0054 left a third item
that turns out to be the same thing again - "**Undoing a takedown.** Upholding
is one-way: there is no endpoint that puts a listing back or unhides a review,
so a mistake needs a database" - and this closes that too, by making the
reversal something an appeal earns rather than a button beside the takedown.

---

## Two halves, and the order between them matters

```text
whoever was stopped   argues, and nothing about the sanction changes
platform staff        decide, and upholding is the only reversal there is
```

**Raising an appeal changes nothing.** The shop stays suspended, the listing
stays down, the review stays hidden. The alternative - suspending the sanction
while somebody looks - would make appealing a free way back, and every
enforcement decision would be appealed the moment it landed. It is the mirror of
the rule ADR 0054 built reporting on: saying something is wrong is not the same
as it being wrong, whichever direction the claim travels.

That symmetry is the whole design. Moderation lets anybody say a thing should
stop; appeals let the person it stopped say it should not have. Neither
changes the world on its own, and both are decided by somebody else.

## Only against something that is actually stopped

```text
a shop      suspended            (ADR 0052)
a listing   removed by staff     (ADR 0054)
a review    hidden by staff      (ADR 0054)
```

`RaiseAppeal` asks the model - `isSuspended()`, `wasRemovedByStaff()`,
`isHidden()` - rather than reading columns, so there is one definition of
"stopped" per thing and this is not a second one.

A listing nobody removed and a shop that is trading have no decision behind them
to argue with, and an upheld appeal against one would have nothing to lift. That
is checked here rather than assumed from the route, because the route proves
ownership and this proves there is a case.

## Three endpoints, and that is the whole of the authorization

```text
POST /seller/appeal                                      the shop the middleware gave us
POST /seller/products/{product}/appeal                   a listing the policy says is theirs
POST /shops/{shop}/products/{listing}/reviews/appeal     the review they wrote
```

Each resolves its subject through the caller's own relations, so there is
nothing a caller could substitute to appeal on somebody else's behalf. One
endpoint taking a type and an id would have needed a policy to re-establish
exactly what these three lookups establish for free (ADR 0008).

It is why **`AppealPolicy` has no `create`**: a method there could never refuse
anything, and a rule nobody applies reads as though it applies.

Two details in those lookups are load-bearing:

- **The shop endpoint sits behind the `seller` middleware**, which admits a
  suspended shop deliberately (ADR 0052). A shop that has been stopped still
  owes what it sold, and now also needs somewhere to argue from.
- **The review endpoint does not resolve through `scopePublic`**, unlike every
  other route taking those two slugs. A review is worth appealing precisely when
  its listing has been removed or its shop suspended, and neither is public any
  more - resolving through the storefront's rules would answer 404 exactly when
  somebody needs the endpoint most. It is resolved in typed steps instead of a
  `whereHas` closure, where the analyser is handed an ungeneric `Builder` and
  cannot check a column name.

## Upholding is the only undo this platform has

Nothing else puts a listing back or unhides a review. That is a deliberate
consequence rather than an omission: every reversal therefore answers somebody's
argument and carries a decision somebody recorded, which is the same
accountability ADR 0054 gave the takedown when it refused to build a bare remove
button.

```text
a shop      reinstated, through ReinstateShop, which already does exactly this
a listing   removal cleared - and left a draft, not put back on sale
a review    unhidden, and visible again wherever it was
```

**A listing comes back as a draft.** The removal is what `PublishProduct`
refuses on, so clearing it restores the seller's _ability_ to sell the thing;
putting it back on sale would be the platform making a shop's decision for it,
about a listing whose owner may well have moved on in the weeks it waited. The
mail says so in as many words, because a seller who checked the storefront and
did not find it would reasonably conclude the appeal had failed.

The lift happens **inside the transaction with the decision**. A reversal that
happened without the decision authorising it is the one inconsistency here
nobody could explain afterwards - the same reasoning `DecideReport` gives.

## One open appeal per appellant per thing

A partial unique index, `WHERE reviewed_at IS NULL`, enforced by the database
rather than by a read before the write - for the reason `ReportContent` gives:
two requests arriving together both find nothing and both insert, and the
database is the only party that can see both.

**Open ones only.** Somebody whose appeal was dismissed may raise another; they
may have found the paperwork since. What the index stops is the same argument
queued twice, not the argument itself.

## The morph, and what it costs a second time

`appeals` is the second `morphTo` here, and it carries the same consequence
ADR 0054 recorded: **no foreign key**, so the thing being appealed about can be
deleted while the appeal waits. A seller deleting a listing they were arguing to
keep is an ordinary thing to do.

So `subject` is nullable in the resource and the queue says the thing has gone.
Upholding one whose subject is gone is a **409** - there is nothing left to put
back - and dismissing it is fine. The queue's eager loading names the nested
relations per type through `loadMorph`, after pagination, reached via
`getCollection()`, for the three reasons ADR 0054 wrote down.

## `can_appeal` is not enough on its own, and that was a defect

The obvious resource field is `can_appeal`, and it was the first thing built:
the thing is stopped, the viewer owns it, and no appeal is open yet.

It is false in two very different situations - there is nothing to appeal, and
you already did - and a page holding only that field cannot tell them apart. The
effect was concrete rather than theoretical. A seller appeals, the form is
replaced by a confirmation, and that confirmation lives in component state; they
come back the next day to a suspension notice, no form, and **no sign that the
argument they sent ever arrived**. "Raising one changes nothing" would have read
as "raising one does nothing".

`has_open_appeal` is published beside it for that reason, guarded by the same
conditions in the same order, so a shop nobody stopped and a catalogue of
ordinary drafts still cost no query. The page says an appeal is being looked at.
It was found by asking what the second page load looks like, which is a question
worth asking of any control that hides itself after being used.

## A dispute is deliberately not appealable

The four things the platform decides are a shop application, a dispute, a report
and now an appeal. Disputes are the conspicuous omission.

Deciding one **moves money** - refunded cancels and refunds, released completes
and transfers (ADR 0051). Reversing that is a Stripe reversal, and nothing in
this application does one; ADR 0041 already wrote down that the abuse it cannot
cover needs exactly that. An appeal endpoint that could not deliver its remedy
would be a form that collects arguments and answers none of them, which is worse
than not having one.

## A fourth queue, and a fourth permission

`can_review_appeals` on `UserResource` is its own answer rather than a reuse of
the three beside it. All four agree today, because each policy asks whether
somebody is staff.

This one has the strongest reason to stay separate: deciding an appeal is the
only thing on this platform that undoes a takedown, so on the day staff stop
being one undifferentiated group - which ADR 0037 has listed as open since it
was written - it is the first permission anybody would hold back.

Staff cannot decide an appeal they raised themselves, the same guard
`ReportPolicy::decide` and `DisputePolicy::resolve` apply.

## What the queue shows, and what it does not

Both sides of the argument, because staff have nowhere else to see either.
`subject` carries `sanction_reason` - the platform's own words when it stopped
the thing - beside the appellant's own. An appeal argues against exactly that
sentence, so a queue without it would show the answer and not the question.

**Neither decision is styled as the default**, and here that is enforced rather
than stated. The moderation queue makes its destructive control the quieter one,
which works because only one side of it changes anything. Both sides change
something here: upholding puts a shop back on the marketplace, dismissing leaves
somebody stopped who has just argued they should not be. Styling either as the
obvious one would be the queue nudging its own outcome.

Who decided is recorded and **not published**, for the reason a dispute's
`resolved_by` and a suspension's `suspended_by` are not.

The note is required either way, and a dismissal needs it most: it goes to
somebody whose shop is still stopped, and it is the only explanation they get
for the platform declining to explain twice. Whoever appealed is told either
way, because an appeal nobody answers is worse than no appeal at all.

## Testing

PHPUnit, 20 tests: a suspended shop, a removed listing and a hidden review can
each be appealed, and a trading shop, an ordinary listing and a visible review
are each refused; **the sanction is untouched by raising one**, asserted on all
three; a second open appeal is refused and a dismissed one may be raised again;
one shop cannot appeal another's listing and one person cannot appeal another's
review; the queue is staff-only, oldest first, and loses an appeal when decided;
upholding reinstates the shop, **returns the listing as a draft rather than to
sale**, and unhides the review; dismissing changes nothing; deciding twice is
409; upholding one whose subject was deleted is 409 and dismissing it is not;
staff cannot decide their own; and whoever appealed is told either way.

Vitest: the control offers nothing when there is nothing to appeal, **says an
appeal is waiting rather than falling silent**, sends the argument, promises a
second look rather than a reversal, does not redraw the page afterwards, puts a
refused reason beside its field, and shows the API's sentence on a 409.

Playwright: the round trip no single suite can see - staff suspend Retuned
Audio, its owner reads the reason and argues in a browser, **the page still says
so after a reload**, staff read both sides in the queue and reverse themselves,
and the shop is back on the storefront. Upholding the appeal is also the
cleanup, which is the tidiest possible proof that the remedy works.

---

## Not yet decided

- **A hidden review has no page to be appealed from.** The endpoint exists and
  is tested, but nothing in the frontend shows somebody their own hidden review,
  so there is nowhere to put the control. The shop and the listing both had a
  page already saying why they were stopped; a review does not. What is missing
  is a "your reviews" page, which is its own feature rather than a corner of
  this one.
- **A history of appeals.** Done in [ADR 0060](0060-a-shops-record.md), which
  closed this and the three identically worded items that prompted it. An appeal
  is recorded against **the thing it argued about** rather than against itself,
  so a reader is not made to resolve a morph through a morph to find out what it
  concerned - and an appeal about a hidden review is recorded nowhere, for the
  reason the hiding itself is not.
- **Appealing a dispute.** Reasoned above, and it stays open rather than closed:
  it needs a Stripe reversal, which ADR 0041 also wants.
- **A deadline on an appeal.** Nothing expires an unanswered one, and a shop
  waiting is a shop losing money. The queue is oldest first, which is a
  convention rather than a guarantee.
- **Appealing a decided appeal.** Nothing stops somebody raising a fresh appeal
  about the same sanction after a dismissal, and nothing yet treats the second
  one differently from the first.
