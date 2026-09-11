# 0034 - Account settings and the address book

Status: accepted - 2026-09-11

Changing your own name, email address and password, and keeping the address
book checkout chooses from. The API had no endpoints for the first three; the
address book has had its API since ADR 0021 and now has a page.

---

## Three endpoints, because they are three different things

```text
PATCH /account            the name
PUT   /account/email      the address, which then needs confirming again
PUT   /account/password   the password, which signs out everywhere else
```

A name changes and nothing else happens. An address has to be confirmed again
before checking out or opening a shop. A password ends every other session. One
endpoint taking all three, and one save button over them, would leave somebody
unsure which of those they had just done. The settings page is three forms for
the same reason.

No id in any path: the account is the session's, so there is nothing to
substitute and no policy question to ask.

## The address and the password need the current password

The address is where a password reset goes, so changing it is how an account is
taken, and changing the password is taking it. Both need the current password
(`current_password`), and both share a limiter keyed by account, five a minute:
the attacker they are for is somebody already inside a session left open on a
shared computer, so an IP limit would not stop them.

## A new address takes effect at once, unconfirmed

The address changes immediately, `email_verified_at` becomes null, and a link to
confirm it goes to the new address, exactly as at registration. Everything that
needs a confirmed address waits for it, and already says so through the API's
answers (`checkout_blocker`, `shop_application_blocker`).

The alternative was keeping the old address until the new one is confirmed. It
is safer against a typo, and it is a second column, a second signed link and a
second route to follow it to. The session that made the change stays signed in
whatever was typed, so a typo is corrected from the same page.

Changing to the address already held is refused rather than accepted, because
accepting it would unconfirm the address and send a link for nothing. Two
accounts racing for the same new address meet the unique index, which is turned
into the same 422 the form request gives.

**The old address is not told.** A notice to the address being left is the
security half of this, and it belongs to the notifications change (ADR 0035)
rather than to a one-off mail here.

## A new password ends every other session

A password is changed because somebody else might know it, and a session they
already hold would outlive the change. So `ChangePassword` deletes every row in
`sessions` for the account except the one making the change, and rotates the
"remember me" token as a reset does. The controller then gives the current
session a new id and destroys the old one, so the id issued before the change is
treated like every other session that was.

That depends on sessions being rows in the database, which they are in both
compose files. Moving sessions to another store makes the delete a no-op, and
`ChangePassword` is where the change would have to follow them.

The new password meets registration's rules, from the same
`Password::defaults()` and the same 72-byte bcrypt limit, and has to differ from
the current one.

## The address book

`/account/addresses` lists every saved address, with a way to change or remove
each and to add another. The address form checkout uses now edits as well: a
change is a PATCH, where a field that is not sent is left alone, so an optional
part somebody cleared is sent as null rather than left out.

One entry is edited at a time, so one save cannot redraw away another form's
unsaved typing. Removing asks first, and says what it does not do: an order sent
to the address kept its own copy of it (ADR 0021).

The account's section of the sidebar is now Overview, Orders, Addresses and
Settings.

## Testing

PHPUnit covers each change and each refusal: the name endpoint setting nothing
else, a new address unconfirmed with its link sent to it, a wrong or unchanged
password, registration's rules, the limiter, and a password change deleting the
account's other session rows and nobody else's.

Vitest covers the three forms and the address book: what each sends, what each
says afterwards, refusals beside their fields, and removal asking first.

Playwright changes the shopper's name and puts it back, and adds, changes and
removes an address. The address and the password are changed on a fourth demo
account, `demo-settings@example.test`, because changing a password signs out
every other session, including the saved ones the rest of the suite runs on.
`make seed-demo` gives it back its password and a confirmed address before each
run. The test follows the link to the new address through Mailpit, moves back,
changes the password, stays signed in, and signs in again with the new one.

Its first run failed on a race worth recording: signing out is a full page load
(ADR 0025), and going to the sign-in page before it had finished aborted one
navigation with the other. The test now waits for the signed-out header.

---

## Not yet decided

- **Telling the address being left**, and telling anybody a password changed.
  Built in ADR 0035.
- **Deleting an account.** Orders refer to their buyer, and a receipt has to
  outlive the account that paid it. Nothing has decided what deleting keeps.
