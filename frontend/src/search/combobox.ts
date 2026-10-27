/**
 * Accessible autocomplete for the server-rendered search form
 * (WAI-ARIA 1.2 combobox + listbox pattern).
 *
 * Without JavaScript, or if the graph cannot load, the form still submits to
 * the native WordPress search (?s=). With JavaScript, suggestions link straight
 * to drug and disease guides and the last option always offers full search.
 */
import type { Bus } from '../bus';
import type { KGraph, KUConfig } from '../types';
import { SearchIndex, type SearchHit, type TypeFilter } from './engine';
import { searchUrl } from '../util/url';

const TYPE_LABEL: Record<string, string> = { drug: 'Drug', disease: 'Disease' };
const MAX_QUERY = 100;

interface Option {
  id: string;
  href: string;
  nodeId: string | null;
}

export class SearchCombobox {
  private input: HTMLInputElement;
  private panel: HTMLElement;
  private list: HTMLUListElement;
  private status: HTMLElement;
  private filterBox: HTMLFieldSetElement | null;
  private index: SearchIndex | null = null;
  private state: 'loading' | 'ready' | 'error' = 'loading';
  private options: Option[] = [];
  private active = -1;
  private timer = 0;
  private lastQuery = '';
  private announceTimer = 0;

  constructor(
    private form: HTMLFormElement,
    private bus: Bus,
    private config: KUConfig,
  ) {
    this.input = form.querySelector<HTMLInputElement>('input[type="search"]')!;
    this.panel = form.querySelector<HTMLElement>('[data-ku-panel]')!;
    this.list = this.panel.querySelector<HTMLUListElement>('[role="listbox"]')!;
    this.status = form.querySelector<HTMLElement>('[data-ku-status]')!;
    this.filterBox = form.querySelector<HTMLFieldSetElement>('[data-ku-filter]');

    this.input.setAttribute('role', 'combobox');
    this.input.setAttribute('aria-autocomplete', 'list');
    this.input.setAttribute('aria-expanded', 'false');
    this.input.setAttribute('aria-controls', this.list.id);
    if (this.filterBox) this.filterBox.hidden = false;

    this.input.addEventListener('input', () => this.schedule());
    this.input.addEventListener('focus', () => {
      if (this.input.value.trim()) this.update();
    });
    this.input.addEventListener('keydown', (e) => this.onKey(e));
    // Choosing a type keeps the caret in the field (the filter only shows while searching).
    this.filterBox?.addEventListener('mousedown', (e) => {
      if ((e.target as Element).closest('label')) e.preventDefault();
    });
    this.filterBox?.addEventListener('change', () => {
      this.update();
      this.input.focus();
    });
    this.list.addEventListener('mousedown', (e) => e.preventDefault()); // keep focus in the input
    this.list.addEventListener('click', (e) => {
      const li = (e.target as Element).closest<HTMLElement>('[role="option"]');
      if (li) this.choose(Number(li.dataset.index));
    });
    this.list.addEventListener('mousemove', (e) => {
      const li = (e.target as Element).closest<HTMLElement>('[role="option"]');
      if (li && Number(li.dataset.index) !== this.active) this.setActive(Number(li.dataset.index), false);
    });
    document.addEventListener('pointerdown', (e) => {
      if (!this.form.contains(e.target as Node)) this.close();
    });
    this.form.addEventListener('submit', (e) => {
      if (this.active >= 0) {
        e.preventDefault();
        this.choose(this.active);
      }
    });
  }

  /** Attach the entity index once the graph is available. */
  setGraph(graph: KGraph): void {
    this.index = new SearchIndex(graph);
    this.state = 'ready';
    if (document.activeElement === this.input && this.input.value.trim()) this.update();
  }

  setError(): void {
    this.state = 'error';
    if (document.activeElement === this.input && this.input.value.trim()) this.update();
  }

  /** Focus the field, optionally pre-selecting a type filter. */
  focusWith(filter: TypeFilter): void {
    const radio = this.filterBox?.querySelector<HTMLInputElement>(`input[value="${filter}"]`);
    if (radio) radio.checked = true;
    this.input.focus({ preventScroll: true });
    this.input.scrollIntoView({ block: 'center', behavior: 'smooth' });
    if (this.input.value.trim()) this.update();
  }

  private get filter(): TypeFilter {
    return this.filterBox?.querySelector<HTMLInputElement>('input:checked')?.value ?? 'all';
  }

  private schedule(): void {
    window.clearTimeout(this.timer);
    this.timer = window.setTimeout(() => this.update(), 60);
  }

  private update(): void {
    const query = this.input.value.slice(0, MAX_QUERY).trim();
    if (!query) {
      this.close();
      this.lastQuery = '';
      this.bus.emit('search:clear', {});
      return;
    }
    this.lastQuery = query;

    let hits: SearchHit[] = [];
    if (this.state === 'ready' && this.index) {
      hits = this.index.search(query, this.filter, 8);
      this.bus.emit('search:results', { query, ids: hits.map((h) => h.node.id) });
    }
    this.render(query, hits);
  }

  private render(query: string, hits: SearchHit[]): void {
    this.list.replaceChildren();
    this.options = [];
    this.active = -1;
    this.input.removeAttribute('aria-activedescendant');
    this.panel.querySelector('.ku-search__message')?.remove();

    let message = '';
    if (this.state === 'loading') message = 'Loading the drug and disease library…';
    else if (this.state === 'error') message = 'Suggestions are unavailable right now. Press Enter to search all articles.';
    else if (!hits.length) message = `No drug or disease matches “${query}”. Check the spelling, or search all iMedix articles.`;

    if (message) {
      const p = document.createElement('p');
      p.className = 'ku-search__message';
      p.textContent = message;
      this.panel.insertBefore(p, this.list);
    }

    hits.forEach((hit) => this.addHit(hit, query));
    this.addSearchAll(query);

    this.panel.hidden = false;
    this.input.setAttribute('aria-expanded', 'true');
    this.announce(hits.length, query);
  }

  private addHit(hit: SearchHit, query: string): void {
    const url = hit.node.url;
    if (!url) return;
    const li = this.option(url, hit.node.id);
    li.className = 'ku-opt';

    const glyph = document.createElement('span');
    glyph.className = `ku-glyph ku-glyph--${hit.node.type}`;
    glyph.setAttribute('aria-hidden', 'true');

    const body = document.createElement('span');
    const name = document.createElement('span');
    name.className = 'ku-opt__name';
    appendHighlighted(name, hit.node.name, hit.via ? '' : query);
    const sub = document.createElement('span');
    sub.className = 'ku-opt__sub';
    const active = hit.node.actives && hit.node.actives.toLowerCase() !== hit.node.name.toLowerCase() ? `${hit.node.actives} · ` : '';
    sub.textContent = (hit.via ? `Matches “${hit.via}” · ` : '') + active + displayPath(url);
    body.append(name, sub);

    const type = document.createElement('span');
    type.className = 'ku-opt__type';
    type.dataset.type = hit.node.type;
    type.textContent = TYPE_LABEL[hit.node.type] ?? hit.node.type;

    li.append(glyph, body, type);
    this.list.append(li);
  }

  private addSearchAll(query: string): void {
    const li = this.option(searchUrl(this.config.searchUrl, query, this.config.siteOrigin), null);
    li.className = 'ku-opt ku-opt--all';
    const ns = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(ns, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('class', 'ku-icon');
    svg.setAttribute('aria-hidden', 'true');
    const c = document.createElementNS(ns, 'circle');
    c.setAttribute('cx', '11');
    c.setAttribute('cy', '11');
    c.setAttribute('r', '7');
    const p = document.createElementNS(ns, 'path');
    p.setAttribute('d', 'm20 20-3.5-3.5');
    svg.append(c, p);
    const text = document.createElement('span');
    text.className = 'ku-opt__name';
    text.textContent = `Search all iMedix articles for “${query}”`;
    li.append(svg, text);
    this.list.append(li);
  }

  private option(href: string, nodeId: string | null): HTMLLIElement {
    const index = this.options.length;
    const li = document.createElement('li');
    li.id = `ku-opt-${index}`;
    li.setAttribute('role', 'option');
    li.setAttribute('aria-selected', 'false');
    li.dataset.index = String(index);
    this.options.push({ id: li.id, href, nodeId });
    return li;
  }

  private onKey(e: KeyboardEvent): void {
    const open = !this.panel.hidden;
    switch (e.key) {
      case 'ArrowDown':
        e.preventDefault();
        if (!open) this.update();
        else this.setActive(this.active + 1 >= this.options.length ? 0 : this.active + 1, true);
        break;
      case 'ArrowUp':
        e.preventDefault();
        if (open) this.setActive(this.active <= 0 ? this.options.length - 1 : this.active - 1, true);
        break;
      case 'Escape':
        if (open) {
          e.preventDefault();
          this.close();
        } else if (this.input.value) {
          e.preventDefault();
          this.input.value = '';
          this.bus.emit('search:clear', {});
        }
        break;
      case 'Tab':
        this.close();
        break;
      default:
        break;
    }
  }

  private setActive(index: number, scroll: boolean): void {
    const prev = this.list.querySelector('[aria-selected="true"]');
    prev?.setAttribute('aria-selected', 'false');
    this.active = index;
    const opt = this.options[index];
    if (!opt) {
      this.input.removeAttribute('aria-activedescendant');
      this.bus.emit('search:active', { id: null });
      return;
    }
    const li = document.getElementById(opt.id);
    li?.setAttribute('aria-selected', 'true');
    this.input.setAttribute('aria-activedescendant', opt.id);
    if (scroll) li?.scrollIntoView({ block: 'nearest' });
    this.bus.emit('search:active', { id: opt.nodeId });
  }

  private choose(index: number): void {
    const opt = this.options[index];
    if (!opt) return;
    this.close();
    window.location.assign(opt.href);
  }

  private close(): void {
    if (this.panel.hidden) return;
    this.panel.hidden = true;
    this.input.setAttribute('aria-expanded', 'false');
    this.input.removeAttribute('aria-activedescendant');
    this.active = -1;
    this.bus.emit('search:active', { id: null });
  }

  private announce(count: number, query: string): void {
    window.clearTimeout(this.announceTimer);
    this.announceTimer = window.setTimeout(() => {
      if (query !== this.lastQuery) return;
      this.status.textContent =
        this.state === 'loading'
          ? 'Loading suggestions.'
          : count
            ? `${count} ${count === 1 ? 'suggestion' : 'suggestions'} available.`
            : 'No suggestions. Press Enter to search all articles.';
    }, 400);
  }
}

function displayPath(url: string): string {
  try {
    const u = new URL(url);
    return u.hostname.replace(/^www\./, '') + u.pathname;
  } catch {
    return url;
  }
}

/** Append text with the first case-insensitive occurrence of `query` wrapped in <mark>. */
function appendHighlighted(el: HTMLElement, text: string, query: string): void {
  const q = query.trim().toLowerCase();
  const at = q ? text.toLowerCase().indexOf(q) : -1;
  if (at < 0) {
    el.textContent = text;
    return;
  }
  const mark = document.createElement('mark');
  mark.textContent = text.slice(at, at + q.length);
  el.append(text.slice(0, at), mark, text.slice(at + q.length));
}
