# 0048 - Product images live in a bucket

Status: accepted - 2026-09-13

ADR 0016 stored photographs on a private local disk and wrote down what that
cost: "a container's disk is its own, which makes the production stack
single-replica by implication". This is the change it named, taken because the
deployment target is Cloud Run, where the filesystem is not merely per-instance
but disposable.

---

## Nothing in the application had to change

That is the result worth recording, and it was not luck. ADR 0016 made three
decisions that turned this from surgery into configuration:

```text
every write goes through Storage::disk()      no local paths anywhere
product_images.disk is recorded per row       two eras can coexist
ProductImage::url() is the one place a row    the URL shape never moved
  becomes a URL
```

So there is no data migration and no rewrite: a second disk is configured, the
switch that already existed is pointed at it, and every row written before today
keeps answering from the disk it names. `ProductImageDiskTest` drives the whole
life of a photograph - stored, served, deleted - against the bucket, and ends
with a row from before the move still being served from the local one.

## Native GCS rather than the S3 interoperability API

Google's buckets speak an S3-compatible API, and Laravel ships an `s3` driver,
so the cheapest possible version of this change is configuration alone with no
code at all.

It was not taken, and the reason is credentials. S3 interoperability
authenticates with an **HMAC key pair**: a long-lived secret that has to be
created, stored, delivered to the container and rotated. The native SDK uses
**Application Default Credentials**, which on Cloud Run is the service account
the revision already runs as.

```text
s3 against GCS   no code, one more secret to hold and rotate
native gcs       ~15 lines registering a driver, no secret at all
```

Fifteen lines of `Storage::extend` against a credential that can leak from an
environment variable, an image layer or a Terraform state file is not a close
trade. It also means the bucket's access is expressed as IAM on the service
account, which is where the eventual Terraform will want it.

## The bucket stays private, and bytes keep flowing through the API

Nothing here is public, and no object URL is handed out. Images are still
streamed by `GET /api/v1/images/{key}`, which checks the signature itself -
exactly as ADR 0016 decided, and for the reason it gave: the behaviour is then
identical against a local disk, a bucket or an emulator, and a CDN can cache the
public case without understanding any of it.

**The cost is worth stating plainly.** On Cloud Run every thumbnail is served by
a PHP process rather than by the bucket, so image traffic is instance time.
Public images are already `max-age=31536000, immutable`, so a CDN in front of
that route removes almost all of it - which is the answer when there is traffic
to justify it, and remains the thing to do before the bill is a surprise.

Serving straight from the bucket would mean either public objects, which the
draft case forbids, or minting GCS signed URLs, which moves the authorization
decision out of this application and into storage. Neither is worth doing before
a CDN is.

## Development writes to a bucket too

`fake-gcs-server` runs in the development stack and `GCS_API_ENDPOINT` points
the SDK at it, with anonymous credentials, which is why no credential appears
anywhere in the local stack.

**That variable is this application's, and the first attempt assumed otherwise.**
`STORAGE_EMULATOR_HOST` is honoured by Google's Go and Python clients and is the
thing every tutorial sets; the PHP client does not read it at all - there is not
one occurrence of the name under `vendor/google/`. Every call went to Google
instead and came back "The specified bucket does not exist", which is precisely
what an anonymous lookup of a bucket name in somebody else's project looks like,
and reads like a broken emulator rather than a client that never spoke to one.

`apiEndpoint` is the option it actually has. Anonymous credentials go with it,
because a machine pointed at an emulator has no metadata server to ask and ADC
would fail before the first request.

**Development defaults to the bucket rather than the local disk**, so the path
production takes is the path a developer exercises every time they add a
photograph, and the one the end-to-end suite drives. A code path only production
runs is a code path nobody has run.

The local `products` disk stays configured. It is what rows written before this
change point at, and what the test suite uses.

## The suite stays on the local disk

`tests/bootstrap.php` pins `PRODUCT_IMAGE_DISK=products`, beside the array
cache, the array mailer and the sync queue it already pins. A suite that
inherited the development default would need a container running before it could
store a photograph, which is the same objection that puts Stripe behind a fake
and mail in an array.

`Storage::fake()` replaces whichever disk a test names, so this costs no
coverage: `ProductImageDiskTest` fakes `products_bucket` and asserts the
behaviour is identical, which is the actual claim.

## Testing

PHPUnit: an upload is written to the configured disk and the row records which;
it is served by the same route with the same headers; deleting it removes it
from the disk it was written to; and a row from before the move is still served
from its own disk.

Nothing in the suite talks to Google or to the emulator. What the emulator
proves is that the driver, the adapter and the SDK work against a real JSON API,
and that is proved by the development stack and the end-to-end suite running on
it.

---

## Not yet decided

- **A CDN in front of the public route.** The one change that stops image bytes
  costing instance time, and the reason the cache headers already say what they
  say.
- **Moving the images that exist.** Rows written before today stay on the local
  disk and are served from it. A copy-and-restamp is a script and a migration,
  and it is not worth writing until there is a deployment whose old images
  matter.
- **Lifecycle rules and a retention policy.** A deleted listing's photograph is
  removed from the bucket today; nothing expires anything else, and nothing
  versions it.
- **Shop images.** Same machinery, different owner, as ADR 0016 said.
