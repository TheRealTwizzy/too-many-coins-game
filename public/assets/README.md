# Art assets

Every icon and animation in the `?ui=next` client is referenced by a semantic
name, not a file path. Names resolve through the registry in
`public/js/core/assets.js`. Until art exists, each name renders an emoji or
geometric placeholder.

**Dropping art in is a one-file change.** Fill in the `art` field for a slot in
that registry and it appears everywhere that slot is used. No screen, component
or stylesheet needs touching, because none of them ever knew what a coin looked
like.

---

## Hard constraints

**Files must live in this repo.** The app serves
`Content-Security-Policy: img-src 'self' data:`. Art loaded from a CDN, an
image host, or any other origin is blocked by the browser — silently, leaving
an empty box. Commit the files here, or inline them as `data:` URIs.

**Sprite sheets are horizontal strips.** N frames of *identical* width, laid
left to right in a single image, transparent background, no padding between
frames and none around the edges. Playback is a CSS `steps()` animation over
`background-position`, so a frame that is one pixel off shifts every frame
after it.

**PNG with alpha.** Not JPEG — there is no transparency, and these composite
over four different backgrounds.

---

## Slots

Sizes are the 1x logical size. Supply `@2x` at exactly double for crisp
rendering on high-DPI displays; it is optional but cheap.

### Navigation — 24 × 24

`nav-home` · `nav-seasons` · `nav-ranks` · `nav-chat` · `nav-shop`

Sit on `--bg-2` at small size. Legible silhouettes matter far more than detail;
anything finer than about 2px of stroke disappears.

### Currency — 20 × 20

`coin` · `star-season` · `star-global` · `sigil`

`coin` and the two stars are the most-repeated marks in the game — they sit
beside every number in the HUD. The two stars must be distinguishable at
20px from each other, since confusing seasonal with global stars is a real
gameplay error, not a cosmetic one.

### Sigil families — 28 × 28, **tintable**

`family-yield` · `family-time` · `family-ward` · `family-larceny` ·
`family-market` · `family-sight` · `family-wild`

**Supply these as flat white silhouettes on transparency.** Set
`tintable: true` on the slot and each is masked and recoloured from its
existing `--family-*` token. One shape per family, seven colours for free, and
they stay in step with the palette across all four themes.

Full-colour art works too — just omit `tintable`. The cost is that the art and
the palette can then drift apart, and a theme change cannot reach them.

### Moments — sprite sheets

Each plays **once**, in response to something happening. None loop: a looping
animation in a game about watching numbers becomes noise within a minute.

| Slot | Frame size | Frames | fps | Plays when |
|---|---|---|---|---|
| `payout-burst` | 96 × 96 | 12 | 24 | a tick pays out |
| `sigil-drop` | 128 × 128 | 16 | 24 | a sigil drops |
| `theft-strike` | 128 × 128 | 14 | 24 | a theft resolves |
| `freeze-lock` | 96 × 96 | 10 | 20 | a freeze lands |
| `lockin-seal` | 192 × 192 | 24 | 20 | lock-in completes |

So `payout-burst` is a single **1152 × 96** PNG (12 × 96 wide), and
`lockin-seal` is **4608 × 192**.

`lockin-seal` is the one ceremony in the set — it marks leaving a season for
good, and is the only slot where a full second of animation is earned.

### States — 24 × 24

`state-idle` · `state-blackout` · `state-frozen`

---

## Registering art

```js
// in public/js/core/assets.js
'coin': {
    placeholder: '\u{1FA99}',
    art: { kind: 'image', src: '/assets/coin.png', src2x: '/assets/coin@2x.png', w: 20, h: 20 },
},

'family-ward': {
    placeholder: '◈',
    tint: 'var(--family-ward)',
    art: { kind: 'image', src: '/assets/family-ward.png', w: 28, h: 28, tintable: true },
},

'payout-burst': {
    placeholder: null,
    art: { kind: 'sprite', src: '/assets/payout-burst.png', w: 96, h: 96, frames: 12, fps: 24 },
},
```

Add `pixelated: true` for pixel art, which turns off smoothing when scaled.

Slots can be filled **one at a time**. A half-populated registry is a valid
state — filled slots show art, empty ones keep their placeholder.

---

## A note on generating sprite sheets

Image models are good at single icons and unreliable at sprite sheets, because
the hard requirement is *consistency across frames* — same subject, same
position, same scale, same lighting, varying only by the step of the motion.
Asking for a finished sheet in one shot generally produces frames that jitter
because the subject drifts between them.

What tends to work better:

- Generate **one** master frame you are happy with, then request variations
  from it rather than a sheet from scratch.
- Generate frames individually at a fixed canvas size and assemble the strip
  yourself. Assembly is mechanical — happy to write a small script for it.
- Prefer effects where drift reads as intentional: bursts, sparkles, dust,
  expanding rings. Anything with a rigid silhouette (a rotating coin, a
  character) will show every inconsistency.
- Radial or symmetric effects hide frame-to-frame wobble far better than
  directional ones.

Also worth knowing: because playback is `steps()` over one image, **frame count
and frame width must match the registry exactly**. If a generated sheet comes
back at a different size, change the numbers in the registry rather than
rescaling the image — rescaling to non-integer frame widths produces visible
seams at every step.

---

## Accessibility

`assets.icon(name)` is `aria-hidden` by default, on the assumption that it sits
beside a text label. Pass `{ label: 'Coins' }` when an icon appears alone, and
it becomes `role="img"` with that accessible name.

Sprites never play under `prefers-reduced-motion` — the rest frame is shown
instead, and the state change the sprite was announcing still happens. Reduced
motion removes the travel, never the outcome.
