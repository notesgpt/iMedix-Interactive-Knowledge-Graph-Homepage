import { describe, expect, it } from 'vitest';
import { budgetFor, sampleNodes, stages } from './sampling';
import { computeLayout } from './layout';
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

describe('sampling', () => {
  const g = realGraph();
  it('respects the budget per tier', () => {
    expect(sampleNodes(g, budgetFor('high', 640))).toHaveLength(640);
    expect(sampleNodes(g, budgetFor('medium', 640))).toHaveLength(340);
    expect(sampleNodes(g, budgetFor('low', 640))).toHaveLength(180);
    expect(budgetFor('none', 640)).toBe(0);
  });
  it('prioritises topics, then diseases', () => {
    const order = sampleNodes(g, 200);
    const types = order.map((i) => g.nodes[i].type);
    const topics = g.counts.topic;
    expect(types.slice(0, topics).every((t) => t === 'topic')).toBe(true);
    expect(types.slice(topics, topics + 96).every((t) => t === 'disease')).toBe(true);
  });
  it('is deterministic', () => {
    expect(sampleNodes(g, 300)).toEqual(sampleNodes(g, 300));
  });
  it('splits into progressive stages covering every node once', () => {
    const order = sampleNodes(g, 300);
    const s = stages(order);
    expect(s[0].length).toBeGreaterThan(100);
    expect(s.flat()).toEqual(order);
  });
});

describe('layout', () => {
  const g = realGraph();
  const pos = computeLayout(g);
  it('produces finite, bounded, deterministic coordinates', () => {
    expect(pos).toHaveLength(g.nodes.length * 3);
    for (const v of pos) {
      expect(Number.isFinite(v)).toBe(true);
      expect(Math.abs(v)).toBeLessThan(160);
    }
    expect(computeLayout(g)).toEqual(pos);
  });
  it('places topic members near their topic', () => {
    const t = g.byId.get('topic:blood-pressure')!;
    const m = g.byId.get('disease:hypertension')!;
    const d = Math.hypot(pos[t * 3] - pos[m * 3], pos[t * 3 + 1] - pos[m * 3 + 1], pos[t * 3 + 2] - pos[m * 3 + 2]);
    expect(d).toBeLessThan(30);
  });
});
