/**
 * Lazy-loaded WebGL view: wires the sphere scene to the HUD, contextual labels,
 * details card and the search bus. Imported dynamically so three.js never blocks
 * first paint.
 */
import type { Bus } from '../bus';
import type { KGraph } from '../types';
import { SphereScene } from './spheres';
import { NodeCard } from './card';
import { readPalette } from './palette';
import { compositionBoxes } from './stage-boxes';

export interface MountOptions {
  hero: HTMLElement;
  stage: HTMLElement;
  graph: KGraph;
  bus: Bus;
  count: number;
  tier: 'high' | 'medium' | 'low';
  reducedMotion: boolean;
  onFatal: () => void;
}

const TYPE_LABEL: Record<string, string> = { drug: 'Drug', disease: 'Disease', topic: 'Editorial topic' };
const MAX_MATCH_LABELS = 6;

type Box = { l: number; t: number; r: number; b: number };

export function mountWebGL(o: MountOptions): () => void {
  const { hero, stage, graph, bus } = o;
  const hud = hero.querySelector<HTMLElement>('[data-ku-hud]');
  const cardEl = hero.querySelector<HTMLElement>('[data-ku-card]');
  const inner = hero.querySelector<HTMLElement>('.ku-hero__inner');
  const field = hero.querySelector<HTMLElement>('.ku-search__field');

  // Overlay: contextual labels + connector from the search field to the best match.
  const labels = document.createElement('div');
  labels.className = 'ku-labels';
  labels.setAttribute('aria-hidden', 'true');
  stage.after(labels);
  const NS = 'http://www.w3.org/2000/svg';
  const connector = document.createElementNS(NS, 'svg');
  connector.setAttribute('class', 'ku-connector');
  const path = document.createElementNS(NS, 'path');
  connector.append(path);
  labels.append(connector);

  const boxOf = (el: HTMLElement | null, pad: number, origin: DOMRect): Box | null => {
    if (!el) return null;
    const r = el.getBoundingClientRect();
    return { l: r.left - origin.left - pad, t: r.top - origin.top - pad, r: r.right - origin.left + pad, b: r.bottom - origin.top + pad };
  };

  // The HUD is part of the composition (spheres avoid it), so show it first.
  hud?.querySelector<HTMLElement>('.ku-controls')?.removeAttribute('hidden');
  if (hud) hud.hidden = false;
  const boxes = compositionBoxes(hero, stage);
  const hudParts = hud ? [...hud.querySelectorAll<HTMLElement>('.ku-legend, .ku-controls')] : [];

  let card: NodeCard | null = null;
  let hovered: number | null = null;
  let matches: number[] = [];
  const pool = new Map<number, HTMLElement>();

  const scene = new SphereScene(stage, graph, {
    count: o.count,
    palette: readPalette(stage),
    reducedMotion: o.reducedMotion,
    tier: o.tier,
    getSafe: boxes.safe,
    getAvoid: boxes.avoid,
    getFocusShift: () => {
      const s = stage.getBoundingClientRect();
      const c = cardEl && !cardEl.hidden ? cardEl.getBoundingClientRect() : null;
      if (!c || !c.width) return { x: 0, y: 0 };
      // Card on the right (desktop): centre of the area to its left.
      if (c.left - s.left > s.width * 0.45) return { x: (c.left - s.left) / s.width - 1, y: 0 };
      // Bottom sheet (phones): centre of the area above it.
      return { x: 0, y: 1 - (c.top - s.top) / s.height };
    },
    onHover: (index) => (hovered = index),
    onSelect: (index) => {
      card?.show(index);
      hero.classList.toggle('has-card', index !== null);
      bus.emit('graph:select', { id: index === null ? null : graph.nodes[index].id });
    },
    onContextLost: () => o.onFatal(),
  });
  if (cardEl) card = new NodeCard(cardEl, graph, (index) => scene.select(index));

  const labelFor = (index: number): HTMLElement => {
    let el = pool.get(index);
    if (!el) {
      const n = graph.nodes[index];
      el = document.createElement('div');
      el.className = 'ku-label';
      el.dataset.type = n.type;
      const name = document.createElement('span');
      name.className = 'ku-label__name';
      name.textContent = n.name;
      const type = document.createElement('span');
      type.className = 'ku-label__type';
      type.textContent = TYPE_LABEL[n.type] ?? n.type;
      el.append(name, type);
      labels.append(el);
      pool.set(index, el);
    }
    return el;
  };

  let raf = 0;
  const place = () => {
    raf = requestAnimationFrame(place);
    const origin = stage.getBoundingClientRect();
    const safe = boxOf(inner, 10, origin);
    const hudBoxes = [...hudParts, ...(card?.open && cardEl ? [cardEl] : [])]
      .map((el) => boxOf(el, 6, origin))
      .filter((b): b is Box => b !== null);
    const selected = scene.selection;
    // Contextual labelling only: selected, hovered, search matches, then a few featured hubs.
    const wanted: { index: number; kind: string }[] = [];
    const add = (index: number | null, kind: string) => {
      if (index !== null && !wanted.some((w) => w.index === index)) wanted.push({ index, kind });
    };
    add(selected, 'selected');
    add(hovered, 'hover');
    matches.slice(0, MAX_MATCH_LABELS).forEach((m, k) => add(m, k === 0 ? 'best' : 'match'));
    if (!matches.length && selected === null) scene.featured().forEach((f) => add(f, 'featured'));

    const placed: Box[] = [];
    const shown = new Set<number>();
    for (const w of wanted) {
      const s = scene.screenOf(w.index);
      if (!s) continue;
      const el = labelFor(w.index);
      el.dataset.kind = w.kind;
      const hw = (el.offsetWidth || 120) / 2 + 4;
      const hh = (el.offsetHeight || 40) / 2 + 3;
      let at: Box | null = null;
      let pos = { x: 0, y: 0 };
      for (const [x, y] of [
        [s.x, s.y + s.r + hh + 6],
        [s.x, s.y - s.r - hh - 6],
        [s.x - s.r - hw - 6, s.y],
        [s.x + s.r + hw + 6, s.y],
      ]) {
        const b = { l: x - hw, t: y - hh, r: x + hw, b: y + hh };
        if (b.l < 4 || b.r > origin.width - 4 || b.t < 4 || b.b > origin.height - 4) continue;
        // Selected/hover labels may overlap the text column; ambient ones never do.
        if (w.kind !== 'selected' && w.kind !== 'hover' && safe && !(b.r < safe.l || b.l > safe.r || b.b < safe.t || b.t > safe.b)) continue;
        if ([...placed, ...hudBoxes].some((p) => !(b.r < p.l || b.l > p.r || b.b < p.t || b.t > p.b))) continue;
        at = b;
        pos = { x, y };
        break;
      }
      if (!at) continue;
      placed.push(at);
      shown.add(w.index);
      el.style.transform = `translate(${pos.x.toFixed(1)}px, ${pos.y.toFixed(1)}px) translate(-50%, -50%)`;
      // Sphere the label belongs to (stage px), for hit-testing in the e2e suite.
      el.dataset.anchor = `${Math.round(s.x)} ${Math.round(s.y)} ${Math.round(s.r)}`;
      el.style.opacity = '1';
    }
    pool.forEach((el, index) => {
      if (!shown.has(index)) el.style.opacity = '0';
    });

    // Connector: search field → best match.
    const best = matches[0] !== undefined ? scene.screenOf(matches[0]) : null;
    const f = boxOf(field, 0, origin);
    // Only drawn sideways: a vertical connector would cut through the headline.
    if (best && f && (best.x < f.l || best.x > f.r)) {
      const x0 = best.x < f.l ? f.l : f.r;
      const y0 = (f.t + f.b) / 2;
      const tx = best.x + (best.x < x0 ? best.r : -best.r);
      const ty = best.y;
      const mx = (x0 + tx) / 2;
      path.setAttribute('d', `M${x0.toFixed(1)} ${y0.toFixed(1)} C${mx.toFixed(1)} ${y0.toFixed(1)} ${mx.toFixed(1)} ${ty.toFixed(1)} ${tx.toFixed(1)} ${ty.toFixed(1)}`);
      connector.classList.add('is-on');
    } else connector.classList.remove('is-on');
  };
  raf = requestAnimationFrame(place);

  // Recompose when the text column changes size (fonts, viewport).
  const ro = new ResizeObserver(() => scene.relayout());
  if (inner) ro.observe(inner);

  const toIndices = (ids: string[]) => ids.map((id) => graph.byId.get(id)).filter((i): i is number => i !== undefined);
  let searchTimer = 0;
  const offs = [
    bus.on('search:results', ({ ids }) => {
      window.clearTimeout(searchTimer);
      // Let typing settle before spheres travel, so they don't jitter per keystroke.
      searchTimer = window.setTimeout(() => {
        matches = toIndices(ids);
        scene.setMatches(matches);
      }, 180);
    }),
    bus.on('search:clear', () => {
      window.clearTimeout(searchTimer);
      matches = [];
      scene.setMatches([]);
    }),
    bus.on('search:active', ({ id }) => scene.setFocus(id ? (graph.byId.get(id) ?? null) : null)),
    bus.on('chip:hover', ({ id }) => scene.setFocus(id ? (graph.byId.get(id) ?? null) : null)),
    bus.on('map:show', ({ id }) => {
      const index = graph.byId.get(id);
      if (index !== undefined) scene.select(index);
    }),
  ];

  const motionBtn = hud?.querySelector<HTMLButtonElement>('[data-ku-action="motion"]');
  const syncMotion = () => motionBtn?.setAttribute('aria-pressed', String(!scene.moving));
  syncMotion();
  const onHud = (e: Event) => {
    const btn = (e.target as Element).closest<HTMLButtonElement>('[data-ku-action]');
    if (!btn) return;
    switch (btn.dataset.kuAction) {
      case 'zoom-in':
        scene.zoomBy(1.18);
        break;
      case 'zoom-out':
        scene.zoomBy(0.85);
        break;
      case 'reset':
        scene.reset();
        break;
      case 'motion':
        scene.setMotion(!scene.moving);
        syncMotion();
        break;
    }
  };
  hud?.addEventListener('click', onHud);
  const onKey = (e: KeyboardEvent) => {
    if (e.key === 'Escape' && card?.open && !(document.activeElement instanceof HTMLInputElement)) scene.select(null);
  };
  document.addEventListener('keydown', onKey);
  const onFocusIn = () => {
    if (card?.open) scene.select(null);
  };
  inner?.addEventListener('focusin', onFocusIn);

  stage.classList.add('is-ready');
  bus.emit('graph:ready', { mode: 'webgl' });

  return () => {
    cancelAnimationFrame(raf);
    ro.disconnect();
    offs.forEach((off) => off());
    hud?.removeEventListener('click', onHud);
    document.removeEventListener('keydown', onKey);
    inner?.removeEventListener('focusin', onFocusIn);
    scene.dispose();
    labels.remove();
    card?.show(null);
    if (hud) hud.hidden = true;
    hero.classList.remove('has-card');
  };
}
