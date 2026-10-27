/**
 * Intelligent node sampling: the hero never renders the whole database.
 * Priority: editorial topics → diseases → drugs that belong to a topic →
 * other remediated (completed) drugs → remaining drugs. Ties break on a
 * stable hash, so every visitor sees the same constellation for a given sync.
 */
import type { KGraph } from '../types';
import { hash32 } from '../util/env';

export type Tier = 'high' | 'medium' | 'low' | 'none';

export function budgetFor(tier: Tier, desktopBudget: number): number {
  switch (tier) {
    case 'high':
      return desktopBudget;
    case 'medium':
      return Math.min(desktopBudget, 340);
    case 'low':
      return Math.min(desktopBudget, 180);
    default:
      return 0;
  }
}

function tierOf(g: KGraph, i: number): number {
  const n = g.nodes[i];
  if (n.type === 'topic') return 0;
  if (n.type === 'disease') return 1;
  const inTopic = g.incident[i].some((ei) => g.edges[ei].type === 'in_topic');
  if (inTopic) return 2;
  if (n.status === 'completed') return 3;
  return 4;
}

/** Node indices in priority order, truncated to the budget. */
export function sampleNodes(g: KGraph, budget: number): number[] {
  const order = g.nodes.map((n) => ({
    i: n.index,
    tier: tierOf(g, n.index),
    degree: n.degree,
    h: hash32(n.id),
  }));
  order.sort((a, b) => a.tier - b.tier || b.degree - a.degree || a.h - b.h);
  return order.slice(0, Math.max(0, budget)).map((o) => o.i);
}

/** Split into a fast first stage and a progressively revealed remainder. */
export function stages(order: number[], firstFraction = 0.45, chunk = 40): number[][] {
  if (!order.length) return [];
  const first = Math.max(1, Math.ceil(order.length * firstFraction));
  const out = [order.slice(0, first)];
  for (let i = first; i < order.length; i += chunk) out.push(order.slice(i, i + chunk));
  return out;
}
