# Data pipeline and graph model

The Google Sheet is the source of the **entity inventory**; WordPress is the source of truth for
**live publication status, titles and destinations**.

## Synchronisation

```
WP-Cron (twicedaily) / wp imedix-ku sync / Settings → Knowledge Universe → "Sync now"
  └─ lock (5 min)
     └─ SheetSource: 3 worksheets
          csv_export (default): https://docs.google.com/spreadsheets/d/<id>/export?format=csv&gid=<gid>
          sheets_api (optional): values:batchGet, key from IMEDIX_KU_SHEETS_API_KEY
        └─ hash(sheets) unchanged AND no drug/disease post changed status → "unchanged", stop
        └─ GraphBuilder + WpResolver
           └─ guard: ≥ 10 entities and no drop > 50 % vs. last good build (configurable)
              └─ store option + versioned JSON file, report (rejected/warnings), state
     on any error: keep serving the last good graph, record lastError
```

Why this design:

* **No visitor ever triggers a Google request**, and the homepage keeps working if the sheet
  becomes unavailable or unshared (an HTML sign-in page is detected by content type).
* **No secrets by default**: the sheet is link-shared, so the CSV export needs no credentials.
* **Incremental**: rebuilds only when the sheet hash changes or WordPress publication changes
  (`transition_post_status` / `post_updated` on posts under the drug/disease bases mark the graph
  dirty).
* **Build-time alternative** for development/CI: `php bin/build-graph.php` runs the same `Core`
  classes on CSV files (publication status "unverified").

## Entity eligibility

### Drugs (`Status`)

| Status | Included | Reason code when excluded |
|---|---|---|
| Completed | yes (`editorial = completed`) | — |
| Pending | yes (`editorial = pending`) — live legacy page, **not** unpublished | — |
| Retired | no; its documented 301 becomes a search alias of the target | `status_retired` |
| Not needed | no (no page) | `status_not_needed` |
| To create | no (no page yet) | `status_to_create` |
| anything else | no, until the new state is reviewed | `status_unknown` |

### Diseases (`Decision`)

`KEEP` → included (`Completed (v1.3)` → `completed`, `Pending rewrite` → `pending`). Any other
decision → `decision_not_keep`; a `Target (301)` value becomes a redirect/alias.

### Checks applied to every candidate

| Check | Reason code |
|---|---|
| slug is a valid WordPress slug (no `__trashed`) | `invalid_slug` |
| URL column parses, is on the sheet host (`www.imedix.com`), no query/fragment | `url_invalid` |
| URL equals the canonical `/{drugs|diseases}/{slug}/` | `url_slug_mismatch` |
| empty URL → derived from slug (warning) | `url_missing_derived` (warning) |
| slug seen before | `duplicate` |
| URL is a redirect/410 source (redirect sheet, Retired notes, disease targets) | `redirected` |
| WordPress: `url_to_postid()` finds no post | `wp_not_found` |
| WordPress: post not `publish` | `wp_not_published` |
| WordPress: post's permalink differs from the canonical URL | `wp_canonical_mismatch` |

Redirect chains are resolved (max 5 hops, loop detection). Every rejection is stored with sheet,
row number, slug, reason and detail, shown in **Settings → Knowledge Universe** and via
`wp imedix-ku rejected`.

Result on the 2026-10-27 snapshot (before WordPress validation): **797 drugs, 96 diseases,
15 editorial topics, 295 edges; 59 rows rejected** (34 retired, 14 not needed, 11 to create).

## Graph schema (`imedix-ku/graph@1`)

```jsonc
{
  "schema": "imedix-ku/graph@1",
  "generatedAt": "2026-10-27T…Z",
  "source": { "spreadsheetId": "…", "hash": "…", "publication": "wordpress" | "unverified" },
  "nodeTypes": { "drug": {…}, "disease": {…}, "topic": {…} },
  "edgeTypes": { "in_topic": { "clinical": false, "provenance": "…" }, "links_to": { … } },
  "counts": { "drug": 797, "disease": 96, "topic": 15 },
  "nodes": [{
    "id": "drug:metformin",              // stable: <type>:<slug>
    "type": "drug",                      // entity type (extensible)
    "name": "Metformin",                 // WordPress title when validated
    "slug": "metformin",
    "url": "https://www.imedix.com/drugs/metformin/",
    "status": { "editorial": "completed", "label": "Completed",
                "publication": "published" | "unverified", "updated": "2026-10-20" },
    "category": "diabetes",              // visual category = first topic key, else type
    "degree": 1,                         // relationship count
    "aliases": ["Glucophage"],           // from 301 redirects to this page (search only)
    "description": "…"                   // optional, WordPress excerpt
  }],
  "edges": [{ "source": "drug:metformin", "target": "topic:diabetes",
              "type": "in_topic", "provenance": "Drugs.Batch" }]
}
```

The browser receives a compact derivative (`imedix-ku/client@1`, `Core\ClientPayload`) with
site-relative paths. **Future entity types** (active ingredient, drug class, symptom, treatment)
need only a new `nodeTypes` entry, a builder method and a glyph/colour; IDs are namespaced by type,
sampling and layout treat unknown types as regular entities, and nothing is populated without a
reliable source.

## Relationships and provenance

| Edge type | Meaning | Source | Clinical? |
|---|---|---|---|
| `in_topic` | Entity is grouped under an editorial topic | `Drugs.Batch`, `Diseases.Cluster` | **No** — editorial work grouping |
| `links_to` | The published iMedix article for A links to the article for B | Internal links in `post_content` (WordPress, at sync time) | **No** — navigation fact |
| search alias | Old/retired name that 301-redirects to the entity | Redirect worksheet, Retired notes, disease targets | No |

**No drug → disease treatment edges exist**, because no reliable source was available: the tracker
holds no indication data, and the WordPress content could not be inspected from the build
environment. Co-occurrence in the spreadsheet is never interpreted as a medical relationship. The
UI says so where connections are shown ("Grouping used by the iMedix editorial team. It does not
mean this medicine treats these conditions.").

A clinical edge type (e.g. `indicated_for`) should only be added with an explicit, citable source
(e.g. a structured "Indications" field maintained by editors, or FDA label data with provenance per
edge) and `clinical: true`; the schema already carries per-edge provenance for that purpose.

### Editorial topic normalisation

Annotations in parentheses are dropped, `/` splits multiple topics, `Batch X - ` prefixes are
removed. Small explicit alias list (in `Core\Topics`): `ED` → Erectile Dysfunction,
`Heart` → Heart Disease, `Men's` → Men's Health, `Woman's Health` → Women's Health. `Other` is
ignored. Topics with a single member are dropped (7 on the current snapshot, logged as warnings).
Drug batch "Blood Pressure" and disease cluster "Blood Pressure" merge into one topic because they
carry the same editorial label.

## Display names

WordPress `post_title` is used when validated. Offline builds use a humaniser
(`Core\Text::humanize_slug`) that knows trailing acronyms (`…-copd` → "(COPD)"), eponyms
("Parkinson's") and units ("HCl").
