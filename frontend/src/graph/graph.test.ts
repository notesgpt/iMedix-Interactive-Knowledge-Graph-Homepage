import { describe, expect, it } from 'vitest';
import { halfHeightAt, layoutSpheres, matchSlots, pickSpheres, sphereCount, toWorld, type SafeBox, type View } from './sphere-layout';
import { neighbours } from '../data/graph';
import { realGraph, tinyGraph } from '../test-fixture';

describe('decodeGraph', () => {
  const g = tinyGraph();
  it('drops edges to unknown nodes and unsafe URLs', () => {
    expect(g.edges).toHaveLength(2);
    expect(g.nodes[g.byId.get('disease:evil')!].url).toBeNull();
    expect(g.nodes[g.byId.get('drug:metformin')!].url).toBe('https://www.imedix.com/drugs/metformin/');
  });
  it('builds adjacency', () => {
    const topic = g.byId.get('topic:diabetes')!;
    expect(neighbours(g, topic).length).toBe(2);
  });
});

describe('sphereCount', () => {
  it('shows fewer spheres on smaller screens and weaker GPUs', () => {
    expect(sphereCount(1440, 'high')).toBe(40);
    expect(sphereCount(820, 'high')).toBe(26);
    expect(sphereCount(390, 'high')).toBe(16);
    expect(sphereCount(1440, 'low')).toBe(26);
  });
});

describe('pickSpheres', () => {
  const g = realGraph();
  const items = pickSpheres(g, 40);
  it('returns the requested number of real, distinct entities', () => {
    expect(items).toHaveLength(40);
    expect(new Set(items.map((i) => i.index)).size).toBe(40);
    for (const it of items) expect(g.nodes[it.index].type).toBe(it.type);
  });
  it('mixes all three categories, with topics as the largest hubs', () => {
    const by = (t: string) => items.filter((i) => i.type === t);
    expect(by('topic').length).toBeGreaterThanOrEqual(4);
    expect(by('disease').length).toBeGreaterThan(0);
    expect(by('drug').length).toBeGreaterThan(0);
    const minTopic = Math.min(...by('topic').map((i) => i.radius));
    const avgOther = [...by('disease'), ...by('drug')].reduce((s, i) => s + i.radius, 0) / (items.length - by('topic').length);
    expect(minTopic).toBeGreaterThan(avgOther);
    expect(items.filter((i) => i.featured).every((i) => i.type === 'topic')).toBe(true);
  });
  it('is deterministic', () => {
    expect(pickSpheres(g, 26)).toEqual(pickSpheres(g, 26));
  });
});

describe('layoutSpheres', () => {
  const g = realGraph();
  const items = pickSpheres(g, 40);
  const view: View = { aspect: 1.6, fovDeg: 38, camDist: 120 };
  const safe: SafeBox = { l: -0.5, r: 0.5, t: 0.55, b: -0.45 };
  const hud: SafeBox = { l: -0.95, r: -0.4, t: -0.82, b: -0.97 };
  const placements = layoutSpheres(items, view, safe, [hud]);

  it('places every sphere at a finite position', () => {
    expect(placements).toHaveLength(items.length);
    for (const p of placements) for (const v of [p.nx, p.ny, p.z]) expect(Number.isFinite(v)).toBe(true);
  });
  it('keeps the text column and the HUD clear', () => {
    placements.forEach((p, k) => {
      const r = items[k].radius / halfHeightAt(p.z, view);
      for (const box of [safe, hud]) {
        const dx = Math.max(box.l * view.aspect - p.nx * view.aspect, 0, p.nx * view.aspect - box.r * view.aspect);
        const dy = Math.max(box.b - p.ny, 0, p.ny - box.t);
        expect(Math.hypot(dx, dy)).toBeGreaterThan(r);
      }
    });
  });
  it('uses several depth layers', () => {
    const zs = placements.map((p) => p.z);
    expect(Math.max(...zs) - Math.min(...zs)).toBeGreaterThan(40);
  });
  it('is deterministic', () => {
    expect(layoutSpheres(items, view, safe, [hud])).toEqual(placements);
  });
  it('maps placements into the visible frustum', () => {
    const w = toWorld(placements[0], view);
    expect(Math.abs(w.y)).toBeLessThanOrEqual(halfHeightAt(w.z, view) * 1.05);
  });
});

describe('matchSlots', () => {
  const safe: SafeBox = { l: -0.5, r: 0.5, t: 0.55, b: -0.45 };
  it('puts matches beside the search block on wide screens', () => {
    const slots = matchSlots(safe, { aspect: 1.6, fovDeg: 38, camDist: 120 }, 2);
    expect(slots).toHaveLength(2);
    expect(slots[0].nx).toBeGreaterThan(safe.r);
    expect(slots[1].nx).toBeLessThan(safe.l);
  });
  it('puts matches above or below the text on portrait screens', () => {
    const portrait: SafeBox = { l: -0.95, r: 0.95, t: 0.6, b: -0.5 };
    for (const s of matchSlots(portrait, { aspect: 0.46, fovDeg: 38, camDist: 120 }, 6)) {
      expect(s.ny > portrait.t || s.ny < portrait.b).toBe(true);
    }
  });
});
