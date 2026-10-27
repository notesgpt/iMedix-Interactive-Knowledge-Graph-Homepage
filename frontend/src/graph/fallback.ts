/**
 * CSS sphere fallback (no WebGL, Save-Data, or a lost GPU context): the same
 * curated entities and composition as the 3D scene, drawn as shaded CSS orbs.
 * Decorative only — search and the crawlable library provide the navigation.
 */
import type { Bus } from '../bus';
import type { KGraph } from '../types';
import { colourFor, readPalette } from './palette';
import { halfHeightAt, layoutSpheres, pickSpheres, type SafeBox, type View } from './sphere-layout';

export function mountFallback(stage: HTMLElement, graph: KGraph, count: number, safe: () => SafeBox, avoid: () => SafeBox[], bus: Bus): () => void {
  stage.querySelector('.ku-orbs')?.remove();
  const palette = readPalette(stage);
  const items = pickSpheres(graph, Math.min(count, 28));
  const layer = document.createElement('div');
  layer.className = 'ku-orbs';
  layer.setAttribute('aria-hidden', 'true');
  const orbs = items.map((it) => {
    const el = document.createElement('span');
    el.className = `ku-orb ku-orb--${it.type}`;
    el.style.setProperty('--orb', colourFor(palette, it.type, it.variant));
    layer.append(el);
    return el;
  });
  stage.append(layer);

  const draw = () => {
    const w = Math.max(1, stage.clientWidth);
    const h = Math.max(1, stage.clientHeight);
    const view: View = { aspect: w / h, fovDeg: 38, camDist: 120 };
    const placements = layoutSpheres(items, view, safe(), avoid());
    placements.forEach((p, k) => {
      const d = (items[k].radius / halfHeightAt(p.z, view)) * h;
      const el = orbs[k];
      el.style.width = el.style.height = `${d.toFixed(1)}px`;
      el.style.left = `${(((p.nx + 1) / 2) * w - d / 2).toFixed(1)}px`;
      el.style.top = `${(((1 - p.ny) / 2) * h - d / 2).toFixed(1)}px`;
      el.style.zIndex = String(Math.round(p.z + 100));
      el.style.opacity = p.z < -40 ? '0.75' : '1';
    });
  };
  draw();
  const ro = new ResizeObserver(draw);
  ro.observe(stage);
  stage.classList.add('is-ready');

  const highlight = (ids: string[]) => {
    const set = new Set(ids.map((id) => graph.byId.get(id)));
    items.forEach((it, k) => orbs[k].classList.toggle('is-dim', set.size > 0 && !set.has(it.index)));
  };
  const offs = [bus.on('search:results', ({ ids }) => highlight(ids)), bus.on('search:clear', () => highlight([]))];
  bus.emit('graph:ready', { mode: 'fallback' });
  return () => {
    offs.forEach((off) => off());
    ro.disconnect();
    layer.remove();
  };
}
