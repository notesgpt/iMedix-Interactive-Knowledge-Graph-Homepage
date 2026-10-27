import { describe, expect, it } from 'vitest';
import { SearchIndex, editDistance, fold } from './engine';
import { realGraph, tinyGraph } from '../test-fixture';

describe('fold', () => {
  it('normalises case, accents and apostrophes', () => {
    expect(fold("Sjögren's  Syndrome")).toBe('sjogrens syndrome');
    expect(fold('H. pylori')).toBe('h pylori');
  });
});

describe('editDistance', () => {
  it('counts transpositions as one edit', () => {
    expect(editDistance('metfromin', 'metformin', 2)).toBe(1);
    expect(editDistance('asthma', 'astma', 2)).toBe(1);
    expect(editDistance('abc', 'xyz', 1)).toBeGreaterThan(1);
  });
});

describe('SearchIndex (tiny graph)', () => {
  const index = new SearchIndex(tinyGraph());

  it('excludes topics and entities without a safe URL', () => {
    expect(index.size).toBe(4);
    expect(index.search('evil')).toHaveLength(0);
    expect(index.search('diabetes').map((h) => h.node.type)).not.toContain('topic');
  });

  it('ranks exact and prefix matches first', () => {
    expect(index.search('metformin')[0].node.id).toBe('drug:metformin');
    expect(index.search('met')[0].node.id).toBe('drug:metformin');
  });

  it('tolerates typos', () => {
    const hit = index.search('metfromin')[0];
    expect(hit.node.id).toBe('drug:metformin');
    expect(hit.fuzzy).toBe(true);
    expect(index.search('lisinoprl')[0].node.id).toBe('drug:lisinopril');
  });

  it('finds entities through redirect aliases and reports the alias', () => {
    const hit = index.search('accupril')[0];
    expect(hit.node.id).toBe('drug:quinapril-hcl');
    expect(hit.via).toBe('Accupril');
  });

  it('filters by type', () => {
    expect(index.search('diabetes', 'drug')).toHaveLength(0);
    expect(index.search('diabetes', 'disease')[0].node.id).toBe('disease:diabetes-mellitus');
  });

  it('returns nothing for empty or unrelated queries', () => {
    expect(index.search('   ')).toHaveLength(0);
    expect(index.search('zzzzzz')).toHaveLength(0);
  });
});

describe('SearchIndex (real tracker snapshot)', () => {
  const index = new SearchIndex(realGraph());
  const top = (q: string, f?: string) => index.search(q, f)[0]?.node.id;

  it.each([
    ['hypertension', 'disease:hypertension'],
    ['asthma', 'disease:asthma'],
    ['prostate cancer', 'disease:prostate-cancer'],
    ['gerd', 'disease:gastroesophageal-reflux-disease-gerd'],
    ['parkinsons', 'disease:parkinsons-disease'],
    ['metformin', 'drug:metformin'],
    ['metfromin', 'drug:metformin'],
    ['accupril', 'drug:quinapril-hcl'],
  ])('“%s” → %s', (q, id) => {
    expect(top(q)).toBe(id);
  });

  it('answers quickly', () => {
    const t = performance.now();
    for (let i = 0; i < 200; i++) index.search('cardio');
    expect((performance.now() - t) / 200).toBeLessThan(5);
  });
});
