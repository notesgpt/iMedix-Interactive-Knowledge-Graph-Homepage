# Design — research, three directions, decision

> **Update 2026-10-27 — brand alignment.** Typography and colours were aligned with the iMedix
> site: theme fonts are now inherited (Newsreader/Figtree removed) and the palette is iMedix navy/blue.
> The token values in the table below are historical; the current ones are in
> `frontend/src/styles/tokens.css` and [BRAND-ALIGNMENT.md](BRAND-ALIGNMENT.md). Layout, motion and
> interaction decisions in this document are unchanged.

All research used the **UI UX Pro Max** skill installed in `.claude/skills/ui-ux-pro-max`
(v2.15.0). Commands are reproducible from the repository root:

```bash
S=.claude/skills/ui-ux-pro-max/scripts/search.py
python3 $S "medical knowledge graph healthcare information premium data exploration" --design-system -p "iMedix Knowledge Universe"
python3 $S "dark scientific data visualization immersive" --domain style -n 3
python3 $S "editorial minimal swiss clean"                --domain style
python3 $S "spatial 3D immersive depth"                   --domain style -n 3
python3 $S "healthcare medical trustworthy"               --domain color -n 3
python3 $S "scientific research deep navy"                --domain color -n 3
python3 $S "scientific editorial serif"                   --domain typography -n 3
python3 $S "medical clean accessible"                     --domain typography -n 3
python3 $S "search autocomplete suggestions keyboard"     --domain ux -n 3
python3 $S "auto rotation moving content pause"           --domain ux -n 3
python3 $S "reduced motion animation"                     --domain ux -n 3
python3 $S "focus not obscured"                           --domain ux -n 3
python3 $S "landing hero search"                          --domain landing -n 2
```

## What the skill returned and what we did with it

| Skill output | Decision |
|---|---|
| `--design-system` proposed **Neumorphism** + "Hero + Testimonials + CTA" (generic healthcare match) | **Rejected**: neumorphism is flagged by the skill itself as *accessibility risk: high* and has no depth language for a 3D graph; testimonials are irrelevant to a reference site. Kept its **anti-patterns**: no bright neon, no motion-heavy animation, no AI purple/pink gradients. |
| Style search: **HUD / Sci-Fi FUI** (neon cyan, monospace, scanning animations) | Explored as Direction C, rejected (game-like, neon, high a11y risk). |
| Style search: **Minimalism & Swiss** (grid, high contrast, low a11y risk) | Adopted for everything below the hero (Direction B's rigour). |
| Style search: **3D & Hyperrealism**, **Spatial UI**, **Dimensional Layering** | Took depth via WebGL and *purposeful* blur (glass only where text sits over the moving scene — the skill's `blur-purpose` rule). Rejected skeuomorphic textures and parallax stacks. |
| Colour: **Research Lab / University** palette — "Institutional navy + research accent + serif headings" (`#1E3A5F`, `#0F172A`) | Became the base: ink navy surfaces, serif display type. |
| Colour: Healthcare App / Biotech palettes (`#0891B2` cyan, `#059669` health green) | Teal action colour `#2BB3A3` (with dark ink text, 6.6:1) and teal drug signal `#5FD4C4`. |
| Typography: **News Editorial** (Newsreader) and **Medical Clean** (Figtree) pairings | **Newsreader** (display, editorial credibility) + **Figtree** (UI/body, legible, medical). A cross-pairing of the two skill results. |
| Landing pattern **FAQ/Documentation — "Hero with search bar > Popular categories"** and **Marketplace — "Search is the primary CTA"** | Hero = search; one primary CTA; categories (editorial topics) directly below. |
| UX rules: `auto-rotation-controls`, `reduced-motion`, `excessive-motion`, `focus-not-obscured`, `search autocomplete`, `no results`, `empty-states`, `touch-target-size`, `gesture-conflicts`, `color-not-only`, `data-table` (table alternative for charts) | Implemented one by one: ticker pause button + pause on hover/focus/off-screen; reduced-motion disables drift and marquee; only 2 animated elements; labels/cards never cover the focused search; debounced suggestions with a "no results" recovery path; 44 px targets; wheel never hijacks page scroll and touch needs an explicit "Explore" mode; shape + colour encoding; crawlable topic lists as the text alternative of the graph. |

## Three directions

**A. "Observatory" (chosen).** Deep ink-navy hero; the library rendered as a calm constellation
orbiting the search; editorial serif headline with one italic accent; light, Swiss-grid
"Browse the library" section below. Motion = one slow drift.

**B. "Clinical Atlas".** Paper-white page, graph drawn as fine ink lines like an anatomical
engraving, monochrome with a single teal accent, generous whitespace, search in a bordered box.

**C. "Bioluminescent Lab".** Black background, HUD/FUI framing, neon cyan glows, monospace labels,
scanning lines and pulsing nodes.

| Criterion (1–5) | A Observatory | B Clinical Atlas | C Bioluminescent Lab |
|---|---|---|---|
| Visual originality | 5 | 4 | 4 |
| Medical credibility | 4 | 5 | 2 |
| Search usability | 5 | 5 | 3 |
| Graph readability (depth, separation) | 5 | 3 (thin lines vanish on white, little depth) | 3 (glow clutter) |
| Mobile performance | 4 | 5 | 2 (additive glow, bloom) |
| Accessibility | 4 (AA contrast measured) | 5 | 2 (neon on black, motion) |
| Brand consistency (works under any existing header) | 4 | 5 | 2 |
| **Total** | **31** | 32 | 18 |

B scores marginally higher on paper but fails the brief's main ask — a *visually distinctive*
3D hero; on white a 3D point cloud loses depth cues and reads as a scatter plot. **A** was selected
and absorbs B's strengths: the editorial serif, the light Swiss section for reading, and B's
restraint (no bloom, no neon, single drift). C was discarded (it is the "game-like interface"
the brief warns against).

## Headline

Candidates considered:

1. "Explore Medical Knowledge Through Connections" (brief's suggestion) — accurate but abstract,
   and "connections" over-promises clinical links the data does not contain.
2. "Every medicine and condition, connected." — rejected for the same reason.
3. "Search medicines and conditions — then see where they lead." — long.
4. **"Find your way through *medical knowledge*"** — chosen: action-first (search), honest about
   what the map is (navigation through the library), contains the primary keyword, short enough
   to set large on mobile.

Lede: "Search {N} drug guides and {M} disease guides, or explore how the iMedix library connects."
Counts are live from the synced graph.

Under the search, the chips are labelled **"Recently updated"** (latest editorial dates in the
tracker) — there is no popularity data, so "Popular" would be untrue.

## Tokens

| Token | Value | Use |
|---|---|---|
| `--ku-bg-0 / 1 / 2` | `#050A16` / `#0A1324` / `#101C33` | hero, ticker, raised |
| `--ku-ink / 2 / 3` | `#EEF2F8` / `#B7C3D6` / `#8A9AB2` | text on dark (17.6 / 11.1 / 6.9 : 1 on bg-0) |
| `--ku-drug` | `#5FD4C4` dot | drug signal |
| `--ku-disease` | `#F2B880` ring | disease signal |
| `--ku-topic` | `#C9D3FF` diamond | editorial topic |
| `--ku-action` / ink | `#2BB3A3` / `#03201C` | primary button (6.6 : 1) |
| `--ku-focus` | `#FFD48A` 2 px, 3 px offset (dark, 14.2 : 1); `#0B6E66` in the light section (6.1 : 1) | focus ring |
| `--ku-paper / --ku-text / --ku-text-2` | `#F6F7F9` / `#0D1526` / `#475266` | light section (17.0 / 7.3 : 1) |
| Display | Newsreader 340 / italic 300, `clamp(2.5rem, …, 4.6rem)`, −0.022em | H1 |
| UI/Body | Figtree 400–700, 16 px base, 1.55 line height | everything else |
| Motion | 140 / 240 / 600 ms, `cubic-bezier(.2,.7,.2,1)` | shared rhythm |

Drug/disease/topic are distinguishable **without colour** (dot / ring / diamond) in the graph,
legend, suggestions, cards and lists; forced-colours mode is handled.

## Layout

* **Header / footer**: the active iMedix theme's (branding untouched).
* **Hero**: full-bleed, `min(100svh − 64px, 900px)`; constellation orbit framing a centred
  760 px column: eyebrow → H1 → lede → search (+ type filter) → "Recently updated" chips.
  HUD at the bottom: legend (left) and controls (zoom, reset, pause motion; "Explore the map" on
  touch). Details card on the right (bottom sheet on mobile).
* **News ticker** directly below the hero, same dark family, hairline borders, date in teal small
  caps, diamond separators, pause button, "All news".
* **Browse the library** (light): three entry cards (drugs / diseases / topics) and topic cards
  with up to six guides each, then the non-clinical disclaimer.

Screenshots of every state are produced by the browser tests in `build/screenshots/`.
