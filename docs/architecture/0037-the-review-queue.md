# 0037 - The review queue

Status: accepted - 2026-09-12

Staff decide whether a shop may trade. The API for it has existed since ADR
0007; this is its page, and one change to the queue behind it.

---

## One page, and deliberately no staff area

`/admin/shops` is a single full-width page under the site's own header. There
is no staff layout, no second sidebar and no `/admin` shell, because staff have
exactly one thing to do here and a shell built for one page is architecture
built in anticipation (`apps/web/CLAUDE.md`: components are extracted, not
designed in advance).

It is reached from a link in the header, drawn when the API says
`can_review_sellers`. That field already existed on the user resource, and is
the answer rather than the role: the frontend never learns that `role` is
`staff` or `admin`, and could not act on it if it did.

When staff have a second page - categories are seeded and have no screen - the
two will want a section of their own, and that is when a layout is worth
extracting.

## Shops in the address, sellers in the API

The page is `/admin/shops` and the endpoint is `/admin/sellers`. A `seller` is
the row that owns a shop, which is what the API is scoped by; a shop is what
the page is about and what the rest of the frontend calls it (`/shops/{slug}`).
The two words are used consistently for those two things rather than being
treated as synonyms.

## Everything on the card, and no page per application

Each application is one card carrying the whole resource: what the shop says
about itself, its contact address, its currency, the public address its slug
would give it, when it applied, and for a decided one when it was reviewed and
the reason that was sent.

There is no page for a single application, because there is no staff endpoint
for one - `SellerPolicy` deliberately has no `view` method (ADR 0008) - and
nothing on such a page would be missing from the card. A reviewer should not
have to open a second page to read the paragraph they are deciding on.

## Both decisions ask first

A decision cannot be undone: a reviewed application cannot be reviewed again,
and what is being decided is whether somebody may trade here.

- **Approving asks a plain question**, and says what approval does.
- **Turning one down asks for the reason**, which is required by the API and
  read by the applicant, who needs it to apply again (ADR 0007). That form is
  both the reason and the pause, so there is no second question on top of it.

This is the opposite of the shop's own order queue, where accepting and sending
are one click (ADR 0036). The difference is what the action is: those are the
ordinary steps of a job done many times a day, and these are final judgements on
somebody else's livelihood.

**A 409 means another reviewer got there first.** Two people can have the queue
open. The second is refused with the decision that was recorded, its message is
shown, and the queue is drawn again.

## The queue narrows by status, and an unknown one is now refused

The filters are links, one per status, and the narrowing is the API's
(`?status=`), as it is for a shop's orders (ADR 0036).

**The API changed here.** It took `status` as a raw query parameter and ignored
anything that was not a status, so a mistyped link answered with every shop on
the platform and looked as though it had worked. It is now a form request, and
an unknown status is a 422 - which the page turns back into the whole queue,
since only a hand-edited address produces one.

One consequence worth knowing: a form request validates before the controller
authorizes, so a caller who is not staff and sends a nonsense status now gets
422 where they used to get 403. Nothing is disclosed by it - the status rule is
the same for everybody, and the queue itself is still refused.

## A shop's status has one set of words

`shopStatusLabel` gives "Awaiting review", "Open" and "Not approved", and the
badge on the shop's own overview, the sidebar and these filters all use it. A
shop that is "Awaiting review" to its owner is not "Pending" to the person
reviewing it.

Unlike an order's status (ADR 0036), the words do not change with the reader.
An application is one thing being decided, rather than something two sides are
each waiting on.

## The refusal is explained, not hidden

Somebody who is not staff gets the page saying what it is and that this account
is not one, with a link to their own shop. A 403 means "not allowed", and
saying so is the honest answer (ADR 0008); pretending the page does not exist
would be a different status with nothing gained, since the header only ever
offers it to staff anyway.

The page draws from `can_review_sellers` and decides nothing: the API refuses
the queue to anybody else whatever the page does.

## Testing

PHPUnit covers the review rules already. Added: an unknown status is refused.

Vitest covers `ShopReviewActions` - nothing drawn when `can_review` is false,
approving asks first and sends nothing on a no, the reason is sent, a 422 lands
beside the field, a 409 shows the API's message and redraws, and a lapsed
session goes to sign in and back.

Playwright signs in the demo reviewer, the suite's only staff account, and uses
two applications `make seed-demo` seeds pending and puts back on every run - a
decision cannot be undone, so a run needs its own to decide on. It approves one
and finds it under "Open", turns the other down after being refused an empty
reason, reads the reason back on the decided card and waits for the applicant's
mail in Mailpit, checks the filters and that a nonsense status falls back, and
checks the page at phone width and with axe. A non-staff account sees the
explanation and no applications.

---

## Not yet decided

- **Who decided.** The resource carries `reviewed_at` but not `reviewed_by`, so
  a decided card says when and not by whom. Publishing a reviewer's name to
  other staff is a small change; publishing it to the applicant is not, and the
  same resource serves both.
- **Suspending a shop that is already open.** There is no `Suspended` status
  (ADR 0007), and the questions it raises about open orders and payouts have no
  answers yet.
- **Notes between reviewers.** Nothing records why an application was left
  waiting.
- **How many are waiting**, on the filters. The same count-per-status the shop's
  order queue wants (ADR 0036).
- **Everything else staff might do.** Categories are seeded and have no screen,
  and nothing lists people or orders across shops.
