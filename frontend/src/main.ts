/**
 * iMedix homepage — progressive enhancement entry point.
 *
 * Order of work (each step is independent; a failure never breaks the next):
 *  1. search autocomplete (needs only the graph JSON);
 *  2. news ticker motion and live refresh;
 *  3. floating guide labels around the hero text, once the data has loaded.
 */
import './styles/index.css';
import { Bus } from './bus';
import { readConfig } from './config';
import { loadGraph } from './data/graph';
import { SearchCombobox } from './search/combobox';
import { NewsTicker } from './ticker/ticker';
import { FloatingLabels } from './hero/floats';

function boot(): void {
  const root = document.querySelector<HTMLElement>('[data-ku-root]');
  if (!root || root.dataset.kuBooted) return;
  root.dataset.kuBooted = '1';

  const config = readConfig(root);
  const bus = new Bus();
  adoptThemeHeadingFont(root);

  const form = root.querySelector<HTMLFormElement>('[data-ku-search]');
  const search = form ? safe(() => new SearchCombobox(form, bus, config)) : null;

  const news = root.querySelector<HTMLElement>('[data-ku-news]');
  if (news) safe(() => new NewsTicker(news, config));

  // "Explore drugs / diseases" entry cards focus the search when no archive exists.
  root.querySelectorAll<HTMLAnchorElement>('[data-ku-focus-search]').forEach((a) => {
    a.addEventListener('click', (e) => {
      if (a.getAttribute('href') !== '#ku-q' || !search) return;
      e.preventDefault();
      search.focusWith(a.dataset.kuFocusSearch ?? 'all');
    });
  });

  root.querySelectorAll<HTMLElement>('[data-ku-node]').forEach((chip) => {
    const id = chip.dataset.kuNode ?? null;
    chip.addEventListener('pointerenter', () => bus.emit('chip:hover', { id }));
    chip.addEventListener('pointerleave', () => bus.emit('chip:hover', { id: null }));
    chip.addEventListener('focus', () => bus.emit('chip:hover', { id }));
    chip.addEventListener('blur', () => bus.emit('chip:hover', { id: null }));
  });

  // Small screens: topic cards start collapsed to three guides (CSS, only with JS
  // present so every link stays visible without it); the button reveals the rest.
  root.classList.add('ku-js');
  root.querySelectorAll<HTMLButtonElement>('[data-ku-expand]').forEach((btn) => {
    btn.hidden = false;
    const card = btn.closest('.ku-topic');
    const list = card?.querySelector<HTMLElement>('.ku-topic__list');
    if (list && !list.id) list.id = `${card?.id ?? 'ku-topic'}-list`;
    if (list) btn.setAttribute('aria-controls', list.id);
    btn.addEventListener('click', () => {
      const open = btn.getAttribute('aria-expanded') !== 'true';
      btn.setAttribute('aria-expanded', String(open));
      card?.classList.toggle('is-open', open);
      if (open) list?.querySelectorAll('a')[3]?.focus();
    });
  });

  const graphPromise = loadGraph([config.graphUrl, config.graphRestUrl], config.graphVersion, config.siteOrigin);
  const hero = root.querySelector<HTMLElement>('.ku-hero');
  graphPromise.then(
    (graph) => {
      search?.setGraph(graph);
      if (hero) safe(() => new FloatingLabels(hero, graph, bus));
    },
    () => search?.setError(),
  );
}

/**
 * Headings (h1–h3) already use the theme's own heading rules. Non-heading display
 * text (stat numerals, card titles) mirrors them through --ku-font-heading, read
 * from the rendered H1 so no font name is ever hard-coded.
 */
function adoptThemeHeadingFont(root: HTMLElement): void {
  const h1 = root.querySelector('h1');
  if (!h1) return;
  const family = getComputedStyle(h1).fontFamily;
  if (family) root.style.setProperty('--ku-font-heading', family);
}

function safe<T>(fn: () => T): T | null {
  try {
    return fn();
  } catch (err) {
    if (import.meta.env.DEV) console.error(err);
    return null;
  }
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
else boot();
