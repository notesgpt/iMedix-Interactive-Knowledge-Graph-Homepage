import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import type { ClientGraph } from './types';
import { decodeGraph } from './data/graph';

/** Client graph generated from the real tracker snapshot (npm run fixture). */
export function realGraph() {
  const file = resolve(process.cwd(), 'public/dev/graph.json'); // vitest runs from frontend/
  return decodeGraph(JSON.parse(readFileSync(file, 'utf8')) as ClientGraph, 'https://www.imedix.com');
}

export function tinyGraph() {
  const raw: ClientGraph = {
    schema: 'imedix-ku/client@1',
    generatedAt: '2026-10-27T00:00:00Z',
    publication: 'unverified',
    nodeTypes: {},
    edgeTypes: {},
    counts: { drug: 3, disease: 2, topic: 1 },
    nodes: [
      { id: 'drug:metformin', t: 'drug', n: 'Metformin', c: 'diabetes', d: 1, p: '/drugs/metformin/', s: 'completed' },
      { id: 'drug:lisinopril', t: 'drug', n: 'Lisinopril', c: 'drug', d: 0, p: '/drugs/lisinopril/', s: 'pending' },
      { id: 'drug:quinapril-hcl', t: 'drug', n: 'Quinapril HCl', c: 'drug', d: 0, p: '/drugs/quinapril-hcl/', s: 'completed', a: ['Accupril'] },
      { id: 'disease:diabetes-mellitus', t: 'disease', n: 'Diabetes Mellitus', c: 'diabetes', d: 1, p: '/diseases/diabetes-mellitus/' },
      { id: 'disease:evil', t: 'disease', n: 'Evil', c: 'disease', d: 0, p: 'javascript:alert(1)' },
      { id: 'topic:diabetes', t: 'topic', n: 'Diabetes', c: 'diabetes', d: 2 },
    ],
    edges: [
      ['drug:metformin', 'topic:diabetes', 'in_topic'],
      ['disease:diabetes-mellitus', 'topic:diabetes', 'in_topic'],
      ['drug:metformin', 'drug:missing', 'in_topic'],
    ],
  };
  return decodeGraph(raw, 'https://www.imedix.com');
}
