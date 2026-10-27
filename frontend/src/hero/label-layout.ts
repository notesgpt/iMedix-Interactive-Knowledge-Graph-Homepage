/**
 * Composition of the floating guide labels around the hero text (pure,
 * deterministic, unit-tested).
 *
 *  - pickLabels: a curated set of real drug and disease guides (completed
 *    guides and well-connected entities first, alternating types);
 *  - layoutLabels: best-candidate sampling in hero pixels. Labels never touch
 *    the text column, the statistics panel, the edges or each other; search
 *    matches are pulled towards the search field, the rest spread out to frame
 *    the headline.
 */
import type { KGraph } from '../types';
import { hash32, mulberry32 } from '../util/env';

export interface Rect {
  l: number;
  t: number;
  r: number;
  b: number;
}

export interface Size {
  w: number;
  h: number;
}

export interface Point {
  x: number;
  y: number;
}

export interface LayoutInput {
  width: number;
  height: number;
  /** text column (headline, search, chips) — always kept clear */
  safe: Rect;
  /** other UI to keep clear (statistics panel) */
  avoid: Rect[];
  sizes: Size[];
  /** the first `anchored` labels are placed as close to `anchor` as possible */
  anchor?: Point | null;
  anchored?: number;
  gap?: number;
  margin?: number;
  seed?: number;
}

export const LABEL_TYPES = ['drug', 'disease'] as const;

/** How many floating labels each screen size gets. */
export function labelCount(width: number): number {
  return width < 700 ? 5 : width < 1100 ? 8 : 10;
}

/** Short, recognisable names read best as floating labels (no pack sizes, no codes). */
export function readableName(name: string): boolean {
  return name.length <= 18 && !/[\d()]/.test(name);
}

function rank(g: KGraph, i: number): number {
  const n = g.nodes[i];
  return (readableName(n.name) ? 4 : 0) + (n.status === 'completed' ? 2 : 0) + Math.min(n.degree, 8) * 0.1;
}

/** Real, linkable drug and disease guides, alternating type, best first. */
export function pickLabels(g: KGraph, count: number): number[] {
  const lists = LABEL_TYPES.map((type) =>
    g.nodes
      .filter((n) => n.type === type && n.url)
      .sort((a, b) => rank(g, b.index) - rank(g, a.index) || hash32(a.id) - hash32(b.id))
      .map((n) => n.index),
  );
  const out: number[] = [];
  for (let k = 0; out.length < count && k < Math.max(...lists.map((l) => l.length)); k++) {
    // Diseases first: they read as the "health" half of the platform's title.
    for (const list of [lists[1], lists[0]]) if (list[k] !== undefined && out.length < count) out.push(list[k]);
  }
  return out;
}

const overlaps = (a: Rect, b: Rect, gap: number) => a.l < b.r + gap && b.l < a.r + gap && a.t < b.b + gap && b.t < a.b + gap;

function distToRect(p: Point, r: Rect): number {
  const dx = Math.max(r.l - p.x, 0, p.x - r.r);
  const dy = Math.max(r.t - p.y, 0, p.y - r.b);
  return Math.hypot(dx, dy);
}

/** Top-left position per label, or null when it does not fit anywhere. */
export function layoutLabels(input: LayoutInput): (Point | null)[] {
  const { width, height, safe, avoid, sizes } = input;
  const gap = input.gap ?? 14;
  const margin = input.margin ?? 12;
  const anchored = input.anchor ? (input.anchored ?? 0) : 0;
  const rand = mulberry32(input.seed ?? 11);
  const placed: Rect[] = [];
  const centres: Point[] = [];
  return sizes.map((size, i) => {
    let best: { p: Point; rect: Rect; score: number } | null = null;
    const tries = i < anchored ? 220 : 90;
    for (let k = 0; k < tries; k++) {
      const x = margin + rand() * Math.max(0, width - size.w - 2 * margin);
      const y = margin + rand() * Math.max(0, height - size.h - 2 * margin);
      const rect = { l: x, t: y, r: x + size.w, b: y + size.h };
      if (overlaps(rect, safe, gap) || avoid.some((a) => overlaps(rect, a, gap)) || placed.some((p) => overlaps(rect, p, gap))) continue;
      const c = { x: x + size.w / 2, y: y + size.h / 2 };
      let score: number;
      if (i < anchored) {
        score = -Math.hypot(c.x - input.anchor!.x, c.y - input.anchor!.y);
      } else {
        let spread = 320;
        for (const q of centres) spread = Math.min(spread, Math.hypot(q.x - c.x, (q.y - c.y) * 1.6));
        // Spread out, but stay in orbit around the text rather than hugging the edges.
        score = spread - distToRect(c, safe) * 0.25;
      }
      if (!best || score > best.score) best = { p: { x, y }, rect, score };
    }
    if (!best) return null;
    placed.push(best.rect);
    centres.push({ x: (best.rect.l + best.rect.r) / 2, y: (best.rect.t + best.rect.b) / 2 });
    return best.p;
  });
}

/* ------------------------------------------------------------- network */

export interface NetNode {
  x: number;
  y: number;
  r: number;
  /** colour family of the dot: brand blue, disease coral or neutral steel */
  tone: 'drug' | 'disease' | 'steel';
  /** out-of-focus depth dot (drawn blurred, no edges) */
  blur: boolean;
}

export interface Network {
  nodes: NetNode[];
  /** line segments [x1, y1, x2, y2] */
  edges: [number, number, number, number][];
}

export interface NetworkInput {
  width: number;
  height: number;
  safe: Rect;
  avoid: Rect[];
  /** placed labels (their centres are network endpoints) */
  labels: Rect[];
  /** number of junction dots (blurred depth dots are added on top) */
  count: number;
  seed?: number;
}

/** Does segment p→q cross rectangle r (Liang–Barsky clip)? */
export function segmentHitsRect(x1: number, y1: number, x2: number, y2: number, r: Rect): boolean {
  let t0 = 0;
  let t1 = 1;
  const dx = x2 - x1;
  const dy = y2 - y1;
  for (const [p, q] of [
    [-dx, x1 - r.l],
    [dx, r.r - x1],
    [-dy, y1 - r.t],
    [dy, r.b - y1],
  ]) {
    if (p === 0) {
      if (q < 0) return false;
    } else {
      const t = q / p;
      if (p < 0) t0 = Math.max(t0, t);
      else t1 = Math.min(t1, t);
      if (t0 > t1) return false;
    }
  }
  return true;
}

/**
 * Decorative network behind the labels, as in a molecule/graph illustration:
 * small junction dots in the free space, each label tied to its nearest
 * junctions, junctions tied to their nearest neighbours, plus a few large
 * blurred dots for depth. It is ornament, not data: lines never join two
 * labels directly (that would read as a medical relationship) and never cross
 * the text column, the statistics or a label.
 */
export function buildNetwork(input: NetworkInput): Network {
  const { width, height, safe, avoid, labels, count } = input;
  const rand = mulberry32(input.seed ?? 23);
  const blocked = [safe, ...avoid];
  const free = (x: number, y: number, pad: number) =>
    x > pad && y > pad && x < width - pad && y < height - pad &&
    ![...blocked, ...labels].some((r) => x > r.l - pad && x < r.r + pad && y > r.t - pad && y < r.b + pad);

  const nodes: NetNode[] = [];
  const tones: NetNode['tone'][] = ['drug', 'steel', 'disease', 'steel', 'drug'];
  for (let k = 0; k < count * 40 && nodes.filter((n) => !n.blur).length < count; k++) {
    const x = rand() * width;
    const y = rand() * height;
    if (!free(x, y, 18)) continue;
    if (nodes.some((n) => Math.hypot(n.x - x, n.y - y) < 70)) continue;
    nodes.push({ x, y, r: 2.5 + rand() * 2.5, tone: tones[nodes.length % tones.length], blur: false });
  }
  // Out-of-focus dots: a shallow depth of field, never under the text.
  for (let k = 0, made = 0; k < 400 && made < Math.ceil(count / 2); k++) {
    const x = rand() * width;
    const y = rand() * height;
    if (blocked.some((r) => x > r.l - 30 && x < r.r + 30 && y > r.t - 30 && y < r.b + 30)) continue;
    nodes.push({ x, y, r: 7 + rand() * 9, tone: rand() < 0.65 ? 'drug' : 'disease', blur: true });
    made++;
  }

  const sharp = nodes.filter((n) => !n.blur);
  const edges: Network['edges'] = [];
  const seen = new Set<string>();
  const clear = (x1: number, y1: number, x2: number, y2: number, skip: Rect | null) =>
    !blocked.some((r) => segmentHitsRect(x1, y1, x2, y2, r)) &&
    !labels.some((r) => r !== skip && segmentHitsRect(x1, y1, x2, y2, r));
  const link = (x1: number, y1: number, x2: number, y2: number, key: string, skip: Rect | null) => {
    if (seen.has(key) || !clear(x1, y1, x2, y2, skip)) return false;
    seen.add(key);
    edges.push([x1, y1, x2, y2]);
    return true;
  };
  const byDistance = (x: number, y: number) =>
    sharp.map((n, i) => ({ n, i, d: Math.hypot(n.x - x, n.y - y) })).sort((a, b) => a.d - b.d);

  // Each label: one or two junctions within reach (line starts at its centre, hidden under it).
  labels.forEach((r, li) => {
    const cx = (r.l + r.r) / 2;
    const cy = (r.t + r.b) / 2;
    let made = 0;
    for (const { n, i, d } of byDistance(cx, cy)) {
      if (made >= 2 || d > 300) break;
      if (link(cx, cy, n.x, n.y, `L${li}-${i}`, r)) made++;
    }
    // Nothing in reach: give the label a junction of its own just beside it.
    for (let k = 0; !made && k < 48; k++) {
      const angle = (k / 16) * Math.PI * 2;
      const dist = 40 + Math.floor(k / 16) * 35 + Math.max(r.r - r.l, r.b - r.t) / 2;
      const x = cx + Math.cos(angle) * dist;
      const y = cy + Math.sin(angle) * dist * 0.7;
      if (!free(x, y, 10)) continue;
      const node: NetNode = { x, y, r: 3, tone: 'steel', blur: false };
      sharp.push(node);
      nodes.push(node);
      if (link(cx, cy, x, y, `L${li}-${sharp.length - 1}`, r)) made++;
      else {
        sharp.pop();
        nodes.pop();
      }
    }
  });
  // Junctions: two nearest neighbours each.
  sharp.forEach((a, i) => {
    let made = 0;
    for (const { n: b, i: j, d } of byDistance(a.x, a.y)) {
      if (j === i) continue;
      if (made >= 2 || d > 260) break;
      if (link(a.x, a.y, b.x, b.y, i < j ? `N${i}-${j}` : `N${j}-${i}`, null)) made++;
    }
  });
  return { nodes, edges };
}
