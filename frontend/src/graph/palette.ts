/**
 * Graph colours come from the CSS design tokens (styles/tokens.css), so the
 * WebGL scene, the SVG fallback, the legend and the glyphs always match and a
 * theme can re-brand them through the --imedix-graph-* hooks.
 */
export interface GraphPalette {
  drug: string;
  disease: string;
  topic: string;
  edge: string;
  /** colour for unknown future entity types */
  other: string;
}

/** Used only when tokens are unavailable (e.g. unit tests without CSS). */
export const FALLBACK_PALETTE: GraphPalette = {
  drug: '#8fbfea',
  disease: '#dbe4ed',
  topic: '#a3bad2',
  edge: '#7f9cba',
  other: '#a3bad2',
};

export function readPalette(el: Element): GraphPalette {
  const style = getComputedStyle(el);
  const read = (name: string, fallback: string) => style.getPropertyValue(name).trim() || fallback;
  return {
    drug: read('--ku-drug', FALLBACK_PALETTE.drug),
    disease: read('--ku-disease', FALLBACK_PALETTE.disease),
    topic: read('--ku-topic', FALLBACK_PALETTE.topic),
    edge: read('--ku-edge', FALLBACK_PALETTE.edge),
    other: read('--ku-steel', FALLBACK_PALETTE.other),
  };
}

export function colourFor(palette: GraphPalette, type: string): string {
  return type === 'drug' || type === 'disease' || type === 'topic' ? palette[type] : palette.other;
}
