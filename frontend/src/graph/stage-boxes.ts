/**
 * DOM → normalised stage rectangles for the sphere composition: the text column
 * (kept clear) and the HUD pieces (legend, map controls) that spheres avoid.
 */
import type { SafeBox } from './sphere-layout';

export function stageBox(stage: HTMLElement, el: Element | null, pad: number): SafeBox | null {
  if (!el) return null;
  const s = stage.getBoundingClientRect();
  const r = el.getBoundingClientRect();
  if (!r.width || !r.height) return null;
  const nx = (x: number) => ((x - s.left) / Math.max(1, s.width)) * 2 - 1;
  const ny = (y: number) => 1 - ((y - s.top) / Math.max(1, s.height)) * 2;
  return { l: nx(r.left - pad), r: nx(r.right + pad), t: ny(r.top - pad), b: ny(r.bottom + pad) };
}

export function compositionBoxes(hero: HTMLElement, stage: HTMLElement): { safe: () => SafeBox; avoid: () => SafeBox[] } {
  const inner = hero.querySelector('.ku-hero__inner');
  return {
    safe: () => stageBox(stage, inner, 18) ?? { l: -0.4, r: 0.4, t: 0.4, b: -0.4 },
    avoid: () =>
      [...hero.querySelectorAll('.ku-legend, .ku-controls')]
        .map((el) => stageBox(stage, el, 10))
        .filter((b): b is SafeBox => b !== null),
  };
}
