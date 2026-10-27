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
