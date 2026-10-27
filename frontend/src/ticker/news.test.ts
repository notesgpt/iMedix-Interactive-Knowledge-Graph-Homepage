import { describe, expect, it } from 'vitest';
import { normaliseNews, sameNews } from './news';
import { safeSiteUrl, searchUrl } from '../util/url';

const safe = (u: string) => safeSiteUrl(u, 'https://www.imedix.com');

describe('normaliseNews', () => {
  it('sorts newest first, de-duplicates by id and URL, and limits', () => {
    const out = normaliseNews(
      [
        { id: 1, title: 'Old', url: 'https://www.imedix.com/news/old/', date: '2026-10-01T10:00:00Z' },
        { id: 2, title: 'New', url: 'https://www.imedix.com/news/new/', date: '2026-10-20T10:00:00Z' },
        { id: 2, title: 'New dup id', url: 'https://www.imedix.com/news/new-2/', date: '2026-10-20T10:00:00Z' },
        { id: 3, title: 'Dup url', url: 'https://www.imedix.com/news/new/', date: '2026-10-21T10:00:00Z' },
        { id: 4, title: 'Mid', url: 'https://imedix.com/news/mid/', date: '2026-10-10T10:00:00Z' },
      ],
      10,
      safe,
    );
    expect(out.map((n) => n.id)).toEqual([2, 4, 1]);
    expect(normaliseNews(out, 1, safe)).toHaveLength(1);
  });

  it('rejects unsafe or malformed items', () => {
    const out = normaliseNews(
      [
        { id: 1, title: 'XSS', url: 'javascript:alert(1)', date: '2026-10-01' },
        { id: 2, title: 'Offsite', url: 'https://evil.example/news/', date: '2026-10-01' },
        { id: 3, title: '', url: 'https://www.imedix.com/news/a/', date: '2026-10-01' },
        { id: 4, title: 'Bad date', url: 'https://www.imedix.com/news/b/', date: 'yesterday' },
        null,
        'string',
      ],
      10,
      safe,
    );
    expect(out).toHaveLength(0);
    expect(normaliseNews({ not: 'an array' }, 10, safe)).toEqual([]);
  });

  it('detects unchanged lists', () => {
    const a = [{ id: 1, title: 'A', url: 'u', date: 'd' }];
    expect(sameNews(a, [{ ...a[0] }])).toBe(true);
    expect(sameNews(a, [{ ...a[0], title: 'B' }])).toBe(false);
  });
});

describe('url helpers', () => {
  it('keeps same-site URLs only', () => {
    expect(safe('/drugs/x/')).toBe('https://www.imedix.com/drugs/x/');
    expect(safe('https://imedix.com/a/')).toBe('https://imedix.com/a/');
    expect(safe('//evil.example/')).toBeNull();
    expect(safe('data:text/html,hi')).toBeNull();
  });
  it('builds native WordPress search URLs', () => {
    expect(searchUrl('https://www.imedix.com/', 'a&b <c>', 'https://www.imedix.com')).toBe('https://www.imedix.com/?s=a%26b+%3Cc%3E');
  });
});
