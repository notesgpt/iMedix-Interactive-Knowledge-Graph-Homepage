/**
 * Static 2D constellation (inline SVG) used when WebGL is unavailable, when
 * the user asked for reduced data, or after a lost GPU context. Decorative
 * only: search and the crawlable lists below provide the actual navigation.
 */
import type { Bus } from '../bus';
import type { KGraph } from '../types';
import { neighbours } from '../data/graph';
import { colourFor, readPalette } from './palette';

const NS = 'http://www.w3.org/2000/svg';

export function mountFallback(stage: HTMLElement, graph: KGraph, positions: Float32Array, order: number[], bus: Bus): () => void {
  stage.querySelector('svg')?.remove();
  const palette = readPalette(stage);
  const svg = document.createElementNS(NS, 'svg');
  svg.setAttribute('viewBox', '-120 -70 240 140');
  svg.setAttribute('preserveAspectRatio', 'xMidYMid slice');
  svg.setAttribute('aria-hidden', 'true');
  svg.setAttribute('focusable', 'false');
  svg.classList.add('ku-fallback');

  const shown = new Set(order);
  const edges = document.createElementNS(NS, 'g');
  edges.setAttribute('stroke', palette.edge);
  edges.setAttribute('stroke-width', '0.12');
  edges.setAttribute('opacity', '0.35');
  for (const e of graph.edges) {
    if (!shown.has(e.source) || !shown.has(e.target)) continue;
    const l = document.createElementNS(NS, 'line');
    l.setAttribute('x1', positions[e.source * 3].toFixed(1));
    l.setAttribute('y1', (-positions[e.source * 3 + 1]).toFixed(1));
    l.setAttribute('x2', positions[e.target * 3].toFixed(1));
    l.setAttribute('y2', (-positions[e.target * 3 + 1]).toFixed(1));
    edges.append(l);
  }
  svg.append(edges);

  const marks = new Map<number, SVGElement>();
  const nodes = document.createElementNS(NS, 'g');
  for (const i of order) {
    const n = graph.nodes[i];
    const x = positions[i * 3];
    const y = -positions[i * 3 + 1];
    const depth = 0.55 + 0.45 * Math.max(0, Math.min(1, (positions[i * 3 + 2] + 90) / 180));
    let el: SVGElement;
    if (n.type === 'topic') {
      el = document.createElementNS(NS, 'rect');
      el.setAttribute('x', (x - 1.1).toFixed(2));
      el.setAttribute('y', (y - 1.1).toFixed(2));
      el.setAttribute('width', '2.2');
      el.setAttribute('height', '2.2');
      el.setAttribute('transform', `rotate(45 ${x.toFixed(2)} ${y.toFixed(2)})`);
      el.setAttribute('fill', 'none');
      el.setAttribute('stroke', palette.topic);
      el.setAttribute('stroke-width', '0.35');
    } else {
      el = document.createElementNS(NS, 'circle');
      el.setAttribute('cx', x.toFixed(2));
      el.setAttribute('cy', y.toFixed(2));
      el.setAttribute('r', n.type === 'disease' ? '0.75' : '0.55');
      if (n.type === 'disease') {
        el.setAttribute('fill', 'none');
        el.setAttribute('stroke', palette.disease);
        el.setAttribute('stroke-width', '0.3');
      } else {
        el.setAttribute('fill', colourFor(palette, n.type));
      }
    }
    el.setAttribute('opacity', depth.toFixed(2));
    el.dataset.base = depth.toFixed(2);
    marks.set(i, el);
    nodes.append(el);
  }
  svg.append(nodes);
  stage.append(svg);
  stage.classList.add('is-ready');

  const highlight = (ids: string[]) => {
    const strong = new Set<number>();
    const soft = new Set<number>();
    for (const id of ids) {
      const i = graph.byId.get(id);
      if (i === undefined) continue;
      strong.add(i);
      neighbours(graph, i, 'in_topic').forEach((j) => soft.add(j));
    }
    marks.forEach((el, i) => {
      const base = el.dataset.base ?? '1';
      el.setAttribute('opacity', !strong.size ? base : strong.has(i) ? '1' : soft.has(i) ? '0.7' : '0.12');
    });
  };
  const offs = [
    bus.on('search:results', ({ ids }) => highlight(ids)),
    bus.on('search:clear', () => highlight([])),
  ];
  bus.emit('graph:ready', { mode: 'fallback' });
  return () => {
    offs.forEach((off) => off());
    svg.remove();
  };
}
