/**
 * Floating guide labels around the hero text: real iMedix drug and disease
 * guides from the synchronised graph, each a plain link to its WordPress article.
 *
 * Search drives them: matches move next to the search field (best match first,
 * highlighted), the remaining labels step back. The option highlighted in the
 * autocomplete, or a hovered "Recently updated" chip, highlights its label.
 * Motion is a slow CSS drift that stops for reduced motion and on hover/focus.
 */
import type { Bus } from '../bus';
import type { KGraph } from '../types';
import { labelCount, layoutLabels, pickLabels, type Rect } from './label-layout';

const TYPE_LABEL: Record<string, string> = { drug: 'Drug', disease: 'Disease' };
const MAX_MATCHES = 4;

export class FloatingLabels {
  private list: HTMLUListElement;
  private items = new Map<number, HTMLLIElement>();
  private defaults: number[] = [];
  private matches: number[] = [];
  private frame = 0;
  private offs: (() => void)[] = [];
  private ro: ResizeObserver | null = null;

  constructor(
    private hero: HTMLElement,
    private graph: KGraph,
    bus: Bus,
  ) {
    this.list = document.createElement('ul');
    this.list.className = 'ku-floats';
    this.list.setAttribute('aria-label', 'Popular guides');
    // After the text column in the DOM: the search stays the first stop for keyboard users.
    (hero.querySelector('.ku-hero__inner') ?? hero.lastElementChild)?.after(this.list);

    // Entities already offered as "Recently updated" chips are not repeated.
    const chips = new Set([...hero.querySelectorAll<HTMLElement>('[data-ku-node]')].map((c) => graph.byId.get(c.dataset.kuNode ?? '')));
    const count = labelCount(window.innerWidth);
    this.defaults = pickLabels(graph, count + chips.size)
      .filter((i) => !chips.has(i))
      .slice(0, count);
    this.render();

    const toIndex = (id: string | null) => (id === null ? null : (graph.byId.get(id) ?? null));
    let timer = 0;
    this.offs.push(
      bus.on('search:results', ({ ids }) => {
        window.clearTimeout(timer);
        // Let typing settle so labels don't jump on every keystroke.
        timer = window.setTimeout(() => {
          this.matches = ids
            .map((id) => graph.byId.get(id))
            .filter((i): i is number => i !== undefined && !!graph.nodes[i].url && ['drug', 'disease'].includes(graph.nodes[i].type))
            .slice(0, MAX_MATCHES);
          this.render();
        }, 160);
      }),
      bus.on('search:clear', () => {
        window.clearTimeout(timer);
        this.matches = [];
        this.render();
      }),
      bus.on('search:active', ({ id }) => this.highlight(toIndex(id))),
      bus.on('chip:hover', ({ id }) => this.highlight(toIndex(id))),
    );

    if ('ResizeObserver' in window) {
      this.ro = new ResizeObserver(() => this.schedule());
      this.ro.observe(hero);
      const inner = hero.querySelector('.ku-hero__inner');
      if (inner) this.ro.observe(inner);
    }
  }

  destroy(): void {
    cancelAnimationFrame(this.frame);
    this.ro?.disconnect();
    this.offs.forEach((off) => off());
    this.list.remove();
  }

  private schedule(): void {
    cancelAnimationFrame(this.frame);
    this.frame = requestAnimationFrame(() => this.place());
  }

  /** Which labels are shown (matches first) and in what state. */
  private render(): void {
    const wanted = [...this.matches, ...this.defaults.filter((i) => !this.matches.includes(i))].slice(
      0,
      Math.max(this.defaults.length, this.matches.length),
    );
    for (const index of wanted) {
      const li = this.itemFor(index);
      const k = this.matches.indexOf(index);
      li.classList.toggle('is-match', k >= 0);
      li.classList.toggle('is-best', k === 0);
      li.classList.toggle('is-muted', this.matches.length > 0 && k < 0);
    }
    this.items.forEach((li, index) => {
      if (!wanted.includes(index)) this.hide(li);
    });
    this.list.dataset.order = wanted.join(',');
    this.schedule();
  }

  private itemFor(index: number): HTMLLIElement {
    let li = this.items.get(index);
    if (li) return li;
    const node = this.graph.nodes[index];
    li = document.createElement('li');
    li.className = 'ku-float-item is-new';
    li.dataset.type = node.type;
    li.dataset.id = node.id;
    li.hidden = true;
    const a = document.createElement('a');
    a.className = 'ku-float';
    a.href = node.url ?? '#';
    const dot = document.createElement('span');
    dot.className = `ku-glyph ku-glyph--${node.type}`;
    dot.setAttribute('aria-hidden', 'true');
    const name = document.createElement('span');
    name.className = 'ku-float__name';
    name.textContent = node.name;
    const type = document.createElement('span');
    type.className = 'ku-float__type';
    type.textContent = TYPE_LABEL[node.type] ?? node.type;
    a.append(dot, name, type);
    a.addEventListener('pointerenter', () => this.highlight(index));
    a.addEventListener('pointerleave', () => this.highlight(null));
    li.append(a);
    // Each label drifts on its own slow orbit (pure CSS; off for reduced motion).
    const seed = (index * 2654435761) >>> 0;
    li.style.setProperty('--ku-drift-x', `${((seed % 9) - 4) * 1.5}px`);
    li.style.setProperty('--ku-drift-y', `${(((seed >> 4) % 9) - 4) * 1.5}px`);
    li.style.setProperty('--ku-drift-t', `${11 + (seed % 7)}s`);
    li.style.setProperty('--ku-drift-d', `-${seed % 9}s`);
    this.list.append(li);
    this.items.set(index, li);
    return li;
  }

  private hide(li: HTMLLIElement): void {
    li.classList.add('is-leaving');
    li.classList.remove('is-match', 'is-best', 'is-muted', 'is-active');
    window.setTimeout(() => {
      if (li.classList.contains('is-leaving')) li.hidden = true;
    }, 260);
  }

  private highlight(index: number | null): void {
    this.items.forEach((li, i) => li.classList.toggle('is-active', i === index));
  }

  private place(): void {
    const order = (this.list.dataset.order ?? '').split(',').filter(Boolean).map(Number);
    const box = this.hero.getBoundingClientRect();
    const rel = (el: Element | null, pad: number): Rect | null => {
      if (!el) return null;
      const r = el.getBoundingClientRect();
      if (!r.width || !r.height) return null;
      return { l: r.left - box.left - pad, t: r.top - box.top - pad, r: r.right - box.left + pad, b: r.bottom - box.top + pad };
    };
    const safe = rel(this.hero.querySelector('.ku-hero__inner'), 10) ?? { l: box.width * 0.25, r: box.width * 0.75, t: box.height * 0.2, b: box.height * 0.8 };
    const avoid = [...this.hero.querySelectorAll('.ku-legend')].map((el) => rel(el, 6)).filter((r): r is Rect => r !== null);
    const field = rel(this.hero.querySelector('.ku-search__field'), 0);
    // The open suggestion panel is part of the text column while it is shown.
    const panel = rel(this.hero.querySelector('.ku-search__panel:not([hidden])'), 10);
    if (panel) avoid.push(panel);
    // Matches gather beside the search field (wide screens) or above the headline.
    const wide = box.width >= 900;
    const anchor = field
      ? wide
        ? { x: field.r + 150, y: (field.t + field.b) / 2 }
        : { x: box.width / 2, y: Math.max(0, safe.t - 30) }
      : null;

    // Measure while still in flow-less absolute position (hidden ones need showing first).
    const els = order.map((i) => this.items.get(i)!);
    els.forEach((li) => {
      li.hidden = false;
      li.classList.remove('is-leaving');
    });
    const sizes = els.map((li) => ({ w: li.offsetWidth, h: li.offsetHeight }));
    const spots = layoutLabels({
      width: box.width,
      height: box.height,
      safe,
      avoid,
      sizes,
      anchor,
      anchored: this.matches.length,
    });
    els.forEach((li, k) => {
      const p = spots[k];
      if (!p) {
        li.hidden = true;
        return;
      }
      li.style.transform = `translate(${Math.round(p.x)}px, ${Math.round(p.y)}px)`;
      // First placement appears in place (fade only); later ones glide.
      if (li.classList.contains('is-new')) requestAnimationFrame(() => li.classList.remove('is-new'));
    });
  }
}
