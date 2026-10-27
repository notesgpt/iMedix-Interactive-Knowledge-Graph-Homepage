# Testing

## Results (2026-10-27)

| Suite | Command | Result |
|---|---|---|
| PHP lint (all plugin, bin and test files) | `find wordpress bin tests -name '*.php' -exec php -l {} \;` | clean |
| PHP core (no WordPress) | `php tests/php/run.php` | **2198 assertions, 0 failed** |
| TypeScript | `cd frontend && npm run typecheck` | clean (strict) |
| Front-end unit (Vitest) | `cd frontend && npm test` | **30 / 30** |
| Production build | `cd frontend && npm run build` | ok |
| Browser e2e (Chromium, real template + production bundle) | `node tests/e2e/run.mjs` | **27 / 27** |
| WordPress integration (WordPress 6.5, SQLite, WP-CLI, PHP built-in server; classic **and** block theme) | `tests/wordpress/run.sh` | **76 / 76** |
| Dependency audit | `cd frontend && npm audit --omit=dev` | 0 vulnerabilities |

### What is covered

**Google Sheets processing (PHP core)** — CSV parsing (BOM, quoted commas, blank rows, missing
columns), slug/URL validation (foreign hosts, `javascript:`, protocol-relative, query strings),
topic parsing and aliases, redirect chains/loops/410s, every eligibility rule and reason code,
duplicate removal, derived URLs, aliases from the redirect sheet *and* Retired notes, topic
pruning, WordPress resolver outcomes (not found, draft, moved permalink, title/excerpt override,
content-link edges, self-links and foreign links ignored). On the **real tracker snapshot**:
797 drugs / 96 diseases / 59 rejections, deterministic output, unique IDs, every edge endpoint
exists, **no node links to a redirected or 410 URL**, payload < 200 kB, discovery lists bounded.

**Search (Vitest + browser)** — normalisation, transposition-aware edit distance, ranking,
typo tolerance (`metfromin` → Metformin), aliases (`accupril` → Quinapril HCl), type filter,
unsafe URLs excluded, < 5 ms per query on the full index; in the browser: ARIA combobox wiring,
`aria-activedescendant`/`aria-selected`, live-region count, Escape twice, Enter navigates to the
guide, "search all" goes to `/?s=`, graph unavailable → full-text search still works.

**Graph** — budget per tier (640 / 340 / 180), priority order, deterministic sampling and layout,
progressive stages, members near their topic; in the browser: WebGL mount with HUD, search
highlight + connector, clicking a topic in the canvas opens the details card, Escape closes it,
WebGL disabled → SVG fallback with working search, reduced motion → rotation paused, touch →
explicit "Explore the map" mode.

**News** — validation, de-duplication by ID and URL, newest-first sort, limit; in the browser:
marquee only when overflowing, clones `aria-hidden` and unfocusable, pause button (`aria-pressed`),
paused on keyboard focus, static scrollable row under reduced motion; in WordPress: drafts
excluded, newest first, titles plain text and escaped, `limit` validated (400 above 20), the `news`
CPT stays *not* exposed in core REST (no configuration change needed).

**WordPress compatibility** — activation, cron scheduling, sync via WP-CLI, incremental "unchanged"
skip, publication change marks the graph dirty and rebuilds, unshared sheet (HTML response) and a
truncated sheet are refused while the last good graph keeps being served, versioned static file,
template in a classic theme (theme header/footer) and in Twenty Twenty-Four (block template
parts), exactly one H1, canonical link kept, no JSON-LD added, assets only on the template page,
module script tag even on themes without HTML5 script support, settings sanitisation, admin page
capability check, no PHP notices or warnings, uninstall removes only plugin data (posts untouched).

**Accessibility** — axe-core on the full page: no serious/critical violations; H1 → H2 → H3
order; 44 px touch targets; no horizontal overflow at 390 px; keyboard paths above; contrast
values verified by calculation (see DESIGN.md).

**Performance (test harness, local server)** — main JS 23.9 kB (8.6 kB gzip), CSS 24 kB
(5.8 kB gzip), lazy three.js chunk 573 kB (142 kB gzip), graph JSON 133 kB (21 kB gzip);
CLS 0.005; text LCP ≈ 70 ms locally. Real-network Lighthouse numbers must be taken on staging.

## Not verifiable from the build sandbox

* The live **www.imedix.com** site, its REST API and the actual `news` CPT data (host blocked by
  the sandbox network policy). Integration was proven against real WordPress with equivalent post
  types instead; run the staging pre-flight in WORDPRESS-INTEGRATION.md.
* A live request to `docs.google.com` (blocked); the CSV path is exercised with the real tracker
  content through WordPress' HTTP API (`pre_http_request` serves the snapshot).
* Real mobile GPUs; WebGL runs on SwiftShader in tests.

## Running locally

```bash
cd frontend && npm ci && npm run fixture && npm run typecheck && npm test && npm run build && cd ..
php tests/php/run.php
php bin/render-preview.php --mode=dist --news-fixture && node tests/e2e/run.mjs   # needs Playwright + Chromium
WP_ZIP=… SQLITE_ZIP=… WP_CLI=… tests/wordpress/run.sh                              # see .env.example
cd frontend && npm run dev   # live preview of the real template at http://localhost:5173
```

The preview uses clearly labelled synthetic news ("[Dev fixture n] …") only when
`--news-fixture` is passed; the plugin itself only ever shows WordPress `news` posts.
The same suites run in GitHub Actions (`.github/workflows/ci.yml`).

## Screenshots

`docs/screenshots/` (from the browser suite): `desktop.jpg`, `search.jpg`, `card.jpg`,
`mobile.jpg`, `mobile-explore.jpg`, `no-webgl.jpg`.
