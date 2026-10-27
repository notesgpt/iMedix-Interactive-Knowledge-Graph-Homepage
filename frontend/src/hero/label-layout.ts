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
  /** partner[i] = an earlier label related to label i: placed close to it, with a clear line between them */
  partner?: (number | null)[];
  gap?: number;
  margin?: number;
  seed?: number;
}

export const LABEL_TYPES = ['drug', 'disease'] as const;

/** How many floating labels each screen size gets. */
export function labelCount(width: number): number {
  return width < 700 ? 4 : width < 1100 ? 6 : 8;
}

/** Short, recognisable names read best as floating labels (no pack sizes, no codes, no "Generic …"). */
export function readableName(name: string): boolean {
  return name.length <= 18 && !/[\d()]/.test(name) && !/^generic\b/i.test(name);
}

function rank(g: KGraph, i: number): number {
  const n = g.nodes[i];
  // Degree (tracker groupings + article links) is the only importance signal; capped below the status bonus.
  return (readableName(n.name) ? 4 : 0) + (n.status === 'completed' ? 2 : 0) + Math.min(n.degree, 19) * 0.1;
}

/**
 * A real relationship between two guides: the medicine (or its substance) is
 * FDA-approved for a condition covered by the disease guide, as signed in the
 * iMedix knowledge graph (`kg` pairs, from FDA labels — not a complete list).
 * Article links are navigation, not claims, and never draw a line.
 */
export function related(g: KGraph, a: number, b: number): boolean {
  return g.incident[a].some((k) => {
    const e = g.edges[k];
    return e.type === 'kg' && (e.source === b || e.target === b);
  });
}

/**
 * Hero labels. First, disease–drug pairs joined by a real relationship (one
 * pair per category, best first) — the only labels that get a connecting line.
 * The remaining slots take single guides from categories not shown yet.
 * Unregistered products are never featured (they stay searchable).
 */
export function pickLabels(g: KGraph, count: number): number[] {
  const eligible = (i: number) => {
    const n = g.nodes[i];
    return (n.type === 'drug' || n.type === 'disease') && !!n.url && n.registered;
  };
  const featured = (i: number) => eligible(i) && readableName(g.nodes[i].name);
  // A related pair explains itself, so its labels may carry a longer name ("High Blood Pressure (Hypertension)").
  const pairable = (i: number) => eligible(i) && g.nodes[i].name.length <= 36;
  const pairs: { d: number; m: number; cat: string; score: number; key: number }[] = [];
  for (const e of g.edges) {
    if (e.type !== 'kg' || !pairable(e.source) || !pairable(e.target)) continue;
    const [a, b] = [g.nodes[e.source], g.nodes[e.target]];
    if (a.type === b.type) continue;
    const [d, m] = a.type === 'disease' ? [a, b] : [b, a];
    pairs.push({ d: d.index, m: m.index, cat: d.category, score: rank(g, d.index) + rank(g, m.index), key: hash32(d.id + m.id) });
  }
  pairs.sort((x, y) => y.score - x.score || x.key - y.key);

  const out: number[] = [];
  const cats = new Set<string>();
  for (const p of pairs) {
    if (out.length + 2 > count) break;
    if (cats.has(p.cat) || out.includes(p.d) || out.includes(p.m)) continue;
    out.push(p.d, p.m);
    cats.add(p.cat);
  }
  for (const i of pickSingles(g, count, featured, cats, out)) if (out.length < count) out.push(i);
  return out;
}

/** Single guides, alternating type, one per category before any repeats. */
function pickSingles(g: KGraph, count: number, featured: (i: number) => boolean, shown: Set<string>, taken: number[]): number[] {
  const lists = LABEL_TYPES.map((type) => {
    const sorted = g.nodes
      .filter((n) => n.type === type && featured(n.index) && !taken.includes(n.index))
      .sort((a, b) => rank(g, b.index) - rank(g, a.index) || hash32(a.id) - hash32(b.id));
    const seen = new Set<string>();
    const first = sorted.filter((n) => !seen.has(n.category) && seen.add(n.category));
    const rest = sorted.filter((n) => !first.includes(n));
    return [...first, ...rest].map((n) => n.index);
  });
  const out: number[] = [];
  const used = new Set<string>(shown);
  count -= taken.length;
  const take = (list: number[]) => {
    // Across both types, prefer a category not shown yet.
    const i = list.find((x) => !out.includes(x) && !used.has(g.nodes[x].category)) ?? list.find((x) => !out.includes(x));
    if (i === undefined) return;
    out.push(i);
    used.add(g.nodes[i].category);
  };
  while (out.length < count && out.length < lists[0].length + lists[1].length) {
    // Diseases first: they read as the "health" half of the platform's title.
    for (const list of [lists[1], lists[0]]) if (out.length < count) take(list);
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
  const at: (Rect | null)[] = [];
  return sizes.map((size, i) => {
    let best: { p: Point; rect: Rect; score: number } | null = null;
    const mate = input.partner?.[i] ?? null;
    const mateRect = mate !== null ? at[mate] : null;
    const tries = i < anchored || mateRect ? 220 : 90;
    for (let k = 0; k < tries; k++) {
      const x = margin + rand() * Math.max(0, width - size.w - 2 * margin);
      const y = margin + rand() * Math.max(0, height - size.h - 2 * margin);
      const rect = { l: x, t: y, r: x + size.w, b: y + size.h };
      if (overlaps(rect, safe, gap) || avoid.some((a) => overlaps(rect, a, gap)) || placed.some((p) => overlaps(rect, p, gap))) continue;
      const c = { x: x + size.w / 2, y: y + size.h / 2 };
      let score: number;
      if (mateRect && i >= anchored) {
        // Beside its related guide, with nothing between them, at a readable distance.
        const m = { x: (mateRect.l + mateRect.r) / 2, y: (mateRect.t + mateRect.b) / 2 };
        const blocked = [safe, ...avoid, ...placed.filter((p) => p !== mateRect)].some((r) => segmentHitsRect(m.x, m.y, c.x, c.y, r));
        score = (blocked ? -1000 : 0) - Math.abs(Math.hypot(c.x - m.x, c.y - m.y) - 190);
      } else if (i < anchored) {
        score = -Math.hypot(c.x - input.anchor!.x, c.y - input.anchor!.y);
      } else {
        let spread = 320;
        for (const q of centres) spread = Math.min(spread, Math.hypot(q.x - c.x, (q.y - c.y) * 1.6));
        // Spread out, but stay in orbit around the text rather than hugging the edges.
        score = spread - distToRect(c, safe) * 0.25;
      }
      if (!best || score > best.score) best = { p: { x, y }, rect, score };
    }
    if (!best) {
      at.push(null);
      return null;
    }
    placed.push(best.rect);
    at.push(best.rect);
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
  /** line segments [x1, y1, x2, y2], one per real relationship */
  edges: [number, number, number, number][];
}

export interface NetworkInput {
  width: number;
  height: number;
  safe: Rect;
  avoid: Rect[];
  /** placed labels in display order (null = not shown) */
  labels: (Rect | null)[];
  /** pairs of label positions joined by a real relationship */
  links: [number, number][];
  /** number of out-of-focus depth dots */
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
 * The hero network is data, not ornament: a line joins two labels only when
 * the guides are really related (see `related`), and only when it can be drawn
 * without crossing the text column, the statistics or another label. A few
 * out-of-focus dots give depth; they carry no lines.
 */
export function buildNetwork(input: NetworkInput): Network {
  const { width, height, safe, avoid, labels, links, count } = input;
  const rand = mulberry32(input.seed ?? 23);
  const blocked = [safe, ...avoid];
  const nodes: NetNode[] = [];
  for (let k = 0, made = 0; k < 400 && made < count; k++) {
    const x = rand() * width;
    const y = rand() * height;
    if ([...blocked, ...labels.filter((r): r is Rect => r !== null)].some((r) => x > r.l - 30 && x < r.r + 30 && y > r.t - 30 && y < r.b + 30)) continue;
    nodes.push({ x, y, r: 7 + rand() * 9, tone: rand() < 0.65 ? 'drug' : 'disease', blur: true });
    made++;
  }

  const edges: Network['edges'] = [];
  const centre = (r: Rect) => [(r.l + r.r) / 2, (r.t + r.b) / 2] as const;
  for (const [a, b] of links) {
    const ra = labels[a];
    const rb = labels[b];
    if (!ra || !rb) continue;
    const [x1, y1] = centre(ra);
    const [x2, y2] = centre(rb);
    const others = labels.filter((r, i): r is Rect => r !== null && i !== a && i !== b);
    if ([...blocked, ...others].some((r) => segmentHitsRect(x1, y1, x2, y2, r))) continue;
    edges.push([x1, y1, x2, y2]);
  }
  return { nodes, edges };
}
