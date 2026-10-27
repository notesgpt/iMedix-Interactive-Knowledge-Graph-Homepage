import { describe, expect, it } from 'vitest';
import { buildNetwork, labelCount, layoutLabels, pickLabels, readableName, related, type Rect } from './label-layout';
import { realGraph } from '../test-fixture';

const hit = (a: Rect, b: Rect) => a.l < b.r && b.l < a.r && a.t < b.b && b.t < a.b;

describe('pickLabels', () => {
  const g = realGraph();
  it('returns real, linkable drugs and diseases only — never topics', () => {
    const picked = pickLabels(g, 14);
    expect(picked).toHaveLength(14);
    expect(new Set(picked).size).toBe(14);
    for (const i of picked) {
      expect(['drug', 'disease']).toContain(g.nodes[i].type);
      expect(g.nodes[i].url).toMatch(/^https:\/\/www\.imedix\.com\//);
    }
    const types = picked.map((i) => g.nodes[i].type);
    expect(types.filter((t) => t === 'drug').length).toBe(7);
  });
  it('prefers short, recognisable names (no pack sizes or codes)', () => {
    expect(readableName('Asthma')).toBe(true);
    expect(readableName('Novolin 70 (30)')).toBe(false);
    expect(readableName('Gastroesophageal Reflux Disease (GERD)')).toBe(false);
    expect(pickLabels(g, 10).every((i) => readableName(g.nodes[i].name))).toBe(true);
  });
  it('pairs only really related guides, one pair per category', () => {
    const picked = pickLabels(g, 8);
    for (let k = 0; k + 1 < picked.length; k += 2) {
      if (!related(g, picked[k], picked[k + 1])) break;
      expect(g.nodes[picked[k]].type).toBe('disease');
      expect(g.nodes[picked[k + 1]].type).toBe('drug');
    }
  });
  it('never features unregistered products', () => {
    const copy = { ...g, nodes: g.nodes.map((n) => ({ ...n, registered: n.type !== 'drug' })) };
    expect(pickLabels(copy, 8).every((i) => copy.nodes[i].type !== 'drug')).toBe(true);
  });
  it('shows one label per category before repeating any', () => {
    const picked = pickLabels(g, 8);
    const cats = picked.map((i) => g.nodes[i].category);
    expect(new Set(cats).size).toBe(cats.length);
  });
  it('is deterministic and scales with the screen', () => {
    expect(pickLabels(g, 10)).toEqual(pickLabels(g, 10));
    expect(labelCount(390)).toBeLessThan(labelCount(820));
    expect(labelCount(820)).toBeLessThan(labelCount(1440));
  });
});

describe('layoutLabels', () => {
  const safe: Rect = { l: 340, t: 140, r: 1100, b: 720 };
  const legend: Rect = { l: 40, t: 820, r: 460, b: 876 };
  const sizes = Array.from({ length: 14 }, (_, i) => ({ w: 120 + (i % 4) * 20, h: 40 }));
  const spots = layoutLabels({ width: 1440, height: 900, safe, avoid: [legend], sizes });
  const rects = spots.map((p, i) => (p ? { l: p.x, t: p.y, r: p.x + sizes[i].w, b: p.y + sizes[i].h } : null));

  it('places most labels on a desktop hero', () => {
    expect(rects.filter(Boolean).length).toBeGreaterThanOrEqual(12);
  });
  it('never covers the text column, the statistics or another label, and stays on screen', () => {
    const shown = rects.filter((r): r is Rect => r !== null);
    for (const [i, r] of shown.entries()) {
      expect(hit(r, safe)).toBe(false);
      expect(hit(r, legend)).toBe(false);
      expect(r.l).toBeGreaterThanOrEqual(0);
      expect(r.r).toBeLessThanOrEqual(1440);
      for (const q of shown.slice(i + 1)) expect(hit(r, q)).toBe(false);
    }
  });
  it('pulls anchored labels (search matches) towards the anchor', () => {
    const anchor = { x: 720, y: 560 };
    const [best] = layoutLabels({ width: 1440, height: 900, safe, avoid: [legend], sizes, anchor, anchored: 1 });
    const free = spots[0]!;
    const d = (p: { x: number; y: number }) => Math.hypot(p.x + 60 - anchor.x, p.y + 20 - anchor.y);
    expect(d(best!)).toBeLessThan(d(free));
    expect(d(best!)).toBeLessThan(500);
  });
  it('is deterministic', () => {
    expect(layoutLabels({ width: 1440, height: 900, safe, avoid: [legend], sizes })).toEqual(spots);
  });
});

describe('buildNetwork', () => {
  const safe: Rect = { l: 340, t: 140, r: 1100, b: 720 };
  const legend: Rect = { l: 40, t: 820, r: 460, b: 876 };
  const labels: (Rect | null)[] = [
    { l: 40, t: 60, r: 180, b: 116 },
    { l: 60, t: 300, r: 200, b: 356 },
    { l: 1200, t: 60, r: 1340, b: 116 },
    null,
    { l: 1180, t: 400, r: 1320, b: 456 },
  ];
  const links: [number, number][] = [
    [0, 1],
    [2, 4],
    [1, 4], // would cross the text column
    [1, 3], // label not shown
  ];
  const net = buildNetwork({ width: 1440, height: 900, safe, avoid: [legend], labels, links, count: 4 });

  it('draws a line only for real relationships that can be drawn cleanly', () => {
    expect(net.edges).toEqual([
      [110, 88, 130, 328],
      [1270, 88, 1250, 428],
    ]);
  });
  it('without relationships there are no lines at all', () => {
    expect(buildNetwork({ width: 1440, height: 900, safe, avoid: [legend], labels, links: [], count: 4 }).edges).toEqual([]);
  });
  it('places depth dots off the text and the labels', () => {
    for (const n of net.nodes) {
      expect(n.blur).toBe(true);
      expect(n.x > safe.l && n.x < safe.r && n.y > safe.t && n.y < safe.b).toBe(false);
    }
  });
  it('is deterministic', () => {
    expect(buildNetwork({ width: 1440, height: 900, safe, avoid: [legend], labels, links, count: 4 })).toEqual(net);
  });
});
