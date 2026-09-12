# 0039 - The payouts page

Status: accepted - 2026-09-12

How a shop is paid, as a screen. The API has existed since ADR 0031 and had no
page; this is it, and it is the last part of that ADR to be built.

---

## One page, and every state on it is the API's answer

`/seller/payouts`, in the shop's section of the sidebar beside its orders and
its listings.

```text
not_started       no account. Open one, if the shop is approved.
action_required   Stripe wants something. The form below is that something.
in_review         Stripe has it and is checking. Nothing to do.
active            money can reach the shop.
rejected          Stripe will not have it, and support is the way on.
```

The status is derived by the API from the copy of what Stripe last said, never
stored (ADR 0031). `can_open` is the API's answer to whether an account may be
opened at all - it is false for a shop staff have not approved - and this page
draws it rather than asking whether the shop is public.

## The form is whatever the API says is due

`due` arrives as a list of fields in the API's own words: one `date_of_birth`
rather than three of Stripe's dotted paths. The form is that list, in that
order, and **nothing here knows what a Finn or an Italian has to provide.**
Stripe's requirements vary by country and change over time, and a copy of them
in the browser would be the copy that goes stale.

Only what is shown is sent. Every rule on the endpoint is `sometimes`, so one
endpoint serves both a first submission and a single correction months later.

Two of Stripe's asks are not text, and each has its own place on the page: the
identity document is a file, and the terms are a box.

**What the labels say is this application's to write.** The API sends field
names and no wording, so `PAYOUT_FIELDS` is a `Record<PayoutField, ...>` - a
field the API adds is a type error here until somebody writes words for it,
rather than a blank box a seller cannot get paid without filling in.

**A requirement no field answers is named, not hidden.** `unsupported` is what
Stripe asked for that this API cannot collect, and the page says so plainly:
somebody who cannot be paid should be told that the gap is ours rather than
left hunting for a form that does not exist.

## Opening an account

The country is chosen once, because Stripe will not move an account between
countries, and the hint says so before the choice rather than after. The list
is the API's (`countries` on the resource), so where an account may be opened
has one home.

The country **names** are asked of `Intl.DisplayNames` in a fixed locale, for
the reason `formatMoney` fixes one: the server and the browser have to render
the same string. A hand-kept table of twenty-odd members was the alternative,
and it would have been wrong the first time the list changed.

**Accepting Stripe's agreement is recorded here**, because there is no
Stripe-hosted page to accept it on (ADR 0031). The page says that the date,
address and browser go with it. That address is worth only what the hop in
front of this server makes it worth, which ADR 0003 now states in full.

## The identity document goes straight through

Multipart, with the content type left to the browser, as the listing
photographs are. Both sides go in one request, because Stripe wants the pair
together when it wants a back at all - a passport has none, which is why the
API's `back` is optional.

An untouched file input still serialises, and the API would read the empty part
as a file that is not one, so empty parts are removed before the request is
made. Nothing else about the file is checked here: what a document may be is
the API's rule and Stripe's, and a check in the browser would be advice.

## What this page does not show is money

No balance, no payout history, no amount held. Nothing has been charged
anywhere in this application, so there is nothing to report and inventing a
figure would be the most convincing lie on the site. The page says as much in
the `active` state: the account is ready, and nothing is paid out because
nothing is taken.

That arrives with payments (ADR 0015), and this page is what it needs first: a
shop cannot be paid before it has somewhere to be paid into.

## Testing, and what the end-to-end suite deliberately will not do

**Every write on this page reaches Stripe.** Opening an account creates a real
connected account on the platform's test-mode account; sending details and a
document are calls to Stripe too.

So Playwright covers what the page draws before any of that: the sign-in
redirect, the not-started state a seller meets first, the countries the API
allowed, the sidebar link, phone width and axe. **It does not open an
account.** A suite that did would leave a connected account behind on every
run, or delete one and hope, and it would fail whenever Stripe was slow rather
than when this application was wrong.

The writes are covered where they can be honestly: PHPUnit drives all of them
against `FakeStripe` (ADR 0031), and Vitest holds each form to what it sends -
the country and the acceptance, only the fields that were asked for, an address
as one object, a nested 422 beside the input it names, and the empty back left
out of the upload.

The one thing neither can prove is that Stripe accepts the payload, and that
was checked by hand against a test key instead: an account was created with the
real action's parameters and deleted immediately (ADR 0031).

---

## Not yet decided

- **Anything about money.** No balance, no payout list, no history. It needs
  payments to exist (ADR 0015).
- **Changing the country.** Stripe will not move an account, so the only honest
  answer is a new account, and nothing here offers one.
- **Companies.** `business_type` is `individual`, so a shop run by a company
  cannot complete this. The API decided that (ADR 0031) and the page inherits
  it.
- **Re-uploading after a rejected document.** Stripe reports it in `errors` and
  the same upload form is the way to send another, which is workable rather
  than good.
- **British bank accounts**, and **settlement currency**, both from ADR 0031
  and both still unverified against a real account.
- **The address recorded with the terms.** It is the sender's claim until an
  edge overwrites `X-Forwarded-For` (ADR 0003).
