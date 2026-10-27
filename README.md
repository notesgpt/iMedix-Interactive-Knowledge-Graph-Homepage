# iMedix Knowledge Universe

An interactive homepage for **www.imedix.com** (English site): a 3D knowledge graph of the real
drug and disease guides, a central medical search, and a live ticker of the site's own news —
delivered as a **WordPress plugin** that provides a page template and keeps the existing theme,
header, footer and SEO setup.

![Desktop hero](docs/screenshots/desktop.jpg)

| Search reaching into the map | Topic details | Mobile |
|---|---|---|
| ![Search](docs/screenshots/search.jpg) | ![Card](docs/screenshots/card.jpg) | ![Mobile](docs/screenshots/mobile.jpg) |

## What it does

* **Entities from the Google Sheet "iMedix Drug Remediation — Master Tracker"**, synced
  server-side on a schedule, filtered by `Status` / `Decision`, checked against the redirect sheet,
  and confirmed as **published in WordPress** at their canonical URLs.
* **3D graph** (Three.js): drugs (dot), diseases (ring) and editorial topics (diamond) in a calm
  orbit around the search; hover, select, zoom, rotate, details card, links to the WordPress
  guides; sampling, progressive loading, adaptive quality, SVG fallback, reduced-motion support.
* **Search** that works without JavaScript (native `/?s=`), and with it: instant suggestions with
  typo tolerance and redirect aliases, keyboard and screen-reader support, and live highlighting in
  the graph.
* **News ticker** from the existing `news` custom post type (no new news system), cached and
  refreshed, pausable, reduced-motion aware.
* **Honest relationships**: the tracker holds no clinical data, so the graph shows only editorial
  topics and in-article links, each with provenance, and says it does not imply treatment.

## Repository

| Path | Contents |
|---|---|
| `wordpress/imedix-knowledge-universe/` | The plugin (PHP core + WordPress layer + templates) |
| `frontend/` | TypeScript/Vite sources of the bundle |
| `bin/` | `package.sh` (release zip), `build-graph.php` (offline graph), `render-preview.php` |
| `tests/` | PHP core tests, WordPress integration, browser e2e, tracker snapshot fixtures |
| `docs/` | Documentation (below) |
| `.claude/skills/` | UI UX Pro Max skill used for the design work |

## Documentation

* [AUDIT.md](docs/AUDIT.md) — Phase 1 findings (repository, sheet, WordPress, skill)
* [ARCHITECTURE.md](docs/ARCHITECTURE.md) — stack, integration method, search, caching, security, SEO
* [DATA-PIPELINE.md](docs/DATA-PIPELINE.md) — sync, eligibility rules, graph schema, provenance
* [DESIGN.md](docs/DESIGN.md) — UI UX Pro Max research, three directions, decision
* [BRAND-ALIGNMENT.md](docs/BRAND-ALIGNMENT.md) — iMedix typography and colour alignment, tokens, before/after
* [WORDPRESS-INTEGRATION.md](docs/WORDPRESS-INTEGRATION.md) — install, configure, staging pre-flight
* [DEPLOYMENT.md](docs/DEPLOYMENT.md) — build, rollout, rollback
* [TESTING.md](docs/TESTING.md) — test suites and results

## Quick start

```bash
bin/package.sh                               # → build/imedix-knowledge-universe-<version>.zip
cd frontend && npm ci && npm run dev         # local preview of the real template (fixture data)
```

Install the zip on **staging**, run *Settings → Knowledge Universe → Sync now*, create a page with
the *iMedix Knowledge Universe* template, review, and only then switch the static front page.
Nothing deploys automatically.

---

### Кратко по-русски

Плагин WordPress для главной страницы iMedix (только английский сайт): 3D-граф реальных препаратов
и заболеваний из Google Sheets (с проверкой публикации в WordPress), центральный поиск (работает и
без JavaScript) и бегущая строка новостей из существующего CPT `news`. Медицинских связей
«лекарство → болезнь» граф не выдумывает: в данных их нет, поэтому показаны только редакционные
темы и ссылки из статей, с указанием источника. Установка и проверка — в
[WORDPRESS-INTEGRATION.md](docs/WORDPRESS-INTEGRATION.md); на прод ничего не выкатывается без
явного одобрения.
