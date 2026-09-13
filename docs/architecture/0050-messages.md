# 0050 - Messages

Status: accepted - 2026-09-13

ADR 0019 inventoried four things the design export showed with no backend behind
any of them. Search became ADR 0020, reviews ADR 0047 and shipping ADR 0049.
This is the fourth and last of them, and the one the export gave a whole screen
to.

---

## The order is the conversation

```text
who        the two parties to one order
what       a body, and which side wrote it
how many   one thread per order, beginning when somebody first writes
```

**There is no conversations table, and that is the decision the rest follows
from.** A thread here would stand in a one-to-one relationship with an order
forever: it has exactly two parties, both already named on `orders`, and it has
nothing to say that the order does not already say. A table like that is a join
nobody needs and a second place for the same two ids to disagree.

So `order_messages` hangs off the order, and the order is the thread.

## The sender is a side, not an account

`sender` is an `OrderParty` - buyer or seller - rather than a `user_id`. The
order already names both, so an account here would be a copy of a fact one join
away, and the copy that could contradict it.

It is the same shape `cancelled_by` and `completed_by` take, for the same
reason, and it reads the same way: who, of the two parties, did this.

**Which side is writing comes from the route, never from the payload.** Each
audience has its own endpoint scoped to its own relation, so the sender is
decided by which one was called. There is no `sender` field for a buyer to send
in order to sign a message as the shop, and `OrderMessageTest` sends one anyway
to prove it is ignored.

## There is no state in which they stop talking

Every other action on an order refuses out of turn. An order cannot be accepted
twice or shipped before it is accepted, and those refusals are most of what
ADR 0012 is about.

**This one has no state gate at all**, deliberately. A conversation is needed
most exactly where the lifecycle has stopped helping: a parcel that never came,
a cancellation whose reason needs explaining, a return being arranged after the
order completed. Closing the thread when the order ends would shut it at the
moment it starts mattering, and it would take the record of what was agreed with
it.

That also makes this the first half of anything a dispute could later be argued
from, which ADR 0041 wrote down as unsolved.

## Anchored to an order, and what that leaves out

The export shows messaging two ways at once. Its thread list is keyed by shop -
"Northlight Analog", "Bolt & Thread" - and marked "about Order N", but it also
puts a "Message" button on a listing, under "Northlight usually replies within
2 h", which is contact before any order exists.

**This builds the order-anchored half**, and the choice was the owner of the
project's. It is the smaller domain and the honest one: being a party to an
order is the entire permission, the relation already carries it, and so there is
no policy here and nothing for a caller to substitute. Both threads the export
actually draws are about an order.

Opening a thread from a listing is a different chapter rather than a missing
field. Anybody could write to any shop, so it needs its own rate limiting, and
it turns blocking and reporting into questions that have to be answered rather
than deferred.

## Unread belongs to the recipient

`read_at` is set when the **other** side reads a message. A message is never
unread to whoever wrote it, so every count asks for messages this caller did not
send - which is also what stops a shop clearing the badge the buyer is waiting
on by opening its own order.

Marking read is `POST .../messages/read` rather than something the list does on
the way past. A `GET` reads and does not change anything (root `CLAUDE.md`
section 9), and a thread that marked itself read on every fetch would do it on
the refresh that redrew it after a failure. It answers 204, and a second call
has nothing left to do rather than failing.

Both order resources carry `unread_message_count` for their own side. The two
are the same figure counted from opposite ends.

## The API publishes which side, not "yours"

`sender` is the side, and deliberately not `sent_by_you`.

The two audiences read this at two different addresses with two different
components, so which side is looking is a routing fact the frontend already
holds rather than a rule it would be re-deriving. `cancelled_by` is published
exactly this way and `order-timeline` already turns it into "You cancelled it"
on one side and the shop's name on the other.

It is the enum rather than its value, so the generator publishes a union of the
real cases and a component switching on it is exhaustive.

There is no author name on a message. Both parties know who they are talking to
from the order, and putting a shop owner's personal name on their replies would
publish something the shopfront does not.

## The mail carries the message itself

The other side is told by mail, queued and after the commit like everything else
(ADR 0035). It quotes the message in full rather than announcing that one
arrived: "you have a new message" plus a link is a second trip for something
that fits in a sentence.

**The same order has two addresses**, and the mail links to the recipient's. A
shop reads it under `/seller/orders` and a buyer under `/account/orders`, so a
single link would send half of everybody to a page that answers 404.

The body goes through `quoted()`, because it is text a stranger typed and the
template renders Markdown.

## The badge is a subquery, not a count per row

A badge on every row of an order list is an aggregate, and asked naively it is
one query per order. `Order::scopeWithUnreadMessagesFor` puts it in the same
statement, which is what `Product::scopeWithRating` does for a listing's rating
and for the same reason.

Two things it has to get right, both learned here before:

**The alias names the side it counted for.** A query that asked for the buyer's
count and a resource that read the shop's would otherwise get a number that is
real, wrong, and impossible to spot. Reading the wrong alias finds nothing and
falls back to a real query instead, so the answer is never wrong - only slower,
exactly as `averageRating()` promises.

**It is a correlated subquery rather than a `withCount` closure.** Inside a
closure the analyser is handed a `Builder<Model>` and cannot check a column name
against it, so `where('sender', ...)` is an error there - the same failure
`LeaveReview` hit with `whereHas`. Starting from `OrderMessage` gives every
condition something real to be checked against. `scopePaid` gets away with a
closure only because `whereNotNull` carries no such constraint.

The buyer's order list had to start from `Order::query()` rather than from
`$user->orders()` to carry the scope at all: a scope called on a relation
forwards through `__call`, which the OpenAPI generator cannot follow, and the
endpoint would have been published as an unpaginated array while working
perfectly at runtime. `SellerOrderController` already said so in a comment;
this is the second endpoint to need it.

## Testing

PHPUnit: each side writes and the other reads it; a conversation comes back in
the order it was said, which is the one list here that is not newest-first; a
sender in the payload is ignored; a message has to say something; **a cancelled
order can still be talked about**; somebody else's order and another shop's are
both 404; an unpaid order does not exist to its shop, so it cannot be written to
about one; each side is told what it has not read, in the single order and in
the list where the figure comes from the scope; reading clears only the other
side's messages; marking read twice is harmless; a message records when it was
read; the right party is told and the other is not; and the mail quotes the
message and links to the reader's own address for the order.

`PaginationTest` walks both ends of the conversation, because two audiences read
the same rows at two addresses and the envelope has to be right at both.

The guest assertion needed `Auth::forgetGuards()`. `PlacesOrders` signs the
buyer in to reach checkout and `actingAs` persists on the test instance, so
without it the signed-out request is still the buyer's and asserts nothing -
which `PaginationTest` had already found out and says so at `fetch()`.

Vitest covers what `Conversation` promises: the same two messages read as "You"
and the shop's name from one side and the other way round from the other, which
is the whole of publishing `sender` as a side; an empty thread says so in a
sentence; what was written is sent and the box cleared; a 422 lands beside the
box; a lapsed session goes to sign in and back to the order; the other side's
messages are marked read only when some are waiting; and "Read" appears only on
your own messages, and only once it has happened.

Playwright proves it is one thread rather than two. The shop writes, the buyer
finds it on their own copy under the shop's name and replies, and the shop finds
the reply on theirs - asserted in a browser rather than inferred from two suites
that each saw half of it. A second test writes to an order that has just been
cancelled, because that is the decision the whole chapter rests on and it is
worth a test that would fail if anybody ever added a state gate.

---

## Not yet decided

- **Messaging a shop before buying.** The export's listing has a Message button
  and this does not feed it. See above for what that chapter has to answer.
- **An inbox.** The export shows every conversation in one place, with a search
  over them and an unread badge in the header. What exists is one thread per
  order, reachable from that order.
- **Attachments.** A photograph of a damaged parcel is the obvious next thing to
  want, and it is object storage, a size limit and a scanning question rather
  than a column.
- **Quieter mail.** Every message sends one. A rapid exchange is a mail each
  way, and debouncing them is a real decision rather than a tuning value.
- **Moderation.** Nothing flags a message, staff have no endpoint that reads
  one, and neither party can block the other. The conversation is only as
  bounded as the order that carries it.
