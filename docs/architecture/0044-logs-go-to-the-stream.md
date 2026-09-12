# 0044 - Logs go to the stream

Status: accepted - 2026-09-12

Laravel wrote to `storage/logs/laravel.log`, and on this laptop that file
reached 65 MB. Nothing rotated it, nothing read it, and in a deployment it
would have been written inside a container and thrown away with it.

The container writes to its own stream now, and whatever runs the container
collects it.

---

## Why a file was the wrong place

```text
on a laptop      grows until somebody notices and deletes it
in a container   lost with the container, and invisible until then
under replicas   one file per replica, none of them the whole picture
```

A log file made sense when a server was a machine somebody could log in to.
This application is a container that is replaced on every deploy, and the
Dockerfile already said as much before this decision caught up with it:
FrankenPHP was chosen partly because it is "one process serving HTTP directly,
which is one container, one log stream".

PHP's own errors already went to `/dev/stderr` in both images. Only Laravel's
logger was still writing to disk, so this makes one of the two follow the
other rather than introducing anything new.

## The format is the part that matters

```text
plain text on stderr    every line is an ERROR at the far end
JSON carrying severity  the level this application actually meant
```

Cloud Run records anything on stderr as an error unless the payload says
otherwise. A deployment logging at `debug` would therefore look like a
continuous incident, and the one thing a log collector is for - finding the
real errors - would be the thing it could not do.

So the stream carries JSON with a `severity` key, which is what Google Cloud
Logging reads. `App\Logging\CloudLoggingFormatter` adds exactly that one field
to Monolog's own JSON, and **the names need no translating**: Monolog's levels
and Cloud Logging's severities are the same eight words, DEBUG through
EMERGENCY. A mapping table here would be a table to get wrong.

Monolog's stock `JsonFormatter` was not enough on its own - it writes
`level_name`, which nothing at Google reads.

The second reason for JSON is stack traces. A trace on stderr as plain text
becomes one log entry per line at the far end, so a single exception arrives as
forty entries that have to be read back together. As JSON the exception stays
inside the entry it belongs to.

## The same format in development

Readable lines locally and JSON in production was the obvious split, and it is
not what this does.

Turning the formatter off for one environment means setting
`LOG_STDERR_FORMATTER` to an empty value, and an empty value is not "no
formatter" to Laravel: `isset()` is true, so it tries to resolve a class named
`''` and the application fails to boot. The alternative is leaving the variable
out of the development compose file, which breaks the rule that a variable
lives in `.env.example` and both compose files or it does not reach the
container at all.

Parity without a trap is worth more than prettier output from `make logs`, and
`make logs | jq` is there for anybody who wants it.

## The suite logs nowhere

`tests/bootstrap.php` pins `LOG_CHANNEL=null`, beside the array cache, the
array mailer and the sync queue it already pins for the same reason.

This one was found by making the change. The suite spends its time provoking
the refusals it asserts on, and every one of them is reported: "this is sold
out" and "only 3 of these are left" arrive as ERROR lines with a stack
attached. Pointed at the stream they buried `make test` under tens of
kilobytes of JSON, and pointed at a file - which is where they had been going
all along - they are most of how `laravel.log` reached 73 MB.

A run that needs to see them sets `LOG_CHANNEL=stderr` for that run.

Worth knowing: those lines are Laravel reporting handled domain exceptions,
the ones that become a 409 or a 422 and are a normal part of using the
marketplace. They are still reported in production, where they will cost
money to store and will sit between the failures somebody needs to find.
Whether they belong in `dontReport` is a decision of its own and is not taken
here.

## What this does not change

- **`LOG_LEVEL`.** Still `debug` in development and `warning` in production.
- **The `single` and `daily` channels.** Laravel ships them and they stay
  configured, for anybody who has a reason to write a file. Nothing selects
  them.
- **The `emergency` channel**, which still names a path. It is what Laravel
  falls back to when the logging stack itself cannot be built, and a file is a
  reasonable place for the one message that says logging is broken.
- **What is logged.** This is where log lines go, not which ones exist.

## Testing

`CloudLoggingFormatterTest`, as a unit test: the severity is present, it is the
level the record carried, and one record is one line - because a formatter that
emitted two would split every entry at the far end.

---

## Not yet decided

- **Where the logs actually land.** This decision ends at the container's
  stream. Whether that is Cloud Run's built-in collection, GKE, or the Ops
  Agent on a VM is a deployment question, and all three read the same JSON.
- **A request id.** Tying every line of one request together is worth having
  and needs a Monolog processor and something to generate the id.
- **An error tracker.** Cloud Logging is not one. If something like Sentry is
  wanted it is a second channel in the stack, not a replacement for this.
