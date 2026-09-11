# 0023 - The auth screens, and the components they left behind

Status: accepted - 2026-09-11

The first pages in this repository that a person actually uses. Six of them:
sign in, create an account, ask for a reset link, choose a new password, confirm
an address, and the page that says an email is on its way.

They were chosen first because ADR 0019 said so - _"components come out of the
auth screens next"_ - and because two of them are already linked from mail this
application sends. `/verify-email` and `/reset-password` have existed as
addresses in people's inboxes since ADR 0002, with nothing at the other end.

---

## The design export has none of these

It covers home, search, a product, cart and checkout, order tracking, the seller
application, the seller dashboard and messages. No sign-in form anywhere.

So these are the first screens derived from the design language rather than read
off a picture of it: the tokens in `globals.css`, the reasoning in ADR 0019, and
nothing to copy. That is the useful test of whether ADR 0019 was worth writing,
and it was - "cool slate, blue for the control that commits money, 8px radius,
one family" is enough to build a page from.

## Forms submit from the browser, not through a Server Action

This is the decision the rest follow from, and the framework's own default
points the other way.

A Server Action would run on the Next server, which would then have to obtain a
CSRF token, call Laravel, and forward Laravel's `Set-Cookie` back to the browser
by hand. **Every one of those already exists exactly once** - in `apiFetch` and
in the proxy at `app/api/[...path]/route.ts`, where ADR 0003 put them and where
`apps/web/CLAUDE.md` says CSRF is handled "and must not be handled anywhere
else". A Server Action would be a second copy of all three, and the session is
the thing least able to survive two implementations that disagree.

So the forms are Client Components calling `apiFetch`. The session cookie is set
on the browser by the proxy, the same way it is for every other write in the
application, and there is nothing to keep in step.

**Submission is handled explicitly rather than by giving `<form>` an action.**
The requirement is that a refusal must not cost somebody what they typed: a
wrong password should leave the email address in the field. Owning the values
guarantees that without depending on what the framework does to an uncontrolled
input when an action settles.

## Nothing mirrors the session

There is no auth context, no user in client state, no "are we logged in" flag.
`currentUser()` asks `GET /auth/me` and that is the only answer.

A copy would be a second source of truth about the one thing the API is most
entitled to decide, and root `CLAUDE.md` section 4 already rules on it: the
browser's copy is the one that goes stale and the one an attacker controls. A
401 from that call means "nobody" and is not an error; every other status is
left to throw, because rendering a page as though nobody is signed in when the
API is merely broken would quietly sign people out.

---

## What got extracted

Five primitives, and each is on a screen that exists.

```text
Button      primary, secondary, ghost. No destructive: nothing destroys
            anything yet, so the variant would have no caller.
Input       error styling driven from aria-invalid, so the way it looks and
            the way it reads cannot disagree.
Field       label, hint, input, and the messages Laravel sent about it.
Alert       danger, positive, info. Chooses its own aria role by tone.
TextLink    thin, and earns its place by owning the focus ring.
```

`Field` is the one worth justifying. Laravel answers 422 with
`{ errors: { field: [messages] } }`, and putting those beside the field they
name means wiring `aria-describedby` and `aria-invalid` on every input - which
is the sort of thing that gets done on three fields out of five unless one
component owns it. It renders **every** message for a field rather than the
first, because "too short" hiding behind "found in a breach" helps nobody.

`useApiSubmit` is the same argument for the other half. Five screens classify
the same refusals, and 401, 403, 419 and 429 each mean something different to a
person (`apps/web/CLAUDE.md` section 9). Collapsing them into "something went
wrong" is how a recoverable state becomes a dead end.

Three dependencies arrived with this: `clsx`, `tailwind-merge` and
`class-variance-authority`. They are shadcn's contract rather than a preference,
because a primitive copied in from shadcn calls `cn()` and expects `cva`.
Without `tailwind-merge` every component that accepts a `className` is one whose
padding cannot be overridden. No Radix yet: `asChild` has no caller, so
`buttonStyles` is exported for a link to borrow instead.

## `?next=` is sanitised, because it is attacker-controlled

An unchecked redirect target is how a phishing link sends somebody through a
real sign-in page and out to a convincing copy, carrying the trust of having
just authenticated on the genuine site.

Only a single-slash path on this origin is honoured. `//elsewhere.test` and
`/\elsewhere.test` are refused rather than repaired - a browser resolves both as
another origin while they read as paths, and rewriting a hostile value tends to
produce a different hostile value.

## An unverified account is not a locked one

`/verify-email/sent` tells somebody what is waiting for them and does not stand
in their way. Browsing, filling a basket and applying for a shop are all open;
`verified` sits on checkout alone, because that is where an address stops being
a detail and becomes where the receipt goes (ADR 0011).

---

## A claim that turned out to be wrong

Both this application and the API carried a comment saying the verification
link's query must be `expires` then `signature` **because reordering it
invalidates the signature**. Verifying the round trip live disproved it: the
reversed pair validates fine.

Laravel drops `signature` from the raw query string and rejoins what is left in
request order. With only `expires` left there is nothing to reorder. The order
is fixed at both ends anyway, and the comments now say why: a third parameter
would make it load-bearing immediately, and the failure mode would be a link
that works everywhere it is built one way and 403s everywhere it is built the
other.

Worth recording as a method note rather than only a fix. The comment was
plausible, sat next to working code, and had been read several times; it took
running the thing to find out.

---

## Not yet decided

- ~~**Any header or navigation.**~~ **Built in
  [ADR 0024](0024-the-shell-and-the-front-door.md)**, as a `(shop)` route group
  beside this one - so these pages keep having none. "Your shop" is drawn from
  `has_shop`, as planned.
- ~~**Where sign-out lives.**~~ **In that header**, as a button rather than a
  link, because ending a session is a write and a link prefetch could trigger
  one.
- **Guarding pages behind a session.** `currentUser()` exists and only the auth
  screens use it. There is no middleware and no convention yet for "this page
  needs somebody signed in"; the first page that needs it decides.
- **Rate limiting as a thing a person sees.** 429 is rendered as "wait a
  minute", and the API sends no `Retry-After` for the page to be specific with.
- **Tests.** There are none for the frontend, and there is no test runner in
  `apps/web` at all. The auth screens are the first thing worth having one for,
  and choosing it is its own decision.
