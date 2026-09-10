# 0013 - Scheduled work

Status: accepted - 2026-09-10

The first thing in this application that runs without somebody asking it to.

The decision is not about orders. It is about **where the timing lives**, and it
is taken now, before there is pressure to reach for the convenient answer.

---

## The command is the unit. The trigger is deployment configuration.

```bash
php artisan orders:expire --limit=200
```

That is the whole of the application's involvement. It runs once, does a
bounded amount of work, and exits with a status code. It does not know what
started it.

Locally, that is somebody typing it. In production it will be Google Cloud
Scheduler firing a Cloud Run Job. Neither is named anywhere in `apps/api`, and
changing the trigger changes no PHP.

This is what makes the infrastructure decision safe to defer: Terraform arrives
later, and nothing about the command has to be revisited when it does.

---

## Four properties, and why each is not optional

Every scheduler worth using delivers **at least** once. A network timeout that
loses the response looks identical to a run that never happened, so it fires
again. Everything below follows from that.

**Idempotent.** A second run over the same window must be a no-op.
`orders:expire` only touches pending orders, and cancelling one makes it not
pending. `ExpireOrdersTest` runs it twice and asserts the stock came back once.

**Locked.** Two overlapping runs must not both work the same rows. A cache lock
does it, and `CACHE_STORE=database` means the lock lives in PostgreSQL and holds
across replicas and across whatever fired it.

Laravel's own `withoutOverlapping()` would **not** work here, and the reason is
easy to miss: it applies when Laravel's scheduler is running the command, and
here it will not be.

**Bounded.** A batch is capped. Without that, a backlog produces a run that
exceeds the job timeout and is killed halfway through - which is survivable only
because of the first property, and is still a run that never reports success.

**Honest exit codes.** Non-zero when anything failed, so the scheduler shows it
red and retries. A run that cannot take the lock exits **zero**: overlapping is
expected rather than exceptional, and a red mark for something normal trains
people to ignore red marks.

---

## The schedule is not declared in the application

`routes/console.php` registers nothing about when. There is no `schedule()`
call, and there should not be one while infrastructure owns the timing.

A cron expression in Terraform **and** in the application is two descriptions of
one deployment, which is the trap root `CLAUDE.md` section 12 describes for
`.env` files: one of them gets edited, and nothing happens.

The cost is that adding a scheduled task needs an infrastructure change
alongside the code change. That is the right friction. A thing that runs
unattended and can fail silently should be declared where it is monitored.

---

## Cloud Scheduler drives a Cloud Run Job, not an HTTP endpoint

The obvious pattern is Cloud Scheduler making an authenticated HTTP request to
the application. It is the wrong one here, and specifically because of ADR 0003.

The Next.js server proxies `/api/**` wholesale, so a task endpoint under
`/api/v1` is reachable from any browser. Putting it outside the prefix means a
second exception in `ApiSurfaceTest`, which today allows exactly one - `/up`,
the container health probe. Either way it opens an ingress on a service whose
entire security story is that it has none.

A Cloud Run Job needs no HTTP surface at all. Same image, different entrypoint,
runs to completion:

```hcl
resource "google_cloud_run_v2_job" "tasks" {
  template { template { containers {
    image = var.api_image                      # args supplied per execution
  }}}
}

resource "google_cloud_scheduler_job" "task" {
  for_each = local.scheduled                   # one entry per task
  schedule = each.value.schedule
  http_target {
    uri  = "https://run.googleapis.com/v2/${google_cloud_run_v2_job.tasks.id}:run"
    body = base64encode(jsonencode({
      overrides = { containerOverrides = [{ args = each.value.args }] }
    }))
    oauth_token { service_account_email = google_service_account.scheduler.email }
  }
}
```

The scheduler authenticates to the Run API with its own service account. The
container is never on the internet.

## One job definition, one schedule per task

The tempting alternative is a single scheduler firing every minute at a job that
runs `php artisan schedule:run`, with all the timings in PHP. One infrastructure
resource forever, and adding a task is a code change.

It was rejected for a concrete reason: **`schedule:run` reports a failed task
and carries on, exiting zero.** A payout run that throws would leave a green
tick in Cloud Scheduler and an exception buried in logs, and the alerting would
have to be rebuilt by hand. The rest is cost and blast radius - roughly 43,000
container starts a month to mostly do nothing, and one hanging task delaying
every other task on the same tick.

So: one `google_cloud_run_v2_job`, with the artisan command supplied per
execution as an argument override, and one `google_cloud_scheduler_job` per
task. Per-task retries, per-task last-run status, per-task alerting. Adding a
task is one map entry and one command class.

---

## Nothing runs it in the compose stack

`docker-compose.prod.yml` has three services and none of them is a scheduler.
**Scheduled work does not run there**, and that is stated rather than fixed,
because the production target is being decided and adding a service to the
wrong one is worse than adding none.

Root `CLAUDE.md` section 13 says a service arrives in the change that gives it a
job to do. If the compose file turns out to be the real deployment, the job now
exists and a scheduler service - or host cron running
`docker compose exec api php artisan orders:expire` - arrives with that
decision.

In development, run it by hand:

```bash
make artisan ARGS="orders:expire"
```

---

## Queued work is a different problem, and is not decided here

"Background jobs" usually means two things, and only one of them is this ADR.

**Scheduled** is time-triggered and cron-shaped. That is everything above.

**Queued** is event-triggered: "email the seller when an order is placed". It
needs a listener, and Cloud Run Jobs are the wrong shape for one - the options
are a Cloud Run service with a warm instance running `queue:work`, or Cloud
Tasks pushing over HTTP, which drags back the ingress problem above.

There is no queue worker today. `QUEUE_CONNECTION=database`, the table exists,
and nothing consumes it - mail sends synchronously inside the request. That is
tolerable while the only mail is registration and password reset. **It stops
being tolerable the moment order notifications land**, because a slow SMTP call
would then sit inside a checkout.

Deciding that is its own change, and calling it "background jobs" alongside this
one would let a much bigger question ride along on a smaller answer.

---

## Not yet decided

- **The trigger itself.** No Terraform exists. This ADR says what it will look
  like; nothing has been built.
- **Queued work.** Above.
- **Auto-completion.** ~~A shipped order nobody confirms stays shipped
  forever.~~ **Built in [ADR 0014](0014-completing-an-order.md)** as
  `orders:auto-complete`. With two scheduled commands, the lock-and-exit
  contract above moved into a `RunsExclusively` trait so a third gets it
  structurally rather than by being remembered.
- **Telling anybody.** An order cancelled by `orders:expire` is
  indistinguishable, to its buyer and its seller, from one cancelled by hand -
  there is no reason recorded and no notification sent. Both are gaps ADR 0012
  already lists, and expiry makes the first one sharper: the platform is now
  cancelling orders and saying nothing about why.
