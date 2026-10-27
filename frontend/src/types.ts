/** Client graph as published by the WordPress sync (Core\ClientPayload::from_graph). */
export interface ClientGraph {
  schema: string;
  generatedAt: string;
  publication: 'wordpress' | 'unverified';
  nodeTypes: Record<string, { label: string; plural: string }>;
  edgeTypes: Record<string, { label: string; clinical: boolean; provenance: string }>;
  counts: Record<string, number>;
  nodes: ClientNode[];
  edges: [string, string, string][];
}

export interface ClientNode {
  id: string;
  /** type: drug | disease | topic (extensible) */
  t: string;
  /** display name */
  n: string;
  /** visual category (primary topic key or type) */
  c: string;
  /** relationship count */
  d: number;
  /** site-relative path; absent for topics */
  p?: string;
  /** editorial status */
  s?: string;
  /** editorial update date (YYYY-MM-DD) */
  u?: string;
  /** search aliases (retired/redirected names) */
  a?: string[];
  /** optional description */
  x?: string;
}

export interface KNode {
  index: number;
  id: string;
  type: string;
  name: string;
  category: string;
  degree: number;
  url: string | null;
  status: string | null;
  aliases: string[];
  description: string | null;
}

export interface KEdge {
  source: number;
  target: number;
  type: string;
}

export interface KGraph {
  nodes: KNode[];
  edges: KEdge[];
  byId: Map<string, number>;
  /** adjacency: node index → edge indices */
  incident: number[][];
  edgeTypes: ClientGraph['edgeTypes'];
  nodeTypes: ClientGraph['nodeTypes'];
  counts: Record<string, number>;
}

export interface KUConfig {
  graphUrl: string;
  graphRestUrl: string;
  graphVersion: string;
  newsUrl: string;
  newsCount: number;
  newsRefresh: number;
  searchUrl: string;
  siteOrigin: string;
  budget: number;
  locale: string;
}
