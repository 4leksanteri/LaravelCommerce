# 0045 - A refusal is not a failure

Status: accepted - 2026-09-13

ADR 0044 pointed the log at the container's stream, and doing so made a second
thing visible: most of what this application logged was not a problem.

```text
This is sold out.
Only 3 of these are left.
The order has moved on, and no longer allows this.
```

Each arrived as an ERROR, with a stack trace attached, from a marketplace that
was working correctly at the time.

---

## What was actually being reported

Only our own exceptions, and that is worth stating precisely because ADR 0044
guessed wider and guessed wrong. It said the noise was "the ones that become a
409 or a 422". The 422s were never in it: Laravel's own `internalDontReport`
already covers `ValidationException`, and `AuthorizationException`,
`HttpException` and `ModelNotFoundException` with it, so a validation failure, a
403 and a 404 have always been silent.

What was left was exactly the twelve domain exceptions this application throws,
every one of which `bootstrap/app.php` renders as a **409**. That status is the
definition of the problem: the caller was allowed, what they sent was valid, and
the state of the world is what refused them. None of the twelve means anything
is broken.

## They carry the marker on a shared base

```php
abstract class DomainRefusal extends RuntimeException implements ShouldntReport
```

`Illuminate\Contracts\Debug\ShouldntReport` is the framework's own marker, and
`Handler::shouldntReport()` checks it before anything is written.

Two alternatives were rejected:

**A `dontReport` list in `bootstrap/app.php`.** It would be a second list of
the same twelve classes, sitting a few lines from the twelve `render()` calls
that already name them, and nothing keeps two lists in step. The thirteenth
exception would be rendered and reported.

**The marker on each of the twelve.** Same outcome, and it has to be remembered
every time somebody adds a class. A rule that must be remembered is a rule that
is eventually forgotten, which is the reasoning `Seller::scopePublic` gives for
carrying a condition inside the query rather than beside it.

On the base, the next domain refusal is silent by construction, and the reason
is written down once in a class whose name says it.

## What still reports

Everything that is not one of these. A Stripe call that fails, a database that
will not answer, a bug: all unchanged, and all still written to the stream with
a stack.

The one deliberate report this application makes by hand is also unchanged -
`CheckoutController` catching a Stripe failure after the orders were committed
and calling `report()` on it (ADR 0040). That is a real failure with a
consequence, and it should be found in a log.

## Testing

`DomainRefusalsAreNotReportedTest` provokes a real 409 over HTTP - five of
something with one in stock - and asserts the logger heard nothing.

It carries a **control**, and needs one: an assertion that nothing was logged
passes just as well when the test is watching the wrong object. The second test
reports an ordinary `RuntimeException` through the handler and asserts the spy
does see that, so the silence in the first is the marker working.

---

## Not yet decided

- **Counting them.** Silencing these lines also gives up the only record of how
  often buyers hit a sold-out listing, and that number is worth knowing. It is
  a metric rather than a log line, and there is nowhere to put a metric yet.
- **Sampling.** If a refusal ever needs to be visible while debugging, a
  channel that keeps them at `debug` is a smaller change than undoing this.
