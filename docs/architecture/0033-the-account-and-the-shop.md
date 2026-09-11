# 0033 - Your account and your shop, in one layout

Status: accepted - 2026-09-11

Where a signed-in person's own pages live: their account, their orders, and
their shop once they have one.

---

## One layout, inside the site rather than instead of it

The design export draws the seller's side as an application of its own: a dark
sidebar against the edge of the screen, its own brand line, and no site header.
It draws a buyer's orders as a two-column page of their own again. Both are
replaced by one layout, asked for directly by the owner of the project: the
site's header and footer, the centred page, a `w-80` column of links on the
left, and the page in the rest of the width.

It is also what root CLAUDE.md section 6a implies. Selling is not a role here: a
shop owner is a customer who also has a shop and buys with the same account. Two
applications would have them switch between one to check an order they placed
and another to check an order they received.

## A route group, so it is one file

`app/(shop)/(dashboard)/layout.tsx` draws both `/account/**` and `/seller/**`.
The sidebar has two sections:

```text
Your account   Overview, Orders
Your shop      Overview, Shop settings        or, with no shop, "Open a shop"
```

The shop's section grows as the shop's pages are built. Listings, the shop's
orders and payouts each add a link when their page exists and not before: a link
to nothing is the dead link the header and footer already refuse to carry.

The current link is marked with `aria-current`, from `usePathname`, because a
layout is not given the path. An overview is marked on its own address only, and
every other link on its address and anything beneath it after a slash, so an
order's own page keeps Orders marked and `/sell` is never current on `/seller`.
At phone width the column becomes rows of links above the page.

**The layout guards nothing.** It is not told the path, so it cannot send
somebody to sign in and back to the right place. Every page calls `requireUser`
with its own address, as before.

The account's address is `/account`, singular, because it is one person's own.

## The orders moved under the account

`/orders` is now `/account/orders`, and an order is `/account/orders/[reference]`.
There are no redirects from the old addresses: this project has never been
deployed (ADR 0015), so nothing outside it links to them. The header's "Orders"
became "Your account".

`OrderActions` used one variable for the API's address of an order and the
page's. They were the same string until the move, and the test for a lapsed
session is what would have caught them parting: it now expects the sign-in page
to send somebody back to `/account/orders/...`.

## Applying: one form, and the API's answer about whether it can be sent

`/sell` is the application, outside the layout, because until it is sent there
is no shop for the layout's shop section to show. It is one form, with
`ApplyToSellRequest`'s four fields: the shop's name, what it says about itself,
a contact address and the currency. The export's four steps ask what kind of
goods, how many and shipped from where, none of which anything records, and
then for payouts, which ADR 0031 puts after approval rather than before it.

**Whether the form can be sent is the API's answer.** `shop_application_blocker`
on the signed-in user is `unverified_email`, `awaiting_review`, `already_open`,
or null, in the order the application itself refuses: the `verified` middleware
first, then ApplyToSell. It follows `checkout_blocker` (ADR 0030): the page
reads the answer rather than reading `email_verified_at` and the shop's status
and deciding. ShopApplicationBlockerTest asks the answer and the application the
same question for five kinds of account.

A rejected applicant has no blocker. They get the form again, filled from their
application, with the reason they were given. The currency is shown rather than
offered, because the API keeps the first one whatever is sent.

**The currencies' names are the frontend's.** Payout countries arrive from the
API (ADR 0031) because which ones are allowed is a rule. What "SEK" is called is
presentation, so the form holds a `Record<Currency, string>`: a currency the API
adds is a type error in the form until it has a name.

**Success is a full page load** into the shop (`loadFresh`, ADR 0030). The
header above the form was drawn for an account without a shop and would go on
saying "Open a shop" after a client-side navigation.

## The shop's overview and its settings

The overview says where the shop stands and what that means: waiting on review,
with its details still editable; not approved, with the reason and the way to
apply again; or open, with how many listings it has and how many orders it has
taken. The export leads with money held, money released and a rating. No money
is taken (ADR 0015) and there are no reviews, so the figures are the two the API
has, drawn when `is_public` says the shop is trading.

Settings is `PATCH /seller`'s three fields, with the two fixed facts shown as
fixed: the currency, and the shop's address in links. Saving calls
`router.refresh()`, which draws layouts again, so a new name reaches the sidebar
beside the form.

Nothing tells an applicant that staff have decided. No mail is sent, and the
shop's page is the only place that says.

## Form controls beyond an input

`Field` wired a label, a hint and the API's messages to an input, and only an
input. That wiring is now `FieldFrame`, which hands it to whatever control it
holds, and `Field` is a `FieldFrame` with an `Input` inside, unchanged for every
form that uses it. `Textarea` and `Select` are the new primitives. The select is
the browser's own: accessible without effort, it opens a phone's picker, and the
lists are short.

## Testing

Vitest covers what these components promise: SideNav's rules for which link is
current; the application form (no currency chosen for anybody, the four fields
sent and then a full page load, a 422 beside its field with what was typed
kept, and a fixed currency on a second application); and the shop's details
form.

Playwright has an account spec and a seller spec. Applying needs an account
with a confirmed address and no shop, and an application cannot be withdrawn,
so there is a third demo account, `demo-applicant@example.test`, which
`make seed-demo` returns to having no shop on every run. Registering a fresh one
per run instead would spend the hourly registration limit the suite works
within (ADR 0025). The setup project now signs in three accounts.

The phone-width and axe checks for pages behind a session are one helper,
`expectFitsAPhoneAndPassesAxe`, instead of the same dozen lines in each spec.

---

## Not yet decided

- **Settings for the account itself.** Name, email and password have no
  endpoints. The address book does (ADR 0021) and has no page; the account's
  section gains a link when one exists.
- **Telling an applicant.** No mail on approval or rejection, as there is none
  for an order (root CLAUDE.md section 20).
- **The shop's listings, orders and payouts.** Next, each adding its link.
