# Brand alignment with the iMedix WordPress site

Status: 2026-10-27. Scope: typography and colour only; layout, graph, search, ticker,
data pipeline, WordPress integration and SEO are unchanged.

## 1. What could be verified

| Source | Result |
|---|---|
| `https://www.imedix.com/` (HTML, theme stylesheets, `@font-face`, CSS variables) | **Not reachable** from the build environment (network policy: DNS/CONNECT blocked). Third-party mirrors were not used. |
| iMedix theme repository | Not among the repositories available to this session. |
| Web search for the theme's CSS | No result containing imedix.com stylesheets. |
| Reference palette supplied with the task | `#112236`, `#436280`, `#A3BAD2`, `#DBE4ED`, `#FFFFFF` — used. |

Therefore **no iMedix font name or theme hex value was guessed.** Instead:

* **Typography is inherited from the live theme at runtime** — this *is* the actual iMedix
  typography, whatever it is:
  * body text: `.imedix-ku { font-family: var(--imedix-font-body) }` — with the hook undefined the
    declaration is invalid at computed time and the property inherits the theme's `body` font;
  * `h1`, `h2`, `h3` declare **no** `font-family`, `font-weight` or `letter-spacing`, so the
    theme's own heading rules apply unchanged;
  * non-heading display text (stat numerals, card titles) copies the rendered H1 font into
    `--ku-font-heading` at runtime (`main.ts → adoptThemeHeadingFont`);
  * form controls use `font: inherit`.
* **Colours** use the supplied iMedix reference palette, wired through override hooks
  (`--imedix-color-*`) so the theme's exact declarations can replace them without a plugin change.

## 2. Information needed from the iMedix team (to finish 1:1 alignment)

Run on any page of www.imedix.com (DevTools console) and send the output:

```js
(() => {
  const cs = (s) => { const e = document.querySelector(s); return e && getComputedStyle(e); };
  const pick = (s) => { const c = cs(s); return c && { font: c.fontFamily, size: c.fontSize, weight: c.fontWeight, lh: c.lineHeight, ls: c.letterSpacing, color: c.color, bg: c.backgroundColor }; };
  return {
    body: pick('body'), h1: pick('h1'), h2: pick('h2'), h3: pick('h3'), link: pick('main a, .entry-content a'),
    header: pick('header, .site-header'), footer: pick('footer, .site-footer'), button: pick('button, .btn, .button'),
    stylesheets: [...document.styleSheets].map((s) => s.href).filter(Boolean),
    fontFaces: [...document.fonts].map((f) => `${f.family} ${f.weight} ${f.style}`),
    rootVars: [...getComputedStyle(document.documentElement)].filter((p) => p.startsWith('--')).map((p) => p + ': ' + getComputedStyle(document.documentElement).getPropertyValue(p).trim()),
  };
})();
```

Needed: theme font families and how they are loaded (self-hosted / Google Fonts / Adobe),
heading weights and sizes, the exact navy of header and footer, the primary link/button blue and
its hover, the light background and border greys. Then either paste them into the theme's CSS
as hooks (§ 4) or update the fallbacks in `frontend/src/styles/tokens.css`.

## 3. Before → after

### Typography

| Element | Before | After |
|---|---|---|
| Web fonts | Newsreader Variable + Figtree Variable, bundled via Fontsource (~143 kB woff2) | **None bundled** — theme fonts inherited (0 font requests from the plugin) |
| Body / UI | `Figtree Variable`, 16 px / 1.55 | **Theme body font** (inherited), 16 px / 1.55 |
| H1 | Newsreader 340, `clamp(2.5rem…4.6rem)`, line-height 1.04, tracking −0.022em, italic accent | **Theme h1 family, weight and tracking**; size `clamp(2.3rem…4rem)`, line-height 1.1; accent words in `#A3BAD2`, upright (no faux italic) |
| H2 "Browse the library" | Newsreader 400, tracking −0.015em | **Theme h2 rule**; size `clamp(2rem…2.9rem)`, line-height 1.1 |
| H3 topic titles | Newsreader 500 | **Theme h3 rule**; 1.3rem / 1.25 |
| Card titles, stat numerals | Newsreader | Theme heading font via `--ku-font-heading` (read from the rendered H1) |
| Weights | literal numbers (300–700) | tokens `--ku-weight-regular/medium/semibold/bold` (400/500/600/700) |
| Inputs, buttons | `font: … var(--ku-font-ui)` | `font: inherit` + size/weight tokens |

### Colour

| Role | Before | After |
|---|---|---|
| Hero / dark surface | `#050A16` | **`#112236`** (iMedix navy) |
| Ticker / deep surface | `#0A1324` | `#0B1726` (derived: navy darkened) |
| Raised dark surface | `#101C33` | `#1A3049` (derived: navy lightened) |
| Primary text on dark | `#EEF2F8` | **`#FFFFFF`** (16.1 : 1) |
| Secondary text on dark | `#B7C3D6` | **`#DBE4ED`** (12.5 : 1) |
| Tertiary / accent on dark | `#8A9AB2` / `#A6EBE1` | **`#A3BAD2`** (8.0 : 1) |
| Primary button | `#2BB3A3` with `#03201C` text | **`#436280`** with white text (6.4 : 1); hover `#4F7395` (5.0 : 1), active `#35516E` (8.2 : 1) |
| Focus ring | `#FFD48A` (dark) / `#0B6E66` (light) | `#A3BAD2` on dark (8.0 : 1) / **`#436280`** on light (6.4 : 1) |
| Hairlines on dark | `rgba(183,195,214,.16)` | `#A3BAD2` at 18 % / 34 % |
| Light section background | `#F6F7F9` | white → `#F3F6F9` (derived from `#DBE4ED`) |
| Cards / borders on light | `#FFFFFF` / `#DDE2EA` | `#FFFFFF` / **`#DBE4ED`** |
| Text on light | `#0D1526` / `#475266` | **`#112236`** (14.8 : 1) / **`#436280`** (5.9 : 1) |
| Links on light | `#0B6E66` | **`#436280`**, hover `#112236` |

### Knowledge-graph colours (shapes, layout, motion, effects unchanged)

| Signal | Shape | Before | After | Contrast on `#0B1726` |
|---|---|---|---|---|
| Drug | dot | `#5FD4C4` teal | `#8FBFEA` (iMedix blue, lightened) | 9.3 : 1 |
| Disease | ring | `#F2B880` amber | **`#DBE4ED`** | 14.0 : 1 |
| Editorial topic | diamond | `#C9D3FF` periwinkle | **`#A3BAD2`** | 9.0 : 1 |
| Edges | line | `#9FB3D9` | `#7F9CBA` (iMedix blue, mid) | — |
| Glyphs on light | — | teal / amber / indigo | `#436280` dot · `#112236` ring · `#6C8AA8` diamond (≥ 3 : 1) | — |

Drugs, diseases and topics are now separated by **shape plus lightness/saturation** inside one
brand hue family (saturated light blue dot, near-white ring, steel diamond), instead of three
unrelated hues. The WebGL renderer and the SVG fallback read these values from the CSS tokens
(`graph/palette.ts`); no colour is hard-coded in TypeScript.

## 4. Token system and theme hooks

`frontend/src/styles/tokens.css` is the single source: brand primitives → semantic tokens →
component use. Every brand primitive reads an optional hook first:

```css
/* In the iMedix theme (or Customizer → Additional CSS), with the verified values: */
:root {
  --imedix-font-body: "<theme body font>", sans-serif;   /* optional: body already inherits */
  --imedix-color-navy: #112236;      --imedix-color-navy-rgb: 17 34 54;
  --imedix-color-primary: #436280;   --imedix-color-primary-rgb: 67 98 128;
  --imedix-color-primary-strong: …;  --imedix-color-primary-bright: …;
  --imedix-color-secondary: #a3bad2; --imedix-color-secondary-rgb: 163 186 210;
  --imedix-color-light: #dbe4ed;     --imedix-color-background: #f3f6f9;
  --imedix-graph-drug: …; --imedix-graph-disease: …; --imedix-graph-topic: …; --imedix-graph-edge: …;
}
```

Nothing else needs to change; the graph picks up the hooks too. Contrast must be re-checked if a
hook changes a text or button colour (see the pairs listed in tokens.css).

## 5. "Browse the library" refinement

Same content, links, headings (H2 + H3 per topic) and order; presentation upgraded:

* editorial kicker ("The iMedix library") and a statistics strip (drug guides / disease guides /
  editorial topics, tabular numerals, live counts);
* entry cards on iMedix navy with a faint dotted "constellation" echoing the hero, a one-line
  description, count and an arrow that moves on hover;
* topic cards: diamond mark, count pill, an accent rule that draws in on hover/focus, guide rows
  with a chevron, and a **"Show on map"** action that scrolls up and opens the topic in the 3D
  graph (shown only when the WebGL map is running);
* the non-clinical disclaimer as a bordered callout.

## 6. QA

Browser suite (31 tests) adds: tablet 820 px, "Show on map", graph colours from tokens, zero
bundled font files, and a stricter overflow check (it found and fixed a mobile overflow caused by
wider inherited fonts: the hero column is now `min-width: 0`). WordPress suite adds checks that
the shipped CSS declares no font families other than inherited tokens. axe-core: no serious or
critical violations. Screenshots: `docs/screenshots/`.

Comparison against the live site could not be done visually from this environment; the
pre-flight snippet in § 2 closes that gap on staging.
