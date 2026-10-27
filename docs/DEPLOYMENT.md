# Build and deployment

Nothing in this repository deploys automatically. Production changes require explicit approval.

## Build a release

```bash
bin/package.sh          # npm ci + typecheck + production build + zip
# → build/imedix-knowledge-universe-1.0.0.zip   (plugin + compiled assets; no Node on the server)
```

Before tagging a release run the full test suite ([TESTING.md](TESTING.md)).

## Rollout plan

1. **Staging**: install the zip, follow [WORDPRESS-INTEGRATION.md](WORDPRESS-INTEGRATION.md)
   (pre-flight, sync, template page). Verify:
   * Rejected records match expectations (Retired / Not needed / To create + any
     `wp_not_found` / `wp_not_published` / `wp_canonical_mismatch` worth fixing editorially);
   * the ticker shows real news posts;
   * Lighthouse / PageSpeed on the staging page (mobile): LCP, CLS, INP;
   * the page behind the CDN / page cache.
2. **Approval** by the iMedix owner.
3. **Production**: install + activate (inactive template has no effect), sync, create the page,
   review at its own URL, then switch *Settings → Reading* to the new page.
4. **Rollback**: switch *Settings → Reading* back to the previous homepage. Deactivating the plugin
   is also safe (the page then falls back to the theme's default page template).

## Updating

* Data: automatic (cron) — or `wp imedix-ku sync`.
* Code: upload the new zip ("Replace current with uploaded"). Asset filenames are content-hashed,
  so caches never serve stale JS/CSS.

## Environment

See `.env.example`. Default mode needs no secrets. Never commit `.env` files; `.gitignore` already
excludes them.
