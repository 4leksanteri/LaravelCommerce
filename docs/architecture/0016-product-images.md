# 0016 - Product images

Status: accepted - 2026-09-10

ADR 0009 left this open with the line "a catalogue without photographs is not a
marketplace". This is that, and it is also the first thing in the project that
accepts a file from anybody.

---

## One canonical image, not a set of sizes

Every upload is decoded, re-oriented, downscaled to 1600px on its long edge,
re-encoded as WebP, and the original is **discarded**.

The obvious next thought is to generate a set of widths for responsive
rendering. **That would be the same work done twice.** Next's image optimiser
takes one source and derives the srcset itself - it resizes on demand across the
configured `deviceSizes`, caches the results, and negotiates the format from the
`Accept` header. Handing it four sizes it did not ask for gets four times the
storage and no benefit.

1600 is chosen to sit comfortably above anything the optimiser will request, so
it never scales up from a source that is too small.

The place that changes is a CDN in front of object storage, where you might want
sizes rendered without a Node process in the path. That is an optimisation for
traffic this will never see, and the canonical original is what you would
generate them from anyway.

## Why re-encode at all, then

Four reasons, and the last is the one that would be a defect if it were missed.

- **One format.** Everything downstream gets WebP. Nothing has to branch on
  what a seller happened to have on their phone.
- **A size cap at the door.** A twelve megabyte photograph becomes a couple of
  hundred kilobytes. It is also what the optimiser reads from, so a smaller
  source is a faster first render.
- **Orientation is applied, not described.** A phone photograph is landscape
  bytes plus an EXIF tag saying "turn this". `orient()` bakes the rotation in
  and the tag goes away, so nothing downstream has to know.
- **EXIF is stripped, and this one matters.** A photograph taken on a phone
  carries GPS coordinates. A seller listing a loaf from their kitchen table
  would otherwise publish their home address with it. `WebpEncoder(strip: true)`
  is doing real work.

---

## On the product, not the variant

A sourdough in two sizes is one photograph. A shirt in two colours is arguably
two, and that is the case variant-level images exist for.

Product-level covers the catalogue as it stands, and adding a variant reference
later is additive - a nullable column on `product_variants` pointing at an image
already on the product - rather than a migration that moves files.

There is no `is_primary` flag. The first by `position` is the one a grid shows,
because an order needs no rule to keep exactly one of them true.

---

## Served by a route, because the proxy forwards exactly one prefix

`filesystems.local.serve` would register `GET /storage/{path}`. That sits
outside `api/v1`, which is the only thing the Next.js server proxies (ADR 0003),
so nothing could reach it - and `ApiSurfaceTest` would fail on it. The config
comment on the `local` disk said as much before there was anything to store.

So images are streamed by `GET /api/v1/images/{key}`, and three things follow:

**The key is a UUID, not the id.** An image URL is handed out and cached, so it
must not be guessable: sequential ids would let anybody walk the catalogue,
including photographs on listings that are still drafts. A random key is also
exactly what an object-storage URL is, so moving to a bucket changes where the
bytes live and not what a URL looks like.

**There is no authorization check, deliberately.** An image on a published
listing is public by definition, and one on a draft is reachable only by
somebody who already has its key. That is security through an unguessable
identifier, which is what every public bucket URL is, and it is stated here
rather than left as an omission.

**It is cached hard.** The bytes at a key never change - a re-encode produces a
new row with a new key - so `max-age=31536000, immutable`. Without that, every
thumbnail on a catalogue page is a PHP process.

**The URL is relative.** An absolute one would be built from `APP_URL`, which is
this application's own origin and unreachable from a browser. Relative paths go
to the Next.js origin and through the proxy, which is the whole arrangement in
ADR 0003 - and it lets `next/image` treat them as local, with no allowlist.

---

## Each row remembers its own disk

`product_images.disk` is stored per row rather than read from config when
serving.

A marketplace that moves to object storage still has to serve everything
uploaded before the move, and a config value cannot describe two eras at once.
`ProductImage::url()` is the single place that turns a row into a URL, so when
a bucket arrives it answers a bucket URL for rows on that disk and this one for
everything else.

This is also why `PRODUCT_IMAGE_DISK` exists as configuration with nothing but
one value to choose from today. It is the switch, and it is wired before it is
needed because wiring it afterwards means a data migration.

---

## Validation is about the file, not about what was said

`mimes:` resolves the type from the file's own bytes through fileinfo. It does
**not** read the `Content-Type` the browser attached, which is a client-supplied
string (root `CLAUDE.md` section 11).

That still leaves a gap the rule cannot close: a file whose header is a real
JPEG and whose body is truncated passes validation and dies in the decoder. A
500 for a half-uploaded photograph is a bug report; the decode is wrapped and
answers **422**, because unlike the other refusals here it genuinely is a fault
in what was sent.

The count limit is the opposite case and answers **409**: the file is fine,
there is nowhere to put it (ADR 0008).

---

## Not yet decided

- **Object storage.** A `fake-gcs-server` container in development and a GCS
  disk in production, so the Terraform work later has something to point at. The
  `disk` column and `ProductImage::url()` exist so that change is additive.
  Until then a container's disk is its own, which makes the production stack
  single-replica by implication.
- **Variant images.** See above; additive when wanted.
- **Shop images.** A banner and an avatar. Same machinery, different owner.
- **Anything about moderation.** Nothing looks at what is in an uploaded
  photograph, and a marketplace that accepts images from the public eventually
  has to.
