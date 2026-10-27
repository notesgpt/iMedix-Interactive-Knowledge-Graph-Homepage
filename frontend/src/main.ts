/**
 * iMedix Knowledge Universe — progressive enhancement entry point.
 *
 * Order of work (each step is independent; a failure never breaks the next):
 *  1. search autocomplete (needs only the graph JSON, never WebGL);
 *  2. news ticker motion and live refresh;
 *  3. the 3D graph, lazy-loaded after first paint when the hero is visible.
 */
import './styles/index.css';
import { Bus } from './bus';
import { readConfig } from './config';
import { loadGraph } from './data/graph';
import { SearchCombobox } from './search/combobox';
import { NewsTicker } from './ticker/ticker';
import { sphereCount } from './graph/sphere-layout';
import { compositionBoxes } from './graph/stage-boxes';
import { detectTier } from './graph/capabilities';
import { mountFallback } from './graph/fallback';
import { onIdle, prefersReducedMotion } from './util/env';
import type { KGraph } from './types';

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

  // "Show on map" (topic cards) only makes sense once the interactive map exists.
  const mapButtons = root.querySelectorAll<HTMLButtonElement>('[data-ku-show-node]');
  bus.on('graph:ready', ({ mode }) => mapButtons.forEach((b) => (b.hidden = mode !== 'webgl')));
  mapButtons.forEach((b) =>
    b.addEventListener('click', () => {
      const hero = root.querySelector<HTMLElement>('.ku-hero');
      hero?.scrollIntoView({ behavior: prefersReducedMotion() ? 'auto' : 'smooth', block: 'start' });
      bus.emit('map:show', { id: b.dataset.kuShowNode ?? '' });
    }),
  );

  const graphPromise = loadGraph([config.graphUrl, config.graphRestUrl], config.graphVersion, config.siteOrigin);
  graphPromise.then(
    (graph) => search?.setGraph(graph),
    () => search?.setError(),
  );

  const hero = root.querySelector<HTMLElement>('.ku-hero');
  const stage = root.querySelector<HTMLElement>('[data-ku-stage]');
  if (hero && stage) {
    whenVisible(hero, () => onIdle(() => void startGraph(hero, stage, graphPromise, bus)));
  }
}

async function startGraph(hero: HTMLElement, stage: HTMLElement, graphPromise: Promise<KGraph>, bus: Bus): Promise<void> {
  let graph: KGraph;
  try {
    graph = await graphPromise;
  } catch {
    return; // No data: the static hero background remains.
  }
  const tier = detectTier();
  const count = sphereCount(window.innerWidth, tier === 'none' ? 'low' : tier);
  const { safe, avoid } = compositionBoxes(hero, stage);
  const fallback = () => {
    // Static spheres: keep the entity statistics, drop the camera controls.
    const hud = hero.querySelector<HTMLElement>('[data-ku-hud]');
    hud?.querySelector<HTMLElement>('.ku-controls')?.setAttribute('hidden', '');
    if (hud) hud.hidden = false;
    return mountFallback(stage, graph, count, safe, avoid, bus);
  };
  if (tier === 'none') {
    fallback();
    return;
  }
  try {
    const { mountWebGL } = await import('./graph/view');
    let unmount: (() => void) | null = null;
    unmount = mountWebGL({
      hero,
      stage,
      graph,
      bus,
      count,
      tier,
      reducedMotion: prefersReducedMotion(),
      onFatal: () => {
        unmount?.();
        fallback();
      },
    });
  } catch {
    fallback();
  }
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

function whenVisible(el: HTMLElement, cb: () => void): void {
  if (!('IntersectionObserver' in window)) {
    cb();
    return;
  }
  const io = new IntersectionObserver((entries) => {
    if (entries.some((e) => e.isIntersecting)) {
      io.disconnect();
      cb();
    }
  });
  io.observe(el);
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
