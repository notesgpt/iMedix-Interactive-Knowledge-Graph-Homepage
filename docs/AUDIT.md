# Phase 1 — Repository and integration audit

Audit date: 2026-10-27. Sandbox: Claude Code cloud session with an egress allow-list.

## Designated repository

`notesgpt/iMedix-Interactive-Knowledge-Graph-Homepage` was **empty** (no commits, no files) on
branch `claude/beautiful-davinci-ge2ure`. There was no existing application to extend, so the
architecture was chosen from scratch (see [ARCHITECTURE.md](ARCHITECTURE.md)). No other
repository was read or modified.

## Google Sheets — "iMedix Drug Remediation — Master Tracker"

Read through the Google Sheets connector (direct `docs.google.com` access is blocked by the
sandbox network policy). Spreadsheet `1KLBtihRPuRrwAn9VB0a9lVcyg9z-kjZAqvg4UWO0Ivk`, revision
fetched 2026-10-27.

| Worksheet | gid | Data rows | Columns (verified) |
|---|---|---|---|
| Drugs | 975154414 | 857 | `#`, `Drug slug`, `URL`, `Status`, `Batch`, `Note (Verified = …)`, `Completed on` |
| Diseases | 77683610 | 96 | `#`, `Disease slug`, `URL`, `Decision`, `Target (301)`, `Cluster`, `Standard`, `Status`, `Note`, `Updated on` |
| Drug redirects 301-410 | 383967448 | 293 | `Old URL`, `Code`, `Target`, `Target check (2026-10-18)`, `Added`, `Note` |

A snapshot of all three worksheets is stored as CSV in `tests/fixtures/sheets/` **as a test
fixture only**. Production never reads it; the plugin syncs from Google directly.

### What the status fields mean (investigated, not assumed)

**Drugs `Status`** — distribution: Pending 460, Completed 337, Retired 34, Not needed 14,
To create 11, one row with an empty URL.

* `Completed` — page remediated to the current editorial standard and, per the Note column,
  "Verified = standard markers on live site". Completed is an *editorial* state; the plugin still
  confirms live publication in WordPress.
* `Pending` — the page exists as a **legacy article awaiting remediation**. It is *not*
  "unpublished": 56 distinct Pending pages are the targets of 301 redirects whose
  `Target check (2026-10-18)` reads "OK (published)" (e.g. `/drugs/allegra/`, `/drugs/alli/`).
* `Retired` — the Note always documents the outcome: `301 -> /drugs/x/ (…; post set to draft)` or
  `410 (…; post set to draft)`. These URLs must never be linked.
* `Not needed` — "Not created: …" (discontinued brand, duplicate ingredient, herbal product):
  no page exists.
* `To create` — planned pages that do not exist yet.
* One `Completed` row (`avalide`) has an empty URL; its note says the page was created on
  2026-10-23. The URL is derived from the slug and the row is flagged `url_missing_derived`.

**Diseases** — every row currently has `Decision = KEEP`; `Status` is `Completed (v1.3)` (14) or
`Pending rewrite` (82). `Cluster` is an editorial grouping with annotations such as `(v1)`,
`(hub)`, `(rename → …)`. `Target (301)` is empty for all KEEP rows; non-KEEP decisions are
supported for the future.

**Redirects** — 301 and 410 entries; `Target check` reports `OK (published)`, `410 Gone`,
`CHAIN -> …` and `BROKEN: target 404`. Cross-check results:

* no active (Completed/Pending) drug URL appears as a redirect source;
* all 15 "BROKEN"/"CHAIN" targets are absent from the eligible inventory;
* 6 of the 34 Retired rows (`accupril`, `accuretic`, `altace`, `corgard`, `taztia-xt`,
  `twynsta`, all retired on 2026-10-20) document their 301 only in the Drugs Note column, not in
  the redirect worksheet — the pipeline reads both sources.

### Relationship data

The tracker contains **no medical relationship data**. `Batch` (Drugs) and `Cluster` (Diseases)
are editorial work groupings ("Batch A - Cardiovascular", "Blood Pressure", "Antibiotics (hub)").
They are modelled as *editorial topics*, explicitly non-clinical. See
[DATA-PIPELINE.md](DATA-PIPELINE.md#relationships-and-provenance).

## WordPress (www.imedix.com)

`www.imedix.com` is **blocked by the sandbox network policy** (HTTP CONNECT 403 for every URL,
including `/wp-json/`). The following could therefore **not** be verified from this session:

* whether the `news` CPT is exposed at `/wp-json/wp/v2/news` (candidate endpoint, unverified);
* the CPT names used for drugs and diseases, and whether they have archives;
* the WordPress search endpoints and the live theme's markup and branding;
* live publication status of each tracker entity.

The implementation is built so that none of these unknowns is load-bearing:

| Unknown | How the plugin handles it |
|---|---|
| `news` exposed in REST? | Not needed. The plugin queries the CPT with `WP_Query` server-side and serves its own cached endpoint `imedix-ku/v1/news`. No CPT registration change. |
| CPT names for drugs/diseases | Not needed. Publication is checked with `url_to_postid()` on the canonical URL, which works for any public post type. |
| Archive pages | Discovered at runtime with `get_post_type_archive_link()`; when absent, entry cards focus the search instead. |
| Search endpoint | The form submits to native WordPress search (`/?s=`), which works without JavaScript. |
| Branding/header/footer | Rendered by the active theme (`get_header()`/`get_footer()` or block template parts). |

The integration was verified instead against a **real WordPress 6.5 install** (SQLite) with
both a classic theme and the block theme Twenty Twenty-Four — see [TESTING.md](TESTING.md).

**Action for the iMedix team before staging:** run the checks in
[WORDPRESS-INTEGRATION.md § Pre-flight on staging](WORDPRESS-INTEGRATION.md#pre-flight-on-staging).

## UI UX Pro Max skill

Not installed initially. Installed with the official CLI (`ui-ux-pro-max-cli` 2.15.0,
`uipro init --ai claude`) into `.claude/skills/`. Notes:

* A global `npm install -g` was blocked by the session's permission policy; the CLI was installed
  locally in a scratch directory and run from there — the documented project-level install.
* The `/plugin marketplace` route is an interactive slash command and cannot be run by an agent.
* The running session's skill registry is only refreshed on restart ("Restart your AI coding
  assistant"), so the skill could not be invoked through the Skill tool in this session.
  Its `SKILL.md` workflow and its search engine (`scripts/search.py`, local CSV datasets, Python
  standard library only) were used directly. Because the skill is committed under
  `.claude/skills/`, any new Claude Code session on this repository loads it automatically.

How the skill shaped the design is documented in [DESIGN.md](DESIGN.md).
