/**
 * Graph colours come from the CSS design tokens (styles/tokens.css), so the 3D
 * spheres, the CSS fallback, the legend and the glyphs always match, and a theme
 * can re-brand them through the --imedix-* hooks.
 */
export interface GraphPalette {
  /** three material colours per category family */
  drug: string[];
  disease: string[];
  topic: string[];
  other: string[];
  edge: string;
  /** hero background (fog colour for depth) */
  background: string;
}

/** Used only when tokens are unavailable (e.g. unit tests without CSS). */
export const FALLBACK_PALETTE: GraphPalette = {
  drug: ['#2f80ed', '#22b5d6', '#4f6ff0'],
  disease: ['#f2705d', '#f59a4a', '#e2574c'],
  topic: ['#b3a4f0', '#c9aee9', '#9fb2f4'],
  other: ['#2fa58f', '#2fa58f', '#2fa58f'],
  edge: '#8ea3ba',
  background: '#f2f4f7',
};

export function readPalette(el: Element): GraphPalette {
  const style = getComputedStyle(el);
  const read = (name: string, fallback: string) => style.getPropertyValue(name).trim() || fallback;
  const family = (cat: keyof Pick<GraphPalette, 'drug' | 'disease' | 'topic'>) =>
    [1, 2, 3].map((k) => read(`--ku-cat-${cat}-sphere-${k}`, FALLBACK_PALETTE[cat][k - 1]));
  return {
    drug: family('drug'),
    disease: family('disease'),
    topic: family('topic'),
    other: FALLBACK_PALETTE.other.map(() => read('--ku-cat-other-sphere-1', FALLBACK_PALETTE.other[0])),
    edge: read('--ku-edge', FALLBACK_PALETTE.edge),
    background: read('--ku-hero-bg', FALLBACK_PALETTE.background),
  };
}

export function colourFor(palette: GraphPalette, type: string, variant = 0): string {
  const family = type === 'drug' || type === 'disease' || type === 'topic' ? palette[type] : palette.other;
  return family[variant % family.length];
}
