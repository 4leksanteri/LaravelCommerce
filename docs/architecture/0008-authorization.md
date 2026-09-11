# 0008 - Authorization

Status: accepted - 2026-09-10

Where permission decisions live, and the three things they are routinely
confused with. Established with the first domain so that products, orders and
payouts inherit it rather than each inventing something.

---

## Policies, not controller conditionals

Every permission decision is a method on a policy. A controller asks; it does
not decide.

```php
$this->authorize('review', $seller);
```

The rule this replaces looked reasonable:

```php
if (! $request->user()->isPlatformStaff()) {
    throw new HttpException(403, '...');
}
```

It is the same rule as `SellerPolicy::viewAny`, written in a second place. Two
places is where they get to disagree, and the one that disagrees quietly is the
one nobody re-reads.

A listing has no single model to check, which is what Laravel's `viewAny` is
for: `authorize('viewAny', Seller::class)`.

Policies are resolved by name, with nothing to register: `App\Models\Seller`
finds `App\Policies\SellerPolicy`. **A policy that seems not to apply is almost
always a policy whose name does not match its model.**

### A policy method with no caller is deleted

`SellerPolicy` deliberately has no `view`. Nothing asks it: an owner reads
their own shop through `/seller`, staff read the queue through `viewAny`, and a
shopper reads an approved shop through the public scope.

A policy method nothing calls is a rule nobody is applying, and it reads as
though somebody is. It arrives with the endpoint that needs it.

---

## A route prefix is not an authorization boundary

`/api/v1/admin/**` grants nothing. Every method behind it authorizes through
`SellerPolicy`, and `SellerAuthorizationTest` asserts that a customer is
refused at each one.

The prefix is a URL. The day a controller moves out of the group, the check has
to still be there - and if the group was the check, it is not.

---

## Three things authorization is not

This is the distinction that decides which status code a caller gets, and
getting it wrong makes a real interaction wrong.

```text
401  no session                    the frontend clears state and signs in
403  not allowed                   the frontend explains it
409  allowed, but the state says no the frontend offers the next step
422  what you sent is invalid      the frontend shows it beside the field
```

### Not a state conflict

Applying to sell when an application is already pending is **409**, not 403.
The applicant is entitled to apply; they have already applied. Answering 403
would tell them they may not do something they may do.

Those rules live in the action - `ApplyToSell` - and reach HTTP as
`ShopApplicationNotAllowedException`, which `bootstrap/app.php` renders as 409
**once**. A controller that catches a domain exception only to rethrow it as
HTTP is a controller doing translation, which is the exception handler's job.

The same goes for losing a race to another reviewer:
`SellerAlreadyReviewedException`, also 409.

### Not validation

`RejectSellerRequest` requires a reason of at least ten characters. That is
about the payload, not about the person, and it is 422.

### Not a role

Roles are `customer`, `staff`, `admin` - what somebody is _to the platform_.
Selling is not among them: a person sells by having a `sellers` row (ADR 0007).

The two are independent in both directions, and there are tests for both:

- **Staff may run a shop.** They edit it like anybody else, and they still
  cannot review their own application - `review` refuses it even for an admin.
- **Having a shop grants nothing.** A seller is not staff and cannot read the
  review queue.

---

## Seller-only endpoints require a shop, once

`RequireSellerProfile`, aliased `seller`. It resolves the caller's shop, or
refuses, and puts it on the request so the controller does not look it up
again.

```php
Route::patch('/', [ShopController::class, 'update'])
    ->middleware(['stateful', 'seller']);
```

It answers **403**, not 404. The endpoint exists and the caller is
authenticated; what they lack is the standing to use it. Nothing is disclosed,
because the only account being described is their own.

Two boundaries on it worth keeping:

- **It does not check approval.** A pending seller may still edit the shop they
  are being reviewed on, and widening this middleware would lock applicants out
  of fixing the thing they were asked to fix. An endpoint that genuinely needs
  an approved shop gets its own middleware, on the day one does.
- **`GET /seller` is deliberately not behind it.** That endpoint is the
  question "do I have a shop", and the answer "no" is not an error - it is the
  normal state of almost every account, asked on every page load. It answers
  200 with `data: null`.

---

## The policy is also what the frontend is told

`SellerResource` publishes `can_edit` and `can_review` by **calling the
policy**, not by restating it:

```php
'can_edit' => $viewer instanceof User && $viewer->can('update', $this->seller),
```

So the answer the API acts on and the answer the browser draws a button from
are the same answer, from the same method. A frontend that re-derived it from
an id comparison would hold a second copy of the rule - and the copy in the
browser is the one that goes stale and the one an attacker controls.

Route guards in the frontend decide **what to draw** and nothing else. Laravel
remains the only authorization boundary; a hidden button is not a control.

---

## Ownership is scoped in the query where it can be

A policy is the statement of the rule. Where a query can carry the rule
instead, it should - a check that is part of the query cannot be forgotten:

```php
$request->seller()->products()   // rather than Product::findOrFail($id)
Seller::query()->public()        // rather than fetch then check status
```

Both are used today. `PublicShopController` looks a shop up _through_
`scopePublic()`, which is why an unapproved shop is a 404 rather than something
somebody remembered to check afterwards.
