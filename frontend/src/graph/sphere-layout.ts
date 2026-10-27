/**
 * Composition of the knowledge spheres (pure, deterministic, unit-tested).
 *
 *  - pickSpheres: a curated visible subset of real entities (the full dataset stays
 *    searchable); topics are the large "hub" spheres, diseases and drugs fill in.
 *  - layoutSpheres: best-candidate sampling in screen space at several depths, so
 *    spheres frame the headline/search block instead of covering it.
 *  - matchSlots: where search matches float to — beside (or, on narrow screens,
 *    above and below) the search block.
 *
 * Coordinates: nx, ny in [-1, 1] across the stage (y up); z is depth towards the
 * camera (positive = nearer). World conversion depends on camera distance and FOV.
 */
import type { KGraph } from '../types';
import { hash32, mulberry32 } from '../util/env';

export interface SphereItem {
  index: number;
  type: string;
  radius: number;
  /** 0..2 material/colour variant inside the category family */
  variant: number;
  featured: boolean;
}

export interface Placement {
  nx: number;
  ny: number;
  z: number;
}

/** Normalised rectangle (l < r, b < t; y up). */
export interface SafeBox {
  l: number;
  r: number;
  t: number;
  b: number;
}

export interface View {
  aspect: number;
  fovDeg: number;
  camDist: number;
}

/** Visible sphere count per device class. */
export function sphereCount(width: number, tier: 'high' | 'medium' | 'low'): number {
  const base = width < 700 ? 16 : width < 1100 ? 26 : 40;
  return tier === 'low' ? Math.min(base, 26) : base;
}

function rank(g: KGraph, i: number): number {
  const n = g.nodes[i];
  const inTopic = g.incident[i].some((e) => g.edges[e].type === 'in_topic');
  return (n.status === 'completed' ? 2 : 0) + (inTopic ? 1 : 0) + Math.min(n.degree, 6) * 0.1;
}

export function pickSpheres(g: KGraph, count: number): SphereItem[] {
  const by = (type: string) =>
    g.nodes
      .filter((n) => n.type === type)
      .sort((a, b) => rank(g, b.index) - rank(g, a.index) || hash32(a.id) - hash32(b.id));
  const topics = by('topic');
  const nTopics = Math.min(topics.length, Math.max(4, Math.round(count * 0.18)));
  const nDiseases = Math.round((count - nTopics) * 0.52);
  const nDrugs = count - nTopics - nDiseases;

  const items: SphereItem[] = [];
  topics.slice(0, nTopics).forEach((n, k) => {
    items.push({
      index: n.index,
      type: 'topic',
      radius: 4.2 + Math.min(2.4, Math.sqrt(n.degree) * 0.32),
      variant: k % 3,
      featured: k < 4,
    });
  });
  for (const [type, list, take] of [
    ['disease', by('disease'), nDiseases],
    ['drug', by('drug'), nDrugs],
  ] as const) {
    list.slice(0, take).forEach((n) => {
      const rand = mulberry32(hash32(n.id));
      items.push({
        index: n.index,
        type,
        radius: (type === 'disease' ? 2.7 : 2.5) + (n.status === 'completed' ? 0.7 : 0) + rand() * 1.3,
        variant: Math.floor(rand() * 3),
        featured: false,
      });
    });
  }
  return items;
}

/** Half the visible height (world units) at depth z. */
export function halfHeightAt(z: number, view: View): number {
  return (view.camDist - z) * Math.tan((view.fovDeg * Math.PI) / 360);
}

export function toWorld(p: Placement, view: View): { x: number; y: number; z: number } {
  const hh = halfHeightAt(p.z, view);
  return { x: p.nx * hh * view.aspect, y: p.ny * hh, z: p.z };
}

/** Projected radius in normalised-height units (1 = half the stage height). */
function projRadius(radius: number, z: number, view: View): number {
  return radius / halfHeightAt(z, view);
}

/** Distance from a point to a rectangle in aspect-corrected space (0 inside). */
function distToBox(x: number, y: number, box: SafeBox, aspect: number): number {
  const dx = Math.max(box.l * aspect - x, 0, x - box.r * aspect);
  const dy = Math.max(box.b - y, 0, y - box.t);
  return Math.hypot(dx, dy);
}

export function layoutSpheres(items: SphereItem[], view: View, safe: SafeBox, avoid: SafeBox[] = [], seed = 7): Placement[] {
  const rand = mulberry32(seed);
  const placed: { x: number; y: number; r: number }[] = [];
  const out: Placement[] = new Array(items.length);
  const order = items.map((_, i) => i).sort((a, b) => items[b].radius - items[a].radius);
  const margin = 0.05;
  const portrait = view.aspect < 0.9;

  for (const i of order) {
    const item = items[i];
    let best: { p: Placement; score: number; x: number; y: number; r: number } | null = null;
    for (let k = 0; k < 60; k++) {
      // Depth layers: hubs mid/front, small spheres spread deeper for parallax.
      const z = item.type === 'topic' ? -24 + rand() * 34 : -95 + rand() * 105;
      const r = projRadius(item.radius, z, view);
      const nx = (rand() * 2 - 1) * (portrait ? 1.02 : 1.06);
      const ny = (rand() * 2 - 1) * 1.02;
      const x = nx * view.aspect;
      const y = ny;
      // Keep the text column clear (with breathing room), let edges crop a little.
      const clearance = distToBox(x, y, safe, view.aspect) - r - margin;
      if (clearance < 0) continue;
      // Small background spheres may be cropped by the edge; prominent ones stay whole.
      // inset: how far (in radii) the centre must stay inside the edge.
      const inset = item.radius > 4 ? 1.08 : 0.3;
      if (Math.abs(x) + r * inset > view.aspect || Math.abs(y) + r * inset > 1) continue;
      // HUD pieces (legend, map controls) never sit on top of a sphere.
      if (avoid.some((box) => distToBox(x, y, box, view.aspect) < r + 0.02)) continue;
      let gap = 1;
      for (const q of placed) gap = Math.min(gap, Math.hypot(q.x - x, q.y - y) - q.r - r);
      // Prefer spread-out positions and a slight pull towards the text block (framing).
      const score = Math.min(gap, 0.6) - clearance * 0.08;
      if (!best || score > best.score) best = { p: { nx, ny, z }, score, x, y, r };
    }
    if (!best) {
      // No room (very small screens): park it far back at the edge.
      const side = i % 2 ? 1 : -1;
      best = { p: { nx: side * 0.98, ny: (rand() * 2 - 1) * 0.95, z: -70 }, score: -1, x: 0, y: 0, r: 0 };
    }
    placed.push({ x: best.x, y: best.y, r: best.r });
    out[i] = best.p;
  }
  return out;
}

/**
 * Positions for search matches, closest to the search block first. Wide screens
 * use the left/right flanks; portrait screens use the bands above and below.
 */
export function matchSlots(safe: SafeBox, view: View, count: number): Placement[] {
  // Portrait screens have less room around the text: matches come forward less.
  const z = view.aspect >= 0.9 ? 14 : -4;
  const slots: Placement[] = [];
  const hh = halfHeightAt(z, view);
  const pad = 9 / hh; // ~ one large sphere radius
  if (view.aspect >= 0.9) {
    const xr = Math.min(0.92, safe.r + pad * 1.25 / view.aspect);
    const xl = Math.max(-0.92, safe.l - pad * 1.25 / view.aspect);
    const ym = (safe.t + safe.b) / 2 - 0.12;
    const dy = 0.42;
    for (const [nx, ny] of [
      [xr, ym],
      [xl, ym],
      [xr, ym + dy],
      [xl, ym + dy],
      [xr, ym - dy],
      [xl, ym - dy],
    ]) slots.push({ nx, ny, z });
  } else {
    const yt = Math.min(0.9, safe.t + pad * 1.1);
    const yb = Math.max(-0.9, safe.b - pad * 1.1);
    for (const [nx, ny] of [
      // Off-centre, so the label fits beside the sphere and nothing sits on the eyebrow.
      [-0.5, yt],
      [0.5, yt],
      [-0.5, yb],
      [0.5, yb],
      [0, yb],
      [0, yt],
    ]) slots.push({ nx, ny, z });
  }
  return slots.slice(0, count);
}
