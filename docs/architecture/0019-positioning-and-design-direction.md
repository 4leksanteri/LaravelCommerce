# 0019 - Positioning, and the design direction that follows

Status: accepted - 2026-09-10

Supersedes [ADR 0018](0018-design-direction.md).

A design export arrived at `docs/design/exports/LaravelCommerce.html`, and
reading it settled something bigger than a palette: **what this marketplace is
for.** The surface decisions below all follow from that, and most of them
reverse 0018.

---

## Escrow is the product, not a feature

The export leads with it:

```text
LaravelCommerce - marketplace with escrow
Buy from small shops. Pay only when it arrives.
```

And the catalogue it shows is secondhand cameras, lenses, audio and
instruments - "Olympus OM-1 35mm SLR with 50mm f/1.8 Zuiko - serviced",
"Northlight Analog".

**That is the category where escrow is the reason to exist.** Nobody needs funds
held to buy a loaf of bread; everybody wants them held when sending six hundred
pounds to a stranger for a camera that might not arrive and might not work. The
architecture already built - funds held on the platform, released when the buyer
confirms receipt (ADR 0011, 0012, 0014, 0015) - stops being over-engineering and
starts being the pitch.

Two consequences run through everything below.

**Trust is the interface's job.** A shopper's question is not "is this pretty",
it is "will I lose my money". So the surface reads as considered and technical
rather than handmade and warm, and the escrow state is shown rather than
explained in a footer.

**The listing carries specifics.** A camera has a model, a serial, a condition,
a service history. That is dense information, and the export is dense
accordingly - most of its text sits at 12-14px, not 16px. It is a catalogue to
be read, not a gallery to be admired.

`CategorySeeder` is re-seeded to match: cameras and optics, audio, instruments,
computing, watches, bicycles.

---

## The palette is cool, and it is blue

Taken from the export rather than invented.

```text
background     #eef0f4    cool ground
card           #ffffff
foreground     #121826    navy-black
secondary-fg   #3b4354
muted-fg       #5b6474
subtle         #8a93a5
border         #dfe4ec
muted surface  #f1f4f9
```

Nothing here is warm. `#121826` is a near-black with blue in it, and every grey
above it is cool. Beside photographs of metal, glass and black plastic that is
right; the warm paper of 0018 would have made every camera look slightly
yellowed.

## The primary action is blue

```text
primary        oklch(50% 0.19 250)
primary-fg     #ffffff
ring           oklch(50% 0.19 250)
accent         oklch(95% 0.03 250)   a tint, for hover and quiet surfaces
accent-fg      oklch(45% 0.19 250)
```

0018 said the primary action should be ink, because a coloured button makes a
considered page look like a template. **That was right for the marketplace it
described and wrong for this one.** Buying secondhand is a decision, and the
control that commits money should be unmistakable rather than tasteful. Blue is
also the colour of every "this is safe" affordance on the internet, which is
exactly the association this product wants.

## The states this domain has

```text
positive       oklch(45% 0.13 150)   released, delivered, in stock
caution        oklch(45% 0.12 70)    awaiting you, running out
destructive    oklch(50% 0.19 25)    cancel, refuse
```

The green and amber are the export's own hues. **The red is derived** - the
screens exported contain no destructive action, so it is placed in the same
lightness and chroma family as the blue so it belongs rather than shouts.

Amber does double duty as the rating colour in the export. It gets its own token
when reviews exist, and not before.

---

## Manrope, one family

```text
sans   Manrope         everything
mono   ui-monospace    order references
```

The export uses Manrope alone, and it is the right call for this product: a
geometric sans that reads as precise. 0018's serif pairing said "made by hand",
which is a claim this marketplace is not making.

Mono is kept for order references and serials - `8Y9JN63MTC` is a string people
read down a telephone - but as a **system stack** rather than a fourth webfont.
A typeface download to render ten characters is not a trade worth making.

## Softer corners than 0018 wanted

`--radius: 0.5rem`. The export's dominant radius is 8px, with 12-14px on cards
and pills on chips.

0018 argued for 4px on the grounds that it reads editorial. This is not a
catalogue to leaf through, it is an application to transact in, and 8px is what
that reads as.

---

## What carries over from 0018 unchanged

Three decisions survive the reversal, and it is worth saying which.

**Light only.** Same reasoning, and stronger here: product photographs of
equipment are shot on white by everyone who sells equipment.

**The token contract is shadcn's, the values are ours.** Renaming
`--muted-foreground` to something more evocative still means hand-editing every
component brought in, forever, for nothing anybody can see.

**Components are extracted, not designed in advance.** The export is a
_reference to build from_, not markup to lift - it is a bundled React app with
its own runtime. Screens get rebuilt in our components, against these tokens,
and the library is what falls out.

---

## What the export assumes and the API does not have

Reading it is also the clearest inventory yet of what is missing. Four things it
shows have no backend at all:

```text
search              no endpoint. ADR 0009 and 0017 both list it as undecided.
messages            no domain. An entire screen in the export.
reviews, ratings    no domain. Twenty-eight references.
shipping, tracking  `shipped_at` and nothing else - no carrier, no tracking
                    number, and no delivery address anywhere in the schema.
```

Plus payouts, which ADR 0015 decides and does not build.

**That is four more backend chapters, not frontend work.** The screens that are
actually fed today - home, product, cart, checkout, orders, seller application,
seller dashboard - are most of the export, and they are what gets built first.
Order tracking is the one that will look convincing and mean little until
addresses exist.

> **[ADR 0021](0021-addresses.md) built the addresses.** The rest of that line
> stands: an order now knows where it went, and still nothing records a carrier
> or a tracking number, so the screen has a destination and no journey.

---

## Not yet decided

- **A type scale.** The export is dense - 11 to 15px for nearly everything - and
  Tailwind's default scale does not have a 13px step. Whether to add one or
  round to `text-sm` is a decision for the first screens, not for a token file.
- **Elevation.** The export uses borders and flat white cards almost throughout.
  Whether any shadow is ever warranted is easier to judge against a real page.
- **Motion.** Still nothing specified.
- **The gap between the export and the API**, listed above. Search is the
  cheapest and the most missed.
