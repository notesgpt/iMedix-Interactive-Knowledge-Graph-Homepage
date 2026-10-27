/**
 * Progressive enhancement of the server-rendered news list:
 *  - continuous marquee (CSS transform) only when the list overflows;
 *  - pauses on hover, on keyboard focus, off-screen, in hidden tabs and via a
 *    visible pause button (WCAG 2.2.2); disabled under prefers-reduced-motion,
 *    where the list remains a horizontally scrollable row;
 *  - refreshes from the REST endpoint so new posts appear without a reload.
 */
import type { KUConfig } from '../types';
import { prefersReducedMotion } from '../util/env';
import { safeSiteUrl } from '../util/url';
import { normaliseNews, sameNews, type NewsItem } from './news';

const SPEED_PX_PER_S = 42;

export class NewsTicker {
  private viewport: HTMLElement;
  private track: HTMLUListElement;
  private toggle: HTMLButtonElement | null;
  private items: NewsItem[];
  private paused = false;
  private pendingRender = false;
  private dateFmt: Intl.DateTimeFormat;

  constructor(
    private section: HTMLElement,
    private config: KUConfig,
  ) {
    this.viewport = section.querySelector<HTMLElement>('[data-ku-ticker]')!;
    this.track = section.querySelector<HTMLUListElement>('[data-ku-ticker-track]')!;
    this.toggle = section.querySelector<HTMLButtonElement>('[data-ku-ticker-toggle]');
    this.dateFmt = new Intl.DateTimeFormat(config.locale || 'en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    this.items = this.readServerItems();

    this.toggle?.addEventListener('click', () => this.setPaused(!this.paused));
    this.section.addEventListener('focusout', () => {
      if (this.pendingRender && !this.section.contains(document.activeElement)) this.render();
    });

    let resizeTimer = 0;
    window.addEventListener('resize', () => {
      window.clearTimeout(resizeTimer);
      resizeTimer = window.setTimeout(() => this.layout(), 200);
    });
    window.matchMedia?.('(prefers-reduced-motion: reduce)').addEventListener?.('change', () => this.layout());

    if ('IntersectionObserver' in window) {
      new IntersectionObserver(([entry]) => {
        this.section.classList.toggle('is-offscreen', !entry.isIntersecting);
      }).observe(this.section);
    }

    this.layout();
    this.startRefresh();
  }

  private readServerItems(): NewsItem[] {
    const items: NewsItem[] = [];
    this.track.querySelectorAll<HTMLLIElement>('li[data-ku-news-id]').forEach((li) => {
      const a = li.querySelector('a');
      const time = li.querySelector('time');
      const title = li.querySelector('span');
      if (a && time && title) {
        items.push({ id: Number(li.dataset.kuNewsId), url: a.href, date: time.dateTime, title: title.textContent ?? '' });
      }
    });
    return items;
  }

  private startRefresh(): void {
    if (!this.config.newsUrl) return;
    const refresh = async () => {
      if (document.visibilityState !== 'visible') return;
      try {
        const url = new URL(this.config.newsUrl, window.location.href);
        url.searchParams.set('limit', String(this.config.newsCount));
        const res = await fetch(url.href, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (!res.ok) return;
        const data = (await res.json()) as { items?: unknown };
        const next = normaliseNews(data.items, this.config.newsCount, (u) => safeSiteUrl(u, this.config.siteOrigin));
        if (!next.length || sameNews(next, this.items)) return;
        this.items = next;
        this.render();
      } catch {
        /* Network errors: keep showing the current (server-rendered) headlines. */
      }
    };
    const period = Math.max(120, this.config.newsRefresh) * 1000;
    window.setInterval(refresh, period);
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'visible') void refresh();
    });
    // Server HTML may come from a page cache: check once shortly after load if it is empty.
    if (!this.items.length) window.setTimeout(refresh, 1500);
  }

  private render(): void {
    if (this.section.contains(document.activeElement)) {
      this.pendingRender = true; // never yank content from under keyboard focus
      return;
    }
    this.pendingRender = false;
    this.track.replaceChildren(...this.items.map((item) => this.itemEl(item, false)));
    this.section.hidden = this.items.length === 0;
    this.layout();
  }

  private itemEl(item: NewsItem, clone: boolean): HTMLLIElement {
    const li = document.createElement('li');
    li.className = 'ku-news__item';
    if (clone) li.setAttribute('aria-hidden', 'true');
    else li.dataset.kuNewsId = String(item.id);
    const a = document.createElement('a');
    a.href = item.url;
    if (clone) a.tabIndex = -1;
    const time = document.createElement('time');
    time.dateTime = item.date;
    time.textContent = this.dateFmt.format(new Date(item.date));
    const span = document.createElement('span');
    span.textContent = item.title;
    a.append(span, time);
    li.append(a);
    return li;
  }

  private layout(): void {
    this.track.querySelectorAll('[aria-hidden="true"]').forEach((el) => el.remove());
    this.section.classList.remove('is-animated');
    this.track.style.removeProperty('--ku-marquee-distance');
    this.track.style.removeProperty('--ku-marquee-duration');

    const overflow = this.track.scrollWidth > this.viewport.clientWidth + 8;
    const animate = overflow && !prefersReducedMotion() && this.items.length > 1;
    if (this.toggle) this.toggle.hidden = !animate;
    if (!animate) return;

    const setWidth = this.track.scrollWidth;
    this.items.forEach((item) => this.track.append(this.itemEl(item, true)));
    // The clone set begins with a separator, so shift by exactly one set width.
    const firstClone = this.track.querySelector<HTMLElement>('[aria-hidden="true"]');
    const distance = firstClone ? firstClone.offsetLeft - (this.track.firstElementChild as HTMLElement).offsetLeft : setWidth;
    this.track.style.setProperty('--ku-marquee-distance', `-${distance}px`);
    this.track.style.setProperty('--ku-marquee-duration', `${Math.round(distance / SPEED_PX_PER_S)}s`);
    this.section.classList.add('is-animated');
    this.section.classList.toggle('is-paused', this.paused);
  }

  private setPaused(paused: boolean): void {
    this.paused = paused;
    this.section.classList.toggle('is-paused', paused);
    if (!this.toggle) return;
    // Constant accessible name + aria-pressed (the name must not flip with the state).
    this.toggle.setAttribute('aria-pressed', String(paused));
  }
}
