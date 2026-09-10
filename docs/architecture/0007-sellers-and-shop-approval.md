# 0007 - Sellers and shop approval

Status: accepted - 2026-09-10

The first domain in the marketplace. Settles three things that are expensive
to reverse once shops are trading: what a seller _is_, how many shops an
account may run, and what a shop's currency means.

---

## Selling is not a role

A person sells by having a `sellers` row. There is no `seller` value in
`UserRole`, and there will not be one.

```text
users.role      customer | staff | admin      what somebody is to the platform
sellers         a shop: name, currency, approval state
```

A role is a single word; a shop is a thing with a name, an address, a currency
and a review history. Modelling the shop as a role would mean every question
about it - is it approved, what does it trade in - going through two lookups
that can disagree, and it would mean a shop owner is somehow not a customer.

They are. A shop owner buys from other shops with the same account, which is
what a marketplace wants.

---

## One shop per account

`sellers.user_id` is unique.

That constraint is what makes `$user->seller` a single row rather than a guess,
and it is why every seller endpoint is a singleton with no id in its path:

```text
POST /api/v1/seller/application
GET  /api/v1/seller
PATCH /api/v1/seller
```

Relaxing it later is a migration plus a revisit of every query that assumed
one, so it is written down rather than left implicit. The alternative -
allowing several shops per account - would put a shop id in every seller-scoped
path from the beginning, for a flexibility nobody has asked for.

---

## Currency is chosen once and does not change

A seller picks from `App\Enums\Currency` when applying. Everything the shop
does afterwards is denominated in it, and it is not editable: it is absent from
`UpdateShopRequest`, absent from the model's fillable list, and a resubmitted
application deliberately keeps the original.

Changing it would mean re-pricing every product, and it would make orders
already placed ambiguous about what somebody actually agreed to pay. A shop
that genuinely needs a different currency is a different shop.

ADR 0004 listed this as undecided. It is decided.

The supported set is small on purpose - each currency is a payout arrangement,
a rounding rule and a set of test expectations. **Every case in it happens to
have two minor-unit digits, and no code may assume that**: JPY and ISK have
none, and the day either is added, everything that turns minor units into
something a person reads has to ask the currency rather than divide by 100.

---

## Approval is the only thing that makes a shop public

```text
Pending ──approve──▶ Approved        visible to shoppers
   │
   └────reject────▶ Rejected ──resubmit──▶ Pending
```

There is no separate `is_public` column to fall out of step with the status.
`Seller::scopePublic()` is the one definition, and the public endpoint looks a
shop up _through_ it rather than fetching by slug and checking afterwards - a
check that is part of the query cannot be forgotten.

A shop that is not approved answers **404**, not 403. Saying "this shop is
awaiting review" would tell anybody who guessed a slug that somebody applied
under it, and an application is not public information.

A rejected applicant may fix what was wrong and apply again. The same row is
reused, so they keep their address and their history rather than accumulating
one row per attempt.

**There is no Suspended state yet.** Suspending a trading shop raises questions
about open orders and pending payouts that have no answer until those exist.

---

## Applying needs a verified address

`verified` middleware on the application endpoint, and deliberately not on
reading or editing a shop that already exists.

The entire review conversation - approved, rejected, here is why - happens by
email. Applying with an address nobody has shown they can read is applying into
a void.

---

## A rejection carries a reason, and the database says so

```sql
CHECK (status <> 'rejected' OR rejection_reason IS NOT NULL)
CHECK ((status = 'pending') = (reviewed_at IS NULL))
```

The first stops a rejection that the applicant cannot act on. The second is
written as an equivalence so it catches both halves: a decision with no
timestamp, and a timestamp on an application nobody has looked at.

Both are also enforced in the application. They are in the database because
that is the layer that holds under concurrency and against a hand-run UPDATE.

---

## Reviewing is a decision being recorded, not a field being set

```text
POST /api/v1/admin/sellers/{seller}/approval
POST /api/v1/admin/sellers/{seller}/rejection
```

Not `PATCH {status: "approved"}`. Each is a different decision with different
requirements - a rejection carries a reason and an approval does not - and a
status field a client can set is a client that can set it to anything.

Two guards worth stating:

- **Staff cannot review their own application.** `SellerPolicy::review` refuses
  it. Being staff is not a way to wave your own shop through.
- **Staff cannot edit somebody's shop.** They decide whether it may trade; they
  do not rewrite its description. Keeping those apart means a review cannot
  quietly become an edit, and "who changed this" has one answer.

### Two reviewers, one application

Both actions re-read the row `lockForUpdate()` inside a transaction and check
the status again there. The status read before the transaction is a fact about
the past.

The reviewer who arrives second gets **409**, not 403 - they were allowed - and
not 422 - what they sent was fine. The world moved underneath them, and they
are told rather than silently made the author of somebody else's decision.

---

## Consequences found while building it

- **`User::create()` leaves `role` null in memory.** A database default applies
  to the row, not to the model instance, so `UserResource` asking the role
  whether it is staff made _registration_ return a 500. Fixed with
  `protected $attributes` on the model, which is the layer that reaches an
  unsaved instance.
- **Mass assignment silently drops what is not fillable.** `Seller::create()`
  with `user_id`, `slug`, `currency` and `status` inserted four nulls, because
  none of them is a field anybody submits. The actions use `forceFill`: they
  are trusted to set those, and a request body is not. That is the distinction
  the fillable list exists to draw.
- **The generated frontend types are only as good as the return types.**
  `can_edit` was published as `string` until the resource computed it in a
  method with a declared `: bool`, and `status` was a bare `string` until the
  resource returned the enum itself rather than `->value`. Both now generate as
  what they are - a boolean, and a union of the actual cases.
