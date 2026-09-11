# 0036 - The shop's orders

Status: accepted - 2026-09-11

A shop's list of orders, one order's page as the shop sees it, and the three
things a shop can do to an order. The API for all of it already existed (ADR
0012); these are its pages, and one addition to the list: narrowing it to a
status.

---

## Two pages, in the shop's section of the sidebar

`/seller/orders` and `/seller/orders/[reference]`, drawn by the same layout as
the rest of the account and the shop (ADR 0033). "Orders" sits in the shop's
section of the sidebar, and the overview's order count links to the list.

They are two pages for the reason the buyer's are (ADR 0032): each has an
address, and a mail can link to one.

## The list is a to-do list, and the API narrows it

A buyer's list of orders is a record. A shop's is work: what is waiting to be
accepted and what is waiting to be sent. So the list can be narrowed to one
status, by a pill per status, and the two statuses that wait on the shop come
first after "All".

**The narrowing is the API's**, `GET /seller/orders?status=pending`, inside the
shop's own orders. Filtering in the browser would filter one page of twenty and
call it the queue: an order waiting to be sent on page three would be missing
from a "to send" view of page one.

**One status at a time.** Every question the page asks is one status. A "needs
attention" view that combined two would be a second definition of what needs
the shop, in the one place that should not hold a rule, and the API would have
to be asked for it by name anyway.

**A status that does not exist is a 422**, not an empty list, so a mistyped link
says so. Only a hand-edited address produces one, and the page sends it back to
the whole list rather than drawing an error.

Each pill is a link, so a shop can keep "to send" open in a tab.

## The same order, in the shop's words

`statusLabel(status, reader)` now takes who is reading. A pending order is
"Waiting for the shop to accept it" to its buyer and "To accept" to the shop;
an accepted one is "Accepted by the shop" and "To send". The last three read the
same to both.

**One timeline, told to either side.** `OrderTimeline` takes a `reader` and the
other side's name, `counterpart`: the shop's name for a buyer, the buyer's name
for a shop. The steps are read off the same timestamps, and only the notes
change: "Waiting for you to send it" to the shop, "Waiting for Second Hand Time
to send it" to the buyer, and "You cancelled it. Your reason: ..." against
"Second Hand Time cancelled it. Their reason: ...".

A second timeline for the shop was rejected. The logic that reads the steps off
the dates would have been copied, and the two copies would have disagreed about
what an order is waiting for the first time either changed.

## Accepting and sending do not ask first

The buyer's two final actions ask before they act (ADR 0032). A shop's do not:
accepting and marking sent are what a shop does to every order, and each is
exactly what the buyer is waiting for. A question before the ordinary thing
teaches people to answer questions without reading them.

**Cancelling asks for the reason, and that is the confirmation.** The API
requires one (ADR 0035), because the buyer reads it on their order and in the
mail. The form that collects it replaces the buttons and takes the focus, and
filling it in is the pause before something that cannot be undone. A refusal
for the reason, such as an empty one, appears beside the field.

There is no "complete" button, because the API has no seller endpoint for it
and must not (ADR 0012). The page only draws `can_accept`, `can_ship` and
`can_cancel` as they arrive.

**A 409 redraws the page with the API's message**, as on the buyer's side: the
buyer cancelled while the page was open, or the order was not accepted in time.

## Where it is going is on the order's page

The list shows who bought it and their city. The order's page shows the whole
delivery address, under "Send to", because that is the page a shop has open
while packing. The mail about a new order leaves the address out (ADR 0035), so
this page, behind a session, is the one place the shop reads it.

## Mail to a shop links to the order

ADR 0035 linked every mail to a shop to `/seller`, because the order had no page
of its own. A new order, a cancellation, a completion and a request for more
time now link to `/seller/orders/{reference}`. The buyer's mail already linked
to their order.

## Testing

PHPUnit checks that the list narrows to one status, that narrowing never
reaches another shop's orders, that an unknown status is a 422, and that a
shop's mail links to the order.

Vitest checks the timeline's words for each reader, and `ShopOrderActions`:
only what the API allows is drawn, accepting is one click, cancelling asks for a
reason and sends it, a 422 lands beside the field, "Keep it" sends nothing, a
409 redraws with the API's reason, and a lapsed session goes to sign in and
back to the order.

Playwright signs in as the owner of Second Hand Time, with orders the demo
shopper places through the API in a browser context of their own
(`asShopper`). It accepts an order and marks it sent and waits for the buyer's
mail; cancels one, is refused without a reason, then gives one and finds it on
the buyer's page; narrows the list; finds another shop's reference not found;
and checks both pages at phone width and with axe. Every order is finished in a
`finally`, as in the buyer's specs.

---

## Not yet decided

- **How many are waiting.** The pills could say "To accept (3)". That needs a
  count per status from the API, and the list's `meta.total` counts only the
  status being looked at.
- **Oldest first for the to-do views.** The list is newest first everywhere. A
  queue of orders to accept is arguably fairer oldest first; it is one `order`
  parameter when somebody asks.
- **A packing slip.** Nothing prints the address and the items together.
- **Tracking.** Marking an order sent records a date and nothing else (ADR
  0032). A courier and a tracking number would be a change to the API first.
- **Talking to the buyer.** There are no messages. The reason for a cancellation
  is the only thing a shop can say to a buyer.
