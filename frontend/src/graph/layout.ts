/**
 * Deterministic constellation layout (no physics → no jank, identical for all visitors).
 * Editorial topics sit on an elliptical orbit facing the viewer, so the centre
 * stays clear for the heading and search (the "eye" of the constellation);
 * each topic's members cluster around it (diseases closer than drugs), with
 * depth variation for parallax; entities without a topic form a sparse field.
 */
import type { KGraph } from '../types';
import { hash32, mulberry32 } from '../util/env';

export interface LayoutOptions {
  /** horizontal and vertical radii of the topic orbit */
  orbitX: number;
  orbitY: number;
  fieldInner: number;
  fieldOuter: number;
  flatten: number;
}

const DEFAULTS: LayoutOptions = { orbitX: 92, orbitY: 50, fieldInner: 60, fieldOuter: 125, flatten: 0.6 };

function gaussian(rand: () => number): number {
  const u = Math.max(rand(), 1e-9);
  const v = rand();
  return Math.sqrt(-2 * Math.log(u)) * Math.cos(2 * Math.PI * v);
}

export function computeLayout(g: KGraph, options: Partial<LayoutOptions> = {}): Float32Array {
  const o = { ...DEFAULTS, ...options };
  const pos = new Float32Array(g.nodes.length * 3);

  const topics = g.nodes.filter((n) => n.type === 'topic').sort((a, b) => a.id.localeCompare(b.id));
  topics.forEach((t, k) => {
    const a = (k / Math.max(1, topics.length)) * Math.PI * 2 + 0.35;
    const wobble = 1 + 0.12 * Math.sin(a * 3);
    pos[t.index * 3] = Math.cos(a) * o.orbitX * wobble;
    pos[t.index * 3 + 1] = Math.sin(a) * o.orbitY * wobble;
    pos[t.index * 3 + 2] = Math.sin(a * 2 + 1) * 22;
  });

  for (const n of g.nodes) {
    if (n.type === 'topic') continue;
    const rand = mulberry32(hash32(n.id));
    const anchors = g.incident[n.index]
      .map((ei) => g.edges[ei])
      .filter((e) => e.type === 'in_topic')
      .map((e) => (e.source === n.index ? e.target : e.source));

    let x: number;
    let y: number;
    let z: number;
    if (anchors.length) {
      x = 0;
      y = 0;
      z = 0;
      for (const a of anchors) {
        x += pos[a * 3];
        y += pos[a * 3 + 1];
        z += pos[a * 3 + 2];
      }
      x /= anchors.length;
      y /= anchors.length;
      z /= anchors.length;
      if (anchors.length > 1) {
        // Bridge nodes sit between their topics, pushed back out onto the orbit.
        const r = Math.hypot(x / o.orbitX, y / o.orbitY) || 1;
        x /= r;
        y /= r;
      }
      const sigma = n.type === 'disease' ? 5.5 : 8.5;
      x += gaussian(rand) * sigma;
      y += gaussian(rand) * sigma * 0.8;
      z += gaussian(rand) * sigma;
    } else {
      const u = rand() * 2 - 1;
      const phi = rand() * Math.PI * 2;
      const s = Math.sqrt(1 - u * u);
      const radius = o.fieldInner + (o.fieldOuter - o.fieldInner) * Math.sqrt(rand());
      x = Math.cos(phi) * s * radius * 1.3;
      y = u * radius * o.flatten;
      z = Math.sin(phi) * s * radius * 0.7 - 20;
    }
    pos[n.index * 3] = x;
    pos[n.index * 3 + 1] = y;
    pos[n.index * 3 + 2] = z;
  }
  return pos;
}
