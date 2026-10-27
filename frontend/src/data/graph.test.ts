import { describe, expect, it } from 'vitest';
import { neighbours } from './graph';
import { tinyGraph } from '../test-fixture';

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
