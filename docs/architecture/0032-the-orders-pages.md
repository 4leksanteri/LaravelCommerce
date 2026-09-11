# 0032 - The orders pages

Status: accepted - 2026-09-11

A buyer's list of orders, one order's own page, and the three things a buyer can
do to an order. The API for all of it already existed (ADR 0012, ADR 0014);
these are its pages.

---

## Two pages, not the export's two columns

The design export draws the orders as a list with the selected order beside it,
and on a phone as the order on its own. Here they are two pages, `/orders` and
`/orders/[reference]`. Each has an address that can be bookmarked and shared,
each works at phone width without a layout that changes shape, and the order's
page is what the checkout confirmation now links every reference to.

## One row per order

A checkout that spanned three shops is three rows, because it is three orders
in three currencies, and each can go right or wrong on its own.

`checkout_reference` would let rows be grouped back into the basket they came
from, and the list does not group them. It is paged by order, twenty at a time,
so a basket could be split across two pages, and half a group reads worse than
none. If grouping matters, the API should page by checkout rather than by order,
and that is the API's decision to make (ADR 0011).

## The buttons are the API's

`can_cancel`, `can_complete` and `can_extend_completion` are drawn as they
arrive. Nothing on these pages reads the status to decide what may be done. A
buyer may not cancel once the shop has accepted, and the page knows that only
because the API stops offering it (ADR 0012).

**The two final actions ask first.** Cancelling and confirming arrival cannot be
undone, and a button that ends an order on one click is one mis-tap from a
support request. The question replaces the buttons in place and takes the focus,
so a keyboard lands on the answer rather than on the page.

**More time says where the date moved to.** How long an extension lasts is the
API's configuration. The page shows the new date from the API's answer rather
than adding a week of its own, which would be a copy of the rule.

**A refusal redraws the page.** A 409 means the order moved while the page was
open: the shop accepted it, or the deadline passed. The API's message is shown
as it was sent, and the page is drawn again from the order as it now is.

## The timeline is the timestamps, and it does not say who

A step with a date has happened, the first step without one is what the order is
waiting for, and the rest have not happened yet. A cancelled order shows what
happened and then that it was cancelled.

It does not say who cancelled an order, or whether a completed one was confirmed
by the buyer or by the deadline. The API records neither yet (root CLAUDE.md
section 20), and a page that guessed would be making it up.

The export's timeline also has a "Delivered" step, a courier's tracking number,
"Open a dispute", and a sum "held by LaravelCommerce". Nothing records a
delivery or a tracking number, disputes are deliberately not built, and no money
is held. None of them is drawn.

## The money is what the order came to

No payment is taken yet (ADR 0015, ADR 0031), so the total says what the order
came to, with the same sentence the checkout shows: no card was charged. The
button says "Confirm it arrived", not the export's "release" and a sum.

## Dates are in UTC

`formatDate` writes "4 Mar 2026", in a fixed locale and in UTC. The API knows
nothing about where anybody is, the page is rendered on a server whose zone is
whatever the container was given, and a zone that differed between the server
and a client component would be a hydration mismatch. UTC is the one zone that
is the same everywhere and does not pretend to be the reader's.

The cost is a date that is a day out for somebody far from Greenwich late in
their evening. Nothing here is shown to the minute, and the date that matters,
when an order completes on its own, is days away.

## An order's status, in one set of words

`statusLabel` is used by the confirmation, the list and the order's page, so all
three say the same thing, and it is exhaustive over the API's cases. The badge
adds a coloured dot, and never the dot alone: the words are always there.

## Testing

Vitest covers what the page owns in `OrderActions`: it draws only what the API
allows, asks before either final action and sends nothing on a no, shows the
API's new date, redraws on a 409 with the API's reason, and sends a lapsed
session to sign in and back. `formatDate` is tested for UTC.

Playwright places its orders through the API (`e2e/support/orders.ts`) rather
than through the checkout form, which checkout.spec already drives. The buyer's
side of an order's life cannot be reached without the shop's, so the setup
project now saves a second session, a demo shop owner's, to accept and send an
order. That is two sign-ins per run, inside the limits of five a minute per
address and twenty per IP.

The tests buy the Seiko 5 on its canvas strap: three in stock and wanted by no
other test. One of them completes its order, and a completed order keeps its
stock for good, which `make seed-demo` puts back before the next run. Every test
that places an order finishes it in a `finally`, cancelled by whoever is still
allowed to.

---

## Not yet decided

- **Grouping by checkout.** Above.
- **Who cancelled, and who completed.** The attribution and notification change
  ADR 0014 describes; until it lands, these pages say what happened and not who
  did it.
- **Telling anybody.** Nothing tells a buyer that the shop accepted or sent
  their order. They find out by looking.
- **A link to a listing that is no longer on sale.** A line links back to its
  listing while the variant exists, and a listing its seller has unpublished
  answers not-found from there.
- **The shop's name is text.** There is no shop page to link it to (ADR 0028).
