# 0035 - Who ended an order, and telling people

Status: accepted - 2026-09-11

ADR 0014 left four questions to be answered together rather than piecemeal: who
cancelled an order, why, who completed it, and telling anybody. This answers all
four. It also adds the queue worker ADR 0013 said the fourth would need, and
sends the two security notices ADR 0034 left for here.

---

## Who ended it

Three columns on `orders`:

```text
cancelled_by          buyer, seller, or deadline - an order nobody accepted
cancellation_reason   the shop's, when the shop called it off
completed_by          buyer, or deadline - the clock on the buyer's behalf
```

They hold an `OrderActor`, not an `OrderParty`. OrderParty is who may act on an
order, and it has two cases because only people ask for permission. The
platform ends orders too, when a deadline passes, and the case says "deadline"
rather than "system" so nobody has to guess which system.

**A shop cancelling has to say why.** The seller's cancellation now takes a
required `reason`, because a buyer whose order a shop calls off is owed an
explanation. A buyer cancelling their own order owes nobody one, and their
endpoint still takes no body.

The columns are nullable and their CHECKs run one way. Every order ended from
now on records who ended it; orders that ended before this did not, and nothing
can say now. The constraints hold what can be held: an actor never appears on
an order that did not end that way, a reason is only ever a shop's, and a shop
is never recorded as completing an order (ADR 0012).

Both sides' resources publish the three fields, and the buyer's timeline now
says who (amending ADR 0032): you, the shop and its reason, or a deadline.

## Whoever did not act is told

```text
what happened                   who is told
a checkout                      the buyer, once; each shop, about its own order
a shop accepts                  the buyer
a shop sends it                 the buyer, with the date it completes on its own
the buyer cancels               the shop
the shop cancels                the buyer, with the shop's reason
nobody accepted it in time      both
the buyer confirms arrival      the shop
its deadline completes it       both
the buyer asks for more time    the shop, with the new date
staff approve or reject a shop  the shop, with the reason for a rejection
the email address changes       the address being left
the password changes            the account
```

The rule is that the person who pressed the button is already looking at the
result, and the other side is not. When a deadline did it, nobody pressed
anything, so both are told - and a buyer especially should hear that the
marketplace has concluded their parcel arrived.

A checkout is one mail to the buyer, however many shops it spanned: they pressed
one button, and three receipts arriving at once would read as three purchases.

Mail about a shop goes to its contact address rather than its owner's account
address (`Seller::routeNotificationForMail`). A shop run by one person today
may have a shared inbox tomorrow, and the shop said which address to use.

Mail only. A notification centre on the site would be the database channel, and
nothing on the site reads one yet.

## Queued, and only after the commit

ADR 0013 said synchronous mail was tolerable while it was only registration and
password reset, and "stops being tolerable the moment order notifications
land": a slow mail server would sit inside a checkout, and a failing one would
answer 500 for an order already placed.

So every notification extends `QueuedNotification`, which is `ShouldQueue` and
calls `afterCommit()`, and `NotificationsAreQueuedTest` fails for one that does
not. The actions raise notifications after their transactions return, so a
refused step tells nobody, and `afterCommit()` keeps one raised inside a larger
transaction from going out if that transaction rolls back.

**The worker is a `queue` service in both compose files,** from the API's own
image:

- **Development** skips the API's entrypoint, because by the time the api
  service is healthy it has installed `vendor/` and migrated, and a second
  container doing both would race it. It runs `queue:listen`, which boots the
  framework for every job, so an edited notification takes effect without a
  restart.
- **Production** keeps the entrypoint, so the worker refuses to start without
  `APP_KEY` and runs from cached configuration. It runs `queue:work` with
  `--max-time=3600`, so a slow leak is an hourly restart rather than an outage.

Both retry a failed job three times, a minute apart. Jobs and failed jobs are
PostgreSQL tables that already existed; there is still no Redis. ADR 0013's
production target would run the same command as a service with a warm instance,
and nothing is built for it yet.

Registration's verification mail and the password reset mail are Laravel's own
notifications. They still send inside their requests, as before this change.

## What goes into a mail

**Links go to the web application**, built from `FRONTEND_URL`. The API is not
reachable from a browser (ADR 0003), so a link to it in an inbox is a dead link.

**Money is formatted by `Currency::format`**, which asks ICU how many minor units
a currency has and writes it in en-GB, as `formatMoney` does in the browser, so
a mail and the page it links to write a sum the same way. It is the repository's
first unit test. **Dates** are UTC, as on the pages (ADR 0032).

**Text people typed is quoted.** Laravel's mail template renders Markdown, and a
shop's cancellation reason is typed by one person and read by another: written
as `[Claim your refund](https://...)`, it would arrive in the buyer's inbox as a
link. `quoted()` escapes the brackets in every name and reason, and a test
renders the mail and looks for the link.

**The address an account left is shown a new address mostly hidden.** Its reader
is either the owner, who knows it, or somebody whose old address it was, who has
no business learning the new one.

## Testing

PHPUnit records who for every way an order ends, and holds the constraints. It
checks each event's recipients against the table above, including that a
refused step tells nobody, that a shop's mail goes to its contact address, that
links point at the web application, that amounts are in the shop's currency, and
that a reason cannot carry a link. The review decisions and the two account
notices have their own tests.

Vitest checks the timeline's words for each actor.

Playwright checks that the queue worker actually sends: the orders spec waits in
Mailpit for the shop's "New order", the buyer's "sent order" with its date, and
the shop's "cancelled" and "complete".

---

## Not yet decided

- **A notification centre on the site.** Above.
- **The shop's orders page.** Built in [ADR 0036](0036-the-shops-orders.md), and
  mail to a shop now links to the order.
- **Turning any of these off.** Nobody can. Every one is about something that
  happened to that person's own order, shop or account; there is no other kind.
- **Getting an account back.** The notice about a changed address goes to the
  old address, and nothing lets that address undo the change.
- **Registration and reset mail on the queue.** Above.
- **The deadlines themselves.** `orders:expire` and `orders:auto-complete` are
  still triggered by nothing (ADR 0013). When somebody runs them, the orders they
  end now say so and both sides are told.
