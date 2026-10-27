/**
 * Lazy-loaded WebGL view: wires the renderer to the HUD, labels, details card
 * and the search bus. Imported dynamically so three.js never blocks first paint.
 */
import type { Bus } from '../bus';
import type { KGraph } from '../types';
import { GraphRenderer } from './renderer';
import { NodeCard } from './card';
import { readPalette } from './palette';

export interface MountOptions {
  hero: HTMLElement;
  stage: HTMLElement;
  graph: KGraph;
  bus: Bus;
  positions: Float32Array;
  stages: number[][];
  tier: 'high' | 'medium' | 'low';
  reducedMotion: boolean;
  touch: boolean;
  onFatal: () => void;
}

const MAX_MATCH_LABELS = 6;

export function mountWebGL(o: MountOptions): () => void {
  const { hero, stage, graph, bus } = o;
  const hud = hero.querySelector<HTMLElement>('[data-ku-hud]');
  const cardEl = hero.querySelector<HTMLElement>('[data-ku-card]');

  // Overlay layers: constellation names + labels for search matches + hover tooltip.
  const labels = document.createElement('div');
  labels.className = 'ku-labels';
  labels.setAttribute('aria-hidden', 'true');
  const tooltip = document.createElement('div');
  tooltip.className = 'ku-tooltip';
  tooltip.hidden = true;
  stage.after(labels);
  labels.append(tooltip);

  const topicLabels = new Map<number, HTMLElement>();
  graph.nodes
    .filter((n) => n.type === 'topic')
    .forEach((n) => {
      const el = document.createElement('div');
      el.className = 'ku-label';
      el.textContent = n.name;
      labels.append(el);
      topicLabels.set(n.index, el);
    });
  let matchLabels: { index: number; el: HTMLElement }[] = [];

  // Connector from the search field to the best visible match: the search
  // "reaches into" the map without moving the camera behind the text.
  const NS = 'http://www.w3.org/2000/svg';
  const connector = document.createElementNS(NS, 'svg');
  connector.setAttribute('class', 'ku-connector');
  const path = document.createElementNS(NS, 'path');
  const dot = document.createElementNS(NS, 'circle');
  dot.setAttribute('r', '5');
  connector.append(path, dot);
  labels.append(connector);

  const inner = hero.querySelector<HTMLElement>('.ku-hero__inner');
  const field = hero.querySelector<HTMLElement>('.ku-search__field');
  type Box = { l: number; t: number; r: number; b: number };
  const boxOf = (el: HTMLElement | null, pad: number, origin: DOMRect): Box | null => {
    if (!el) return null;
    const r = el.getBoundingClientRect();
    return { l: r.left - origin.left - pad, t: r.top - origin.top - pad, r: r.right - origin.left + pad, b: r.bottom - origin.top + pad };
  };
  const inside = (b: Box | null, x: number, y: number) => !!b && x > b.l && x < b.r && y > b.t && y < b.b;

  let card: NodeCard | null = null;
  let hintTimer = 0;
  let hintShown = 0;
  const hint = document.createElement('p');
  hint.className = 'ku-hint';
  hint.setAttribute('role', 'status');
  hint.hidden = true;
  hud?.prepend(hint);

  const renderer = new GraphRenderer(stage, graph, {
    positions: o.positions,
    stages: o.stages,
    reducedMotion: o.reducedMotion,
    palette: readPalette(stage),
    tier: o.tier,
    touch: o.touch,
    onHover: (index, x, y) => {
      if (index === null || index === renderer?.selection) {
        tooltip.hidden = true;
        return;
      }
      const n = graph.nodes[index];
      tooltip.dataset.type = n.type;
      tooltip.replaceChildren(document.createTextNode(n.name));
      const small = document.createElement('small');
      small.textContent = n.type === 'topic' ? 'Editorial topic' : n.type === 'drug' ? 'Drug' : 'Disease';
      tooltip.append(small);
      tooltip.style.transform = `translate(${x}px, ${y}px) translate(-50%, calc(-100% - 14px))`;
      tooltip.hidden = false;
    },
    onSelect: (index) => {
      card?.show(index);
      hero.classList.toggle('has-card', index !== null);
      bus.emit('graph:select', { id: index === null ? null : graph.nodes[index].id });
    },
    onWheelHint: () => {
      if (Date.now() - hintShown < 6000) return;
      hintShown = Date.now();
      hint.textContent = /Mac|iPhone|iPad/.test(navigator.platform) ? 'Hold ⌘ and scroll to zoom the map' : 'Hold Ctrl and scroll to zoom the map';
      hint.hidden = false;
      window.clearTimeout(hintTimer);
      hintTimer = window.setTimeout(() => (hint.hidden = true), 2200);
    },
    onContextLost: () => o.onFatal(),
  });

  if (cardEl) card = new NodeCard(cardEl, graph, (index) => renderer.select(index));

  // Labels follow their nodes every frame.
  let raf = 0;
  const place = () => {
    raf = requestAnimationFrame(place);
    const origin = stage.getBoundingClientRect();
    const exploring = hero.classList.contains('is-exploring');
    // Keep the heading, intro and search legible: no labels inside the content block.
    const safe = exploring ? null : boxOf(inner, 14, origin);
    // Labels are either fully visible or hidden — never faded to an unreadable
    // grey. Search matches are placed first, then topics nearest the camera;
    // a label that would overlap one already placed is skipped.
    const placed: Box[] = [];
    const fits = (x: number, y: number, el: HTMLElement): Box | null => {
      const w = (el.offsetWidth || 80) / 2 + 4;
      const h = (el.offsetHeight || 24) / 2 + 3;
      const b = { l: x - w, t: y - h, r: x + w, b: y + h };
      if (b.l < 4 || b.r > origin.width - 4 || b.t < 4 || b.b > origin.height - 4) return null;
      if (safe && !(b.r < safe.l || b.l > safe.r || b.b < safe.t || b.t > safe.b)) return null;
      for (const p of placed) if (!(b.r < p.l || b.l > p.r || b.b < p.t || b.t > p.b)) return null;
      return b;
    };
    let target: { x: number; y: number } | null = null;
    for (const m of matchLabels) {
      const s = renderer.screenOf(m.index);
      // The connector follows the node itself, whether or not its label fits.
      if (s && !inside(safe, s.x, s.y)) target ??= s;
      // Try below, above, then beside the node before giving up on the label.
      let box: Box | null = null;
      let at = { x: 0, y: 0 };
      if (s) {
        const half = (m.el.offsetWidth || 80) / 2 + 12;
        for (const [dx, dy] of [[0, 20], [0, -20], [-half, 0], [half, 0]]) {
          box = fits(s.x + dx, s.y + dy, m.el);
          if (box) {
            at = { x: s.x + dx, y: s.y + dy };
            break;
          }
        }
      }
      if (!s || !box) {
        m.el.style.opacity = '0';
        continue;
      }
      placed.push(box);
      m.el.style.opacity = '1';
      m.el.style.transform = `translate(${at.x.toFixed(1)}px, ${at.y.toFixed(1)}px) translate(-50%, -50%)`;
    }
    const order = [...topicLabels.keys()].sort((a, b) => renderer.nearness(b) - renderer.nearness(a));
    for (const i of order) {
      const el = topicLabels.get(i)!;
      const s = renderer.screenOf(i);
      const box = s && renderer.nearness(i) > 0.12 ? fits(s.x, s.y - 18, el) : null;
      if (!s || !box) {
        el.style.opacity = '0';
        continue;
      }
      placed.push(box);
      el.style.opacity = '1';
      el.style.transform = `translate(${s.x.toFixed(1)}px, ${(s.y - 18).toFixed(1)}px) translate(-50%, -50%)`;
    }
    const f = exploring ? null : boxOf(field, 0, origin);
    if (target && f && !inside(f, target.x, target.y)) {
      const fromLeft = target.x < (f.l + f.r) / 2;
      const x0 = fromLeft ? f.l : f.r;
      const y0 = (f.t + f.b) / 2;
      const mx = (x0 + target.x) / 2;
      path.setAttribute('d', `M${x0.toFixed(1)} ${y0.toFixed(1)} C${mx.toFixed(1)} ${y0.toFixed(1)} ${mx.toFixed(1)} ${target.y.toFixed(1)} ${target.x.toFixed(1)} ${target.y.toFixed(1)}`);
      dot.setAttribute('cx', target.x.toFixed(1));
      dot.setAttribute('cy', target.y.toFixed(1));
      connector.classList.add('is-on');
    } else {
      connector.classList.remove('is-on');
    }
  };
  raf = requestAnimationFrame(place);

  const setMatchLabels = (indices: number[]) => {
    matchLabels.forEach((m) => m.el.remove());
    matchLabels = indices.slice(0, MAX_MATCH_LABELS).map((index) => {
      const el = document.createElement('div');
      el.className = 'ku-label ku-label--match';
      el.dataset.type = graph.nodes[index].type;
      el.textContent = graph.nodes[index].name;
      labels.append(el);
      return { index, el };
    });
  };

  const toIndices = (ids: string[]) => ids.map((id) => graph.byId.get(id)).filter((i): i is number => i !== undefined);
  let searchTimer = 0;
  const offs = [
    bus.on('search:results', ({ ids }) => {
      const indices = toIndices(ids);
      setMatchLabels(indices);
      window.clearTimeout(searchTimer);
      // Highlight immediately; move the camera only once typing settles.
      searchTimer = window.setTimeout(() => renderer.setMatches(indices), 320);
    }),
    bus.on('search:clear', () => {
      window.clearTimeout(searchTimer);
      setMatchLabels([]);
      renderer.setMatches([]);
    }),
    bus.on('search:active', ({ id }) => renderer.setFocus(id ? (graph.byId.get(id) ?? null) : null)),
    bus.on('chip:hover', ({ id }) => renderer.setFocus(id ? (graph.byId.get(id) ?? null) : null)),
    bus.on('map:show', ({ id }) => {
      const index = graph.byId.get(id);
      if (index !== undefined) renderer.select(index);
    }),
  ];

  // HUD controls
  const exploreBtn = hud?.querySelector<HTMLButtonElement>('[data-ku-action="explore"]');
  const motionBtn = hud?.querySelector<HTMLButtonElement>('[data-ku-action="motion"]');
  const setExploring = (on: boolean) => {
    hero.classList.toggle('is-exploring', on);
    renderer.setExploring(on);
    const label = exploreBtn?.querySelector<HTMLElement>('[data-ku-label-on]');
    if (label) label.textContent = (on ? label.dataset.kuLabelOn : label.dataset.kuLabelOff) ?? '';
  };
  const syncMotion = () => motionBtn?.setAttribute('aria-pressed', String(!renderer.rotating));
  syncMotion();
  hud?.addEventListener('click', (e) => {
    const btn = (e.target as Element).closest<HTMLButtonElement>('[data-ku-action]');
    if (!btn) return;
    switch (btn.dataset.kuAction) {
      case 'zoom-in':
        renderer.zoom(0.75);
        break;
      case 'zoom-out':
        renderer.zoom(1.33);
        break;
      case 'reset':
        renderer.reset();
        break;
      case 'motion':
        renderer.setAutoRotate(!renderer.rotating);
        syncMotion();
        break;
      case 'explore':
        setExploring(!hero.classList.contains('is-exploring'));
        break;
    }
  });
  const onKey = (e: KeyboardEvent) => {
    if (e.key !== 'Escape') return;
    if (hero.classList.contains('is-exploring')) setExploring(false);
    else if (card?.open && !(document.activeElement instanceof HTMLInputElement)) {
      renderer.select(null);
    }
  };
  document.addEventListener('keydown', onKey);
  // Returning to the search closes the details card it might overlap.
  const onFocusIn = () => {
    if (card?.open) renderer.select(null);
  };
  inner?.addEventListener('focusin', onFocusIn);

  if (hud) hud.hidden = false;
  stage.classList.add('is-ready');
  bus.emit('graph:ready', { mode: 'webgl' });

  return () => {
    cancelAnimationFrame(raf);
    offs.forEach((off) => off());
    document.removeEventListener('keydown', onKey);
    inner?.removeEventListener('focusin', onFocusIn);
    hero.classList.remove('has-card');
    renderer.dispose();
    labels.remove();
    hint.remove();
    card?.show(null);
    if (hud) hud.hidden = true;
    hero.classList.remove('is-exploring');
  };
}
