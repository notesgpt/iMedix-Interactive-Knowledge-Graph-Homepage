# WordPress integration

The plugin never edits the theme, posts, other plugins' settings, SEO configuration or the
`news` post type registration. It only adds: a page template, a shortcode, two read-only REST
routes, one cron event, its own options (`imedix_ku_*`) and generated files in
`wp-content/uploads/imedix-ku/`.

Targets the **English** site only. Do not activate it on the German or French installations.

## Requirements

WordPress ≥ 6.3, PHP ≥ 7.4 (tested on PHP 8.3 with WordPress 6.5). Works with classic themes
(`get_header()` / `get_footer()`) and block themes (`header` / `footer` template parts).

## Pre-flight on staging

These could not be checked from the build sandbox (www.imedix.com is not reachable there):

```bash
# 1. The plugin does not need the news CPT in REST, but confirm the CPT name:
wp post-type list --fields=name,rewrite,has_archive,public
# 2. Drug/disease URLs resolve with url_to_postid (any CPT name works):
wp eval 'echo url_to_postid( home_url("/drugs/metformin/") ), PHP_EOL;'
wp eval 'echo url_to_postid( home_url("/diseases/asthma/") ), PHP_EOL;'
# 3. The sheet is still shared "anyone with the link can view":
curl -sI "https://docs.google.com/spreadsheets/d/1KLBtihRPuRrwAn9VB0a9lVcyg9z-kjZAqvg4UWO0Ivk/export?format=csv&gid=975154414" | grep -i content-type   # → text/csv
# 4. WP-Cron runs (or a system cron calls wp cron event run --due-now).
```

If the news post type is not named `news`, change it in **Settings → Knowledge Universe**.

## Install and configure (staging)

1. Upload `imedix-knowledge-universe-<version>.zip` (Plugins → Add New → Upload) and activate.
2. **Settings → Knowledge Universe**: defaults already point at the tracker
   (`1KLBtihRPuRrwAn9VB0a9lVcyg9z-kjZAqvg4UWO0Ivk`, gids 975154414 / 77683610 / 383967448).
   Keep *"Only include entities whose WordPress post is published"* on. On staging hosts keep
   "Host used in the sheet" = `www.imedix.com`; output links use the staging `home_url()`.
3. Click **Sync now** (or `wp imedix-ku sync --force`). Review the counts and the *Rejected
   records* table.
4. Create a page (e.g. "Home — Knowledge Universe"), choose **Template → iMedix Knowledge
   Universe**, publish, and review it at its own URL.
5. Only after approval: **Settings → Reading → Your homepage displays → A static page →
   Homepage = that page**. This is the single production switch and is fully reversible.

Alternative: the shortcode `[imedix_knowledge_universe]` can be placed in a page that uses a
full-width template without its own H1 (otherwise the page would have two H1s).

## SEO behaviour

* The theme and SEO plugin keep full control of `<head>`: title, meta description, canonical,
  Open Graph, hreflang. The plugin adds nothing to `<head>` except its stylesheet.
* If the SEO plugin stores homepage metadata on the *front page* object, set it on the new page
  before switching (or copy it over) so titles/descriptions do not change. The canonical URL stays
  `https://www.imedix.com/` because WordPress canonicalises the static front page to the home URL.
* No structured data is generated.

## Brand tokens

The page inherits the theme's fonts and uses the iMedix palette. To apply the theme's exact
values (or adjust graph colours) define the `--imedix-*` hooks in the theme or in
*Appearance → Customize → Additional CSS*; see [BRAND-ALIGNMENT.md § 4](BRAND-ALIGNMENT.md#4-token-system-and-theme-hooks).

## Caching / CDN

* The homepage HTML can be page-cached; the ticker and graph refresh client-side.
* Allow `GET /wp-json/imedix-ku/v1/graph` and `/news` to be edge-cached (they send
  `Cache-Control: public`). Do not cache them for more than ~15 min.
* `wp-content/uploads/imedix-ku/graph-*.json` files are versioned; they can be cached as immutable.
* If a security plugin blocks anonymous REST access, allow the `imedix-ku/v1` namespace.

## Operations

```bash
wp imedix-ku sync [--force]     # rebuild now
wp imedix-ku status             # last attempt/success, counts, file, errors
wp imedix-ku rejected --format=csv
```

Hooks: `imedix_ku_graph_built` (action, after a successful build), `imedix_ku_view_model`
(filter, change copy/limits). Uninstall removes only the plugin's options, transients, cron event
and generated files.

## Optional: Google Sheets API mode

Only if the sheet must stop being link-shared:

```php
// wp-config.php
define( 'IMEDIX_KU_SHEETS_API_KEY', '…' ); // restrict to Sheets API + server IPs
```

Then select *Google Sheets API* in settings and share the sheet with the API project as needed.
The key is never stored in the database or sent to browsers.
