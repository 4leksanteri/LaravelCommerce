# 0058 - Closing an account

Status: accepted - 2026-09-15

ADR 0034 left one sentence behind: "Orders refer to their buyer, and a receipt
has to outlive the account that paid it. Nothing has decided what deleting
keeps."

This decides it. For a marketplace trading in four European currencies from
Finland, being unable to leave is not a missing feature but a missing
obligation.

---

## It cannot be a delete, and the database says so

```sql
orders.user_id     restrictOnDelete
orders.seller_id   restrictOnDelete
```

PostgreSQL refuses to remove anybody who has ever bought or sold. That is not an
obstacle to work around: it is ADR 0011's decision, written into the schema
before anybody contemplated deletion, and it is correct. A receipt belongs to
the shop as much as to the buyer, and one party cannot erase the other's record
of a transaction.

So closing is **anonymisation**. The row survives, stops working, and stops
naming anybody.

**It is not `SoftDeletes` either**, and that is worth stating because the name
`deleted_at` is the obvious reach. That trait adds a global scope, and a global
scope would hide the row from every relation pointing at it - an order's buyer
would resolve to null and a shop's receipt would stop saying who it shipped to.
The column is `closed_at`, and nothing scopes on it.

## What goes, and what stays

```text
gone     the name, the address, the password, the remember token, the Stripe
         customer, the address book, the basket, every session
kept     orders and everything they froze, reviews, reports, messages, disputes
```

**Everything kept is kept for a reason.** An order is a transaction record the
other party holds too. A report is moderation's record of a decision. And a
review is a public statement that ADR 0047 refused to let anybody erase - so
letting a closure take one would be a back door straight through that decision,
quietly rewriting the rating of whichever shop it was about. The reviews stay
and the author becomes "A former customer".

**The email address is replaced rather than blanked.** `users.email` is unique,
so a second closed account would collide with the first; `closed-{id}@deleted.invalid`
is unique per account and says nothing about who it was. `.invalid` is reserved
by RFC 2606 precisely so that it can never be delivered to.

## It does not claim to erase the person, and says so

ADR 0021 freezes a name, both address lines, a city, a postal code, a country
and a telephone number onto every order at checkout, so that moving house cannot
rewrite where a parcel went. **None of that is touched here.**

That is a deliberate limit rather than an oversight, and this ADR states it
plainly rather than letting the word "delete" imply otherwise: what is kept is
the record of a transaction, which the shop at the other end of it holds
equally. Scrubbing those snapshots once an order is long finished is a real
question and is listed below rather than quietly skipped.

## What has to finish first

Four refusals, all **409** - somebody is entitled to close their own account,
and what is in the way is that somebody is still waiting on it (ADR 0008).
Asked of **both sides**, because a shop owner is a buyer too.

```text
a shop that is still open   this action cannot close one
an open dispute             the decision moves their money
an unfinished order         somebody is waiting at the other end
a refund that has not come  closing would strand money owed back
```

**A shop is refused rather than closed**, and the constraint decides it:
`sellers_suspension_is_whole` requires a member of staff against any stopped
shop, and the owner is not one. Writing their own id into that audit column
would record a lie, so closing a shop is its own chapter.

**The refund rule is narrower than it first looks, and the first version was
wrong.** `Payment::isHeld()` is paid, not refunded, not transferred - and a
_completed_ order sits in exactly that state whenever its shop has no active
payout account, because `TransferToShop` returns early and a settlement run
collects it later. Refusing on every held payment would have trapped any buyer
who had confirmed a parcel, held hostage to the platform's unfinished business
with a shop they have no stake in. In a stack where no shop has a payout
account, that is every buyer. So completed orders are excluded, and what is left
is the case worth refusing for: an order called off whose refund has not
arrived.

## The password, and the session

It needs `current_password`, like changing the address and changing the
password (ADR 0034), and it has the strongest claim of the three: those two can
be undone by whoever owns the inbox, and this cannot be undone at all. It shares
their limiter, because the attacker all three are for is somebody already inside
a session left open on a shared computer.

Every session goes, including the one doing it. `sessions.user_id` carries no
foreign key, so nothing cascades and the delete is explicit - the same thing
`ChangePassword` does and for the same reason. The page ends in a full document
load, because every render the client router holds belongs to somebody who is
no longer signed in.

The mail goes to the address on the way out, captured before the write, since a
moment later there is no address to send to.

## It answers ADR 0025's open item without being a backdoor

That ADR wanted end-to-end runs cleaned up and named the trap:

> Any fix has to go through the API, and an endpoint that deletes users for a
> test runner is a backdoor with a test's name on it.

This is not that endpoint. It goes through the API, needs the account's own
password and its own session, and exists for people. The auth spec can now close
the account it registered using the same route a person uses - which is the only
kind of teardown that respects the boundary.

## Testing

PHPUnit: an account closes and stops naming anybody, with a unique `.invalid`
address; the old password no longer signs in; the address book, the basket and
every session go; the address being closed is told. Orders are kept and still
name their buyer - as "Closed account" rather than as a blank, which is the
whole reason this is not `SoftDeletes`. Reviews stay, keep their rating, and
read "A former customer" rather than "Closed a.", which is what the
first-word-and-initial shortening would otherwise have made of it. Each of the
four refusals, the wrong password, and the guest. And the case the naive rule
got wrong: **a completed order awaiting its transfer does not refuse it.**

Vitest: the component says what is kept before asking anybody to decide, asks
before it sends anything, goes back without sending, sends the password and ends
in a full page load, and shows the API's own sentence for a 409.

---

## Not yet decided

- **The delivery snapshots.** Above. Every order keeps a name, a postal address
  and a telephone number, and scrubbing them once an order is long finished
  would need a retention period and an answer about a shop's own records.
- **Closing a shop.** Refused here, because suspension records a member of staff
  and there is none. A shop that has finished everything it sold ought to be
  closeable by its owner, and that is its own chapter.
- **The Stripe customer.** The id is forgotten on this side; the customer at
  Stripe is not deleted. It holds an email address and card details this
  application never stored (ADR 0031), and deleting it is an API call with its
  own failure modes.
- **Reopening.** There is no way back. The row could in principle be revived,
  and nothing about the address or the password survives to do it with.
- **Telling the shops.** A shop with a completed order from this account is not
  notified that its buyer has gone, and sees the name change with no explanation.
