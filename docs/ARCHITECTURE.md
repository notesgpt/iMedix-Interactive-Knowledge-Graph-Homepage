# Architecture

## Decision: a WordPress plugin that provides a homepage page template

Three delivery options were evaluated:

| Option | Verdict |
|---|---|
| 1. WordPress-compatible **theme** package | Rejected. Replacing or forking the iMedix theme is out of scope ("do not modify the existing theme"), and a theme would also own every other page. |
| 2. **Plugin providing a homepage template** | **Chosen.** Installs alongside the existing theme, renders inside the theme's own header/footer, owns its data sync, REST endpoints and assets, and is removed cleanly by deactivation. |
| 3. Compiled app embedded through a thin integration layer | Folded into option 2: the front end *is* a compiled Vite bundle, but the plugin server-renders all essential HTML so the page is not a client-only app. |

No separate front-end hosting is needed: the bundle ships inside the plugin zip.

```
                         ┌──────────────────────── WordPress (www.imedix.com) ───────────────────────┐
 Google Sheet ──CSV/API──▶ Sync (WP-Cron / WP-CLI / admin)                                            │
 (inventory)              │  SheetSource → GraphBuilder (Core) ──▶ WpResolver (url_to_postid, status, │
                          │                                         title, excerpt, content links)    │
                          │  guard (≥10 entities, ≤50 % drop) → options + uploads/imedix-ku/graph-<v>.json
                          │                                                                           │
 Visitor ──GET /──────────▶ Page template "iMedix Knowledge Universe"                                 │
                          │  theme header → server-rendered hero, search form, news, topic links → footer
                          │  <script type=module> main.js (7.8 kB gz) — the only script                │
                          │       ├─ fetch graph-<v>.json (21 kB gz, immutable)  or  /wp-json/imedix-ku/v1/graph
                          │       ├─ search autocomplete (in-memory index)                            │
                          │       ├─ floating guide labels (DOM links, layout around the text)        │
                          │       └─ news ticker  ←── /wp-json/imedix-ku/v1/news (WP_Query, cached)   │
                          └───────────────────────────────────────────────────────────────────────────┘
```

## Repository layout

```
wordpress/imedix-knowledge-universe/   The deliverable (WordPress plugin)
  imedix-knowledge-universe.php        Bootstrap
  src/Core/                            Pure PHP (no WordPress): Csv, Text, Topics, Redirects,
                                       GraphBuilder, ContentLinks, ClientPayload, Discovery
  src/WordPress/                       Settings, SheetSource, Sync, WpResolver, News, Rest,
                                       Frontend, ViewModel, Admin, Cli
  templates/                           Page template + the single markup partial
  assets/dist/                         Built bundle (generated; shipped in the release zip)
frontend/                              Vite + TypeScript sources of the bundle
bin/                                   build-graph.php (offline graph build), render-preview.php,
                                       package.sh (release zip)
tests/                                 php/ (core), wordpress/ (real WP), e2e/ (browser), fixtures/
docs/                                  This documentation
.claude/skills/                        UI UX Pro Max skill (installed with its official CLI)
```

## Front-end stack

* **TypeScript + Vite**, no UI framework. The page is server-rendered by PHP; the client adds
  three independent "islands" (search, ticker, floating labels). A UI framework would add runtime
  and hydration complexity on top of PHP markup without a benefit for three widgets.
* **No 3D renderer.** Earlier iterations used a three.js scene (a constellation, then glass
  spheres). Design review found it cluttered the hero, so it was removed with its dependency
  (−143 kB gzip, no GPU work); the hero is typography, a CSS grid background and DOM labels.
* No web fonts are bundled: the page inherits the iMedix theme's body and heading fonts, so it
  adds zero font requests (see [BRAND-ALIGNMENT.md](BRAND-ALIGNMENT.md)). Colours come from
  `frontend/src/styles/tokens.css`, overridable by the theme through `--imedix-*` hooks.

### Loading sequence (Core Web Vitals)

1. HTML contains the H1, lede, search form, recent-news links and topic links — the LCP element
   is text, painted without JavaScript (measured LCP in the test harness: ~60 ms locally).
2. `main.js` (8.5 kB gz) + CSS (5.7 kB gz) load as a deferred module.
3. The graph JSON loads; search suggestions and the floating labels appear as soon as it arrives.
4. Labels are absolutely positioned in a layer that takes no layout space (CLS 0.005 measured).

### Floating guide labels (`frontend/src/hero/`)

* `label-layout.ts` (pure, unit-tested): picks real, linkable **drugs and diseases only**
  (completed guides, short recognisable names and well-connected entities first, alternating
  types; topics are never labels) — 10 on desktop, 8 on tablets, 5 on phones — and places them by
  best-candidate sampling so they never touch the text column, the statistics panel, the edges or
  each other.
* `floats.ts`: renders each label as a real `<a href>` to the WordPress guide inside a labelled
  list placed after the search in the DOM (keyboard users reach the search first). Search results
  move the top matches beside the search field (above the headline on narrow screens), mark the
  best one and let the others step back with a lighter edge — text is never faded. The active
  autocomplete option and hovered "Recently updated" chips highlight their label. Entities already
  shown as chips are not repeated.
* `buildNetwork` (pure, unit-tested) draws the decorative network behind the labels in an
  `aria-hidden` SVG: junction dots in free space, each label tied to its nearest junctions,
  junctions to their neighbours, a few blurred depth dots. Lines never join two labels directly
  (no implied medical relationship) and never cross the text column, statistics or a label.
* Motion: a slow CSS drift per label, paused on hover/focus, removed for `prefers-reduced-motion`.
* No data → no labels; the server-rendered hero is complete without them.

## Search architecture

* **Entity suggestions** come from an in-memory index over the synced graph (≈ 900 drug/disease
  entries + 91 redirect aliases). It is *not* a separate search database: it is built from the
  same WordPress-validated data, and it answers in < 1 ms with typo tolerance (Damerau–Levenshtein,
  1 edit ≥ 4 chars, 2 edits ≥ 8 chars), word-order-independent prefix matching and alias matching
  ("Accupril" → Quinapril HCl, "Amoxicillin" → Amoxil).
* **Full-text search** stays with WordPress: the form is a plain `GET /?s=` and the last
  suggestion is always "Search all iMedix articles for …". Without JavaScript, or if the graph
  fails to load, search still works.
* ARIA 1.2 combobox + listbox, `aria-activedescendant`, polite live-region counts, Escape
  semantics, type filter (All / Drugs / Diseases).
* Graph integration via a tiny event bus (`search:results`, `search:active`, `search:clear`):
  matches are emphasised, everything else dims, the camera pans the best match beside the text
  column and a dashed connector links the search field to it.

## Caching strategy

| Layer | What | Lifetime / invalidation |
|---|---|---|
| Google Sheets | Never called by visitors | Pulled by WP-Cron (`twicedaily` default), WP-CLI or the admin button |
| Sync | Source hash + "dirty" flag | Skips rebuild when the sheet is unchanged **and** no drug/disease post changed status |
| Graph | `uploads/imedix-ku/graph-<sha>.json` (versioned, immutable) + option fallback | New file per build; old files deleted |
| Graph REST | `/wp-json/imedix-ku/v1/graph` | `Cache-Control: public, max-age=900, stale-while-revalidate=3600` |
| Browser | `sessionStorage` keyed by graph version | Per tab session |
| News | Transient `imedix_ku_news_v1` | 10 min; flushed whenever a `news` post enters/leaves `publish` |
| News REST | `/wp-json/imedix-ku/v1/news` | `max-age=300, stale-while-revalidate=600`; browser refresh every 10 min while visible |
| Page cache | Homepage HTML can be fully page-cached | News/graph refresh client-side, so stale HTML self-heals |
| Assets | Hashed filenames from the Vite manifest | Enqueued with `ver=null` (hash = version) |

## Security

* No credentials in the repository or in JavaScript. Default mode needs none; optional Sheets API
  key lives in `wp-config.php` (`IMEDIX_KU_SHEETS_API_KEY`) and is never echoed (error messages
  omit the URL).
* Outbound requests use `wp_safe_remote_get` with timeout, 5 MB limit and content-type check.
* All sheet URLs are validated (scheme, host, no query/fragment/credentials, path charset); entity
  URLs must equal the canonical `/{base}/{slug}/` path; redirected/410 URLs are never linked.
* Output escaping everywhere in PHP (`esc_html`, `esc_url`, `esc_attr`, `wp_kses` for the
  `<em>` in the H1); config JSON uses `JSON_HEX_*` flags; the client builds DOM with `textContent`
  only (no `innerHTML`), and re-validates every URL as same-site http(s).
* REST endpoints are read-only, public, serve cached data and accept a single clamped integer.
  Manual sync requires `manage_options`, a nonce, and is throttled to once per minute; a lock
  prevents concurrent syncs.
* Admin settings are sanitised field by field (allow-lists, regex, clamps).
* Front-end runtime dependencies: none; dev-only: vite, vitest, typescript, jsdom, axe-core. `npm audit`: 0 vulnerabilities at build time.

## SEO

* The template keeps the theme's `<head>` (canonical, SEO-plugin title/meta, hreflang) untouched
  and adds **no** structured data and no meta tags. The official title and description
  ("iMedix: Independent Drug & Health Platform" / "iMedix provides independent, evidence-based
  information on medicines and health, …") are used only in the standalone preview HTML; in
  WordPress the SEO plugin keeps authority over them, and the Site Icon setting over the favicon
  (the integration suite asserts the plugin adds no description and no second `<title>`).
* Exactly one H1; heading order H1 → H2 (news, browse) → H3 (topics).
* Crawlable links: up to 6 guides per editorial topic (≈ 85 links), entry cards, news links.
* Essential content is in the HTML; the canvas is `aria-hidden` decoration.
