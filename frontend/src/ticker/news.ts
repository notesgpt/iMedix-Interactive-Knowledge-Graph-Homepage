export interface NewsItem {
  id: number;
  title: string;
  url: string;
  date: string;
}

/** Validate, de-duplicate (by id and URL) and sort newest first. */
export function normaliseNews(items: unknown, limit: number, isSafeUrl: (u: string) => string | null): NewsItem[] {
  if (!Array.isArray(items)) return [];
  const seenId = new Set<number>();
  const seenUrl = new Set<string>();
  const out: NewsItem[] = [];
  for (const raw of items) {
    if (!raw || typeof raw !== 'object') continue;
    const r = raw as Record<string, unknown>;
    const id = Number(r.id);
    const title = typeof r.title === 'string' ? r.title.trim() : '';
    const url = typeof r.url === 'string' ? isSafeUrl(r.url) : null;
    const date = typeof r.date === 'string' ? r.date : '';
    if (!Number.isFinite(id) || !title || !url || Number.isNaN(Date.parse(date))) continue;
    if (seenId.has(id) || seenUrl.has(url)) continue;
    seenId.add(id);
    seenUrl.add(url);
    out.push({ id, title, url, date });
  }
  out.sort((a, b) => Date.parse(b.date) - Date.parse(a.date) || b.id - a.id);
  return out.slice(0, limit);
}

export function sameNews(a: NewsItem[], b: NewsItem[]): boolean {
  return a.length === b.length && a.every((x, i) => x.id === b[i].id && x.title === b[i].title && x.url === b[i].url);
}
