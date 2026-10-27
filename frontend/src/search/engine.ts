/**
 * Client-side entity search over the synced graph (≈900 entities, so an
 * in-memory index answers in well under a millisecond and works offline from
 * the graph payload). Full-text article search stays with WordPress (?s=).
 */
import type { KGraph, KNode } from '../types';

export interface SearchHit {
  node: KNode;
  score: number;
  /** alias that matched instead of the display name, if any */
  via: string | null;
  /** true when the match needed typo tolerance */
  fuzzy: boolean;
}

interface Term {
  text: string;
  tokens: string[];
  alias: string | null;
}

interface Entry {
  node: KNode;
  terms: Term[];
}

export type TypeFilter = 'all' | string;

export function fold(text: string): string {
  return text
    .normalize('NFKD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/['’]/g, '')
    .replace(/[^a-z0-9]+/g, ' ')
    .trim();
}

/** Optimal-string-alignment Damerau–Levenshtein distance with an early cutoff. */
export function editDistance(a: string, b: string, max: number): number {
  if (Math.abs(a.length - b.length) > max) return max + 1;
  const prev2 = new Array<number>(b.length + 1).fill(0);
  let prev = Array.from({ length: b.length + 1 }, (_, j) => j);
  for (let i = 1; i <= a.length; i++) {
    const cur = [i];
    let rowMin = i;
    for (let j = 1; j <= b.length; j++) {
      const cost = a[i - 1] === b[j - 1] ? 0 : 1;
      let v = Math.min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + cost);
      if (i > 1 && j > 1 && a[i - 1] === b[j - 2] && a[i - 2] === b[j - 1]) {
        v = Math.min(v, prev2[j - 2] + 1);
      }
      cur[j] = v;
      if (v < rowMin) rowMin = v;
    }
    if (rowMin > max) return max + 1;
    for (let j = 0; j <= b.length; j++) prev2[j] = prev[j];
    prev = cur;
  }
  return prev[b.length];
}

function allowedTypos(len: number): number {
  if (len >= 8) return 2;
  if (len >= 4) return 1;
  return 0;
}

export class SearchIndex {
  private entries: Entry[];

  constructor(graph: KGraph, types: string[] = ['drug', 'disease']) {
    this.entries = graph.nodes
      .filter((n) => types.includes(n.type) && n.url)
      .map((node) => ({
        node,
        terms: [node.name, ...node.aliases].map((label, i) => {
          const text = fold(label);
          return { text, tokens: text.split(' ').filter(Boolean), alias: i === 0 ? null : label };
        }),
      }));
  }

  get size(): number {
    return this.entries.length;
  }

  search(query: string, filter: TypeFilter = 'all', limit = 8): SearchHit[] {
    const q = fold(query);
    if (!q) return [];
    const qTokens = q.split(' ').filter(Boolean);
    const hits: SearchHit[] = [];

    for (const entry of this.entries) {
      if (filter !== 'all' && entry.node.type !== filter) continue;
      let best: SearchHit | null = null;
      for (const term of entry.terms) {
        const scored = scoreTerm(q, qTokens, term);
        if (!scored) continue;
        let score = scored.score - (term.alias ? 40 : 0);
        if (entry.node.status === 'completed') score += 6;
        score += Math.min(entry.node.degree, 10);
        if (!best || score > best.score) {
          best = { node: entry.node, score, via: term.alias, fuzzy: scored.fuzzy };
        }
      }
      if (best) hits.push(best);
    }

    hits.sort((a, b) => b.score - a.score || a.node.name.length - b.node.name.length || a.node.name.localeCompare(b.node.name));
    return hits.slice(0, limit);
  }
}

function scoreTerm(q: string, qTokens: string[], term: Term): { score: number; fuzzy: boolean } | null {
  const t = term.text;
  if (t === q) return { score: 1000, fuzzy: false };
  if (t.startsWith(q)) return { score: 900 - Math.min(t.length - q.length, 80), fuzzy: false };

  // Every query token is a prefix of some word (word-order independent).
  let allPrefix = true;
  for (const qt of qTokens) {
    if (!term.tokens.some((tt) => tt.startsWith(qt))) {
      allPrefix = false;
      break;
    }
  }
  if (allPrefix) return { score: 700 - Math.min(t.length, 80), fuzzy: false };

  if (q.length >= 3 && t.includes(q)) return { score: 500 - Math.min(t.length, 80), fuzzy: false };

  // Typo tolerance: compare each query token with word prefixes of similar length.
  let total = 0;
  for (const qt of qTokens) {
    const max = allowedTypos(qt.length);
    if (max === 0) {
      if (!term.tokens.some((tt) => tt.startsWith(qt))) return null;
      continue;
    }
    let bestD = max + 1;
    for (const tt of term.tokens) {
      if (tt[0] !== qt[0] && max < 2) continue;
      // Compare against the whole word and against a same-length prefix (typing in progress).
      const d = Math.min(editDistance(qt, tt, max), editDistance(qt, tt.slice(0, qt.length), max));
      if (d < bestD) bestD = d;
      if (bestD === 1 && max === 1) break;
    }
    if (bestD > max) return null;
    total += bestD;
  }
  return { score: 300 - total * 60 - Math.min(t.length, 60), fuzzy: true };
}
