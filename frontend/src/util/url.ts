/**
 * URL safety: only same-site http(s) destinations are ever assigned to links.
 * Graph/news data is produced server-side, but the client re-validates so that a
 * corrupted cache can never inject javascript: or off-site URLs.
 */
export function safeSiteUrl(pathOrUrl: string | null | undefined, siteOrigin: string): string | null {
  if (!pathOrUrl) return null;
  let url: URL;
  try {
    url = new URL(pathOrUrl, siteOrigin);
  } catch {
    return null;
  }
  if (url.protocol !== 'https:' && url.protocol !== 'http:') return null;
  const site = new URL(siteOrigin);
  const strip = (h: string) => h.replace(/^www\./, '').toLowerCase();
  if (strip(url.hostname) !== strip(site.hostname)) return null;
  return url.href;
}

/** Native WordPress search URL (?s=) for a query. */
export function searchUrl(base: string, query: string, siteOrigin: string): string {
  const url = new URL(base || '/', siteOrigin);
  url.searchParams.set('s', query);
  return url.href;
}
