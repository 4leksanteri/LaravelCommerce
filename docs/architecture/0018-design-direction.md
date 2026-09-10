# 0018 - Design direction

Status: **superseded by [ADR 0019](0019-positioning-and-design-direction.md)** - 2026-09-10

> Superseded within a day, and the reason is worth more than the content.
>
> This was written from the seed categories - bread, coffee, preserves,
> ceramics, yarn - and reasoned honestly from them to a warm, paper-and-ink
> surface with a clay accent and a display serif.
>
> The premise was wrong. The design export that followed is a marketplace for
> **secondhand cameras, audio and instruments**, and leads with escrow. That is
> a better product: nobody needs funds held to buy a loaf, and everybody wants
> them held when sending six hundred pounds to a stranger for a camera. The
> surface it needs is cool and technical, not warm and made-by-hand.
>
> Every specific decision below is reversed in 0019 - the palette, the type, the
> radius, and "the primary action is ink". The method is not: decide the
> direction on paper, write down why, and only then let components wear it.
> Doing that is what made this cheap to throw away.

There is no Figma file and there is not going to be one. This is the substitute:
the decisions a design file would have encoded, written down before anything
wears them, so that "heavily customised" means something other than default
components with the primary colour changed.

Nothing here is about components. It is about what the tokens are and why.

---

## What this marketplace is

Independent makers selling bread, coffee, preserves, ceramics, yarn, knitwear
and prints. Small shops, one person each, mostly Nordic.

Three consequences, and everything below follows from them:

**The photograph is the product.** Nobody buys a loaf because of the button. The
interface's job is to get out of the way of an image somebody took of a thing
they made - which is also why the image pipeline caps at 1600px and re-encodes
rather than storing whatever came off a phone (ADR 0016).

**Small shops are not brands.** There is no shop-level theming and there will
not be. One coherent surface, so a shop with two listings does not look broken
next to one with two hundred.

**It is browsed on a phone.** `apps/web/CLAUDE.md` already says so. Density
decisions below are made for a small screen first.

---

## Paper and ink, not white and black

```text
background          #FAF8F5   warm off-white
card                #FFFFFF   a lift, without a shadow
foreground          #1C1917   warm near-black
muted-foreground    #78716C   warm grey
border              #E7E2DA
```

Pure `#FFFFFF` and `#000000` are harsh next to photographs of food and clay, and
they make everything look like a dashboard. A warm paper ground and a warm ink
sit under product photography without fighting it.

Cards are pure white **on** paper, which is enough separation that borders and
shadows mostly are not needed.

## The primary action is ink, not a colour

```text
primary             #1C1917   the same ink
primary-foreground  #FAF8F5
```

A coloured primary button is the fastest way to make a considered page look like
a template. Near-black buttons are what premium retail does, they never clash
with a photograph, and they are impossible to get wrong.

## One accent, used sparingly

```text
accent              #B4552D   clay
accent-foreground   #FFFFFF
ring                #B4552D
```

A burnt sienna, which is bread crust and unglazed ceramic and the warm end of
everything in the catalogue. It is for links, focus rings, and the occasional
badge - **not** for buttons. The moment it is the button colour, the restraint
above is gone.

Focus rings use it deliberately: focus has to be obvious, and this is the one
colour on the page that carries attention.

## The states that a marketplace actually needs

```text
destructive         #9F3A38   brick, not fire engine
positive            #3F6B4F   deep green
caution             #8A6212   ochre
```

`positive` and `caution` are not in shadcn's default set and are added because
this domain has states that need colour: an order is shipped or cancelled, a
variant is in stock or short. Rendering those in ink would lose the one thing
colour is good at.

Muted rather than saturated, because they appear next to photographs.

---

## Type: a serif that says "made", a sans that says nothing

```text
display   Fraunces      headings, product names, prices
sans      Inter         everything else
mono      Geist Mono    order references
```

The scaffold shipped Geist for both, which is a good typeface for a developer
tool and wrong here - it reads as software.

Fraunces is a variable serif built for exactly this warmth, and using it only at
display sizes keeps it from becoming twee. Inter is deliberately boring: it is a
UI typeface, it is excellent at 14px, and it should not have opinions.

Mono for order references is not decoration. `8Y9JN63MTC` is a string people read
aloud and type back in, and a proportional font makes that harder.

If Fraunces turns out too characterful in practice, Instrument Serif or
Newsreader swap in for one import and one token.

---

## Light only

The scaffold shipped a `prefers-color-scheme: dark` block with two colours in
it, which is a half-implemented dark mode - the worst of both.

It is removed. **This marketplace is light only**, because a photo-led retail
surface in dark mode is genuinely hard: product photographs shot on white
backgrounds look wrong on dark, and every image would need a treatment nobody is
going to give it.

Recorded as a decision rather than left as an absence, so nobody adds three dark
tokens later and calls it supported.

---

## Two densities, one scale

The storefront is generous: large images, a lot of air, few things per screen.
It is browsing.

The seller and staff surfaces are dense: tables, compact rows, many things per
screen. That is work, and air is a cost when you are looking at forty orders.

Same spacing scale, used differently. Not two scales - that is how a design
system becomes two design systems.

## Small radius, borders over shadows

`--radius: 0.25rem`. Pill-shaped buttons and heavily rounded cards read as
consumer-app; a smaller radius reads as editorial, which is nearer to a
catalogue.

Shadows are avoided in favour of a one-pixel border. A drop shadow around a
photograph of a plate is an interface drawing attention to itself.

---

## The token contract is shadcn's, the values are ours

This is the pragmatic decision in here and it is worth being explicit about.

shadcn components reference `--background`, `--foreground`, `--primary`,
`--muted-foreground`, `--destructive`, `--border`, `--ring` and so on. Those
names are **role-based already**, which is the property that matters: a token
named for its job survives a redesign, one named `--warm-grey-400` does not.

Renaming them to `--paper` and `--ink` would read better and would mean
hand-editing every component brought in, forever, for no benefit anybody can
see. So the contract is theirs and the values are ours, and `positive`,
`caution` and `display` are added on top where the domain needs something they
do not have.

Customisation lives in the values and in the component source - which is the
whole reason for choosing a library you copy rather than one you install
(`apps/web/CLAUDE.md` section 12 requires justifying a component library; there
is no library here to justify).

---

## Not yet decided

- **The components themselves.** Deliberately. A design system is a
  distillation, not a foundation: built before there are screens it produces a
  button with fourteen variants and no page using eleven of them. They get
  extracted from the auth screens and the storefront, as things repeat.
- **Storybook.** Worth having, worth deploying, and worth nothing with one
  button in it. It arrives once the first screens have produced a handful of
  primitives, with the accessibility addon on and a build in `make check` so it
  cannot rot.
- **A logo, or any brand mark.** The wordmark is the typeface for now.
- **Motion.** No transitions are specified. They are easier to decide against a
  working page than in the abstract.
- **Shop-level personalisation.** Ruled out above, and worth revisiting only if
  sellers ask.
