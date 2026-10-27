import type { ClientGraph, KGraph, KNode } from '../types';
import { safeSiteUrl } from '../util/url';

/** Decode the compact client payload into an indexed graph. */
export function decodeGraph(raw: ClientGraph, siteOrigin: string): KGraph {
  if (!raw || !Array.isArray(raw.nodes) || !Array.isArray(raw.edges)) {
    throw new Error('Invalid graph payload');
  }
  const nodes: KNode[] = [];
  const byId = new Map<string, number>();
  for (const n of raw.nodes) {
    if (!n || typeof n.id !== 'string' || typeof n.n !== 'string' || byId.has(n.id)) continue;
    const index = nodes.length;
    byId.set(n.id, index);
    nodes.push({
      index,
      id: n.id,
      type: String(n.t),
      name: n.n,
      category: String(n.c ?? n.t),
      degree: Number(n.d) || 0,
      url: n.p ? safeSiteUrl(n.p, siteOrigin) : null,
      status: n.s ?? null,
      aliases: Array.isArray(n.a) ? n.a.filter((a) => typeof a === 'string') : [],
      description: typeof n.x === 'string' ? n.x : null,
      registered: n.r !== 0,
      actives: typeof n.g === 'string' && n.g ? n.g : null,
    });
  }
  const incident: number[][] = nodes.map(() => []);
  const edges: KGraph['edges'] = [];
  for (const e of raw.edges) {
    const s = byId.get(e[0]);
    const t = byId.get(e[1]);
    if (s === undefined || t === undefined || s === t) continue;
    const i = edges.length;
    edges.push({ source: s, target: t, type: String(e[2]) });
    incident[s].push(i);
    incident[t].push(i);
  }
  return {
    nodes,
    edges,
    byId,
    incident,
    edgeTypes: raw.edgeTypes ?? {},
    nodeTypes: raw.nodeTypes ?? {},
    counts: raw.counts ?? {},
  };
}

/** Neighbour indices of a node, optionally filtered by edge type. */
export function neighbours(g: KGraph, index: number, type?: string): number[] {
  const out: number[] = [];
  for (const ei of g.incident[index] ?? []) {
    const e = g.edges[ei];
    if (type && e.type !== type) continue;
    out.push(e.source === index ? e.target : e.source);
  }
  return out;
}

const memory = new Map<string, Promise<KGraph>>();

/**
 * Load the graph once per page. Tries the versioned static file first and
 * falls back to the REST endpoint; caches in sessionStorage keyed by version.
 */
export function loadGraph(urls: string[], version: string, siteOrigin: string): Promise<KGraph> {
  const key = urls.join('|') + '#' + version;
  const cached = memory.get(key);
  if (cached) return cached;

  const storageKey = 'imedix-ku:graph:' + version;
  const promise = (async () => {
    if (version) {
      try {
        const stored = sessionStorage.getItem(storageKey);
        if (stored) return decodeGraph(JSON.parse(stored) as ClientGraph, siteOrigin);
      } catch {
        /* storage unavailable or corrupt: fetch */
      }
    }
    let lastError: unknown = null;
    for (const url of urls.filter(Boolean)) {
      try {
        const res = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const text = await res.text();
        const graph = decodeGraph(JSON.parse(text) as ClientGraph, siteOrigin);
        if (version) {
          try {
            sessionStorage.setItem(storageKey, text);
          } catch {
            /* quota: ignore */
          }
        }
        return graph;
      } catch (err) {
        lastError = err;
      }
    }
    throw lastError ?? new Error('No graph URL configured');
  })();
  memory.set(key, promise);
  promise.catch(() => memory.delete(key));
  return promise;
}
