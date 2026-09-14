# 0055 - Triggering scheduled work in development

Status: accepted - 2026-09-14

ADR 0013 decided where the timing lives - in infrastructure, never in the
application - and left exactly one thing open, in its own words: "**The trigger
itself.** No Terraform exists. This ADR says what it will look like; nothing has
been built."

Three commands later, that gap had a cost. `orders:expire`,
`orders:auto-complete` and `payments:settle` all exist, are all tested, and none
of them had ever fired on a laptop unless somebody remembered to type it. An
order expiring, a parcel completing on its deadline and money settling were all
things a developer had read about rather than watched happen.

This builds the local half of the trigger. It does not build the production
half, and it is careful not to look like it has.

---

## A trigger is not a schedule

```text
a trigger    something that causes a command to run. This ADR.
a schedule   when each command runs, per task, with its own retries,
             its own last-run status and its own alerting.
             Infrastructure's, and still unwritten.
```

ADR 0013's rule is that the **schedule** is not declared in the application,
because a cron expression in Terraform and in the application is two
descriptions of one deployment. Nothing here declares one. `routes/console.php`
still registers no `schedule()` call, no PHP in this repository knows when
anything runs, and **not one file under `apps/api` changed**.

What changed is the Makefile, the development compose file and this document.

## Two ways to fire them, both opt-in

```bash
make tick          all three, once, now
make scheduler     the same three on a loop, behind a compose profile
```

`make tick` is what ADR 0013 already prescribed - "locally, that is somebody
typing it" - collapsed into one command instead of three. It is the honest
default and the one to reach for.

It stops at the first non-zero exit, which is deliberate. A scheduled command
reports failure honestly (ADR 0013), and a developer should see that rather than
have it scroll past two more runs. In production these are independent jobs and
one failing stops nothing; `make tick` is a convenience, not a model.

## The ticker is deliberately the shape ADR 0013 rejected

ADR 0013 turned down "a single scheduler firing every minute at a job that runs
`php artisan schedule:run`, with all the timings in PHP". The `scheduler`
service is that shape: one trigger, every task, one interval.

That is fine here and must never be promoted, and the reason is that **every
argument against it was a production argument**:

```text
schedule:run exits zero on a failed task   a laptop has no alerting to fool
roughly 43,000 container starts a month    one container, already running
one hanging task delays every other        nobody is waiting on a laptop
```

What a developer actually wants is for an order to expire while they are looking
at it. One loop does that. Production remains one `google_cloud_run_v2_job` with
one `google_cloud_scheduler_job` per task, exactly as ADR 0013 describes, and
nothing in this change brings that decision forward.

## Behind a profile, which is what makes it safe

`docker compose up` starts six services and not this one. Starting it takes
`--profile scheduler`, which `make scheduler` passes.

**That opt-in is the whole reason the service is allowed to exist.** A ticker
that started by default would be the application quietly acquiring a schedule:
every developer's stack would have one, the interval would drift into being
"how often things run", and the distinction this ADR spends its length on would
stop being real. Opt-in keeps it a tool somebody reaches for.

It is the first compose profile in this repository. It earns one for the reason
root `CLAUDE.md` section 13 gives for services generally: it arrives in the
change that gives it a job to do.

There is no counterpart in `docker-compose.prod.yml` and there must not be.
ADR 0013 said a scheduler service arrives there "if the compose file turns out
to be the real deployment", and that is still undecided.

## What the loop relies on, and already had

The loop is three commands and a sleep. It needs no care of its own, because
ADR 0013's four properties were built in from the first command:

```text
idempotent   a tick that repeats a tick's work is a no-op
locked       RunsExclusively, a PostgreSQL cache lock
bounded      --limit=200 on each
exit codes   non-zero only when something genuinely failed
```

A run that overlaps the next tick takes no lock, says "another run holds the
lock", and exits zero. So a ten-second interval against a slow command is not a
problem to design around - it is the contract those commands already keep, now
actually exercised rather than only tested.

There is no `|| true` in the loop. A command that fails prints its failure and
the next tick comes anyway, which is what a scheduler does.

## The interval is development-only

`SCHEDULER_INTERVAL_SECONDS` defaults to 60 and is read by the compose service.
**No PHP reads it**, which is the point - it is a property of the trigger, not
of the application.

It is in `.env.example` and in `docker-compose.yml` only, never in
`docker-compose.prod.yml`, the same way `MAILPIT_UI_PORT` is: a variable for a
service that exists in one stack belongs in that stack's allowlist and nowhere
else. Putting it in the production file would be dead configuration implying a
service that is not there.

## The healthcheck could not be copied from the queue worker

The obvious check is the queue worker's, `pgrep -f 'artisan queue'`. Applied
here it would fail for most of every interval: between ticks the container is
sleeping and there is no `artisan` process at all, so a working ticker would
read unhealthy.

It matches the shell holding the loop instead. The whole script sits in that
process's command line, so the pattern matches during the sleep as well as
during a run.

## Testing

There is nothing to unit-test: no PHP changed, and the commands' own suites
already cover idempotency, locking, bounding and exit codes. What was verified
is what this change actually claims.

`docker compose config` parses the service and reports `scheduler` as the only
profile. `docker compose ps` shows six services running and no scheduler, which
is the opt-in claim. `make tick` runs all three commands in order against the
running stack. `make scheduler` starts the container, the log shows each command
reporting on every tick, the healthcheck passes while it sleeps, and
`make scheduler-stop` leaves the rest of the stack alone.

---

## Not yet decided

- **The production trigger.** Still nothing. ADR 0013 describes the Cloud
  Scheduler and Cloud Run Job shape; no Terraform exists, and this change
  deliberately does not bring that forward.
- **Whether the compose file is ever the real deployment.** If it becomes one, a
  scheduler service arrives in `docker-compose.prod.yml` - and it will not be
  this one, because per-task cadence and per-task alerting are the whole
  difference.
- **Per-task intervals locally.** One interval fires all three. A developer who
  wants expiry every ten seconds gets settlement every ten seconds too, which
  costs nothing but is not what production looks like.
- **Telling anybody.** Unchanged from ADR 0013: an order cancelled by
  `orders:expire` still records no reason beyond the deadline. The ticker makes
  that more visible rather than worse.
