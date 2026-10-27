import type { Tier } from './sampling';
import { coarsePointer } from '../util/env';

interface NavigatorHints {
  deviceMemory?: number;
  hardwareConcurrency?: number;
  connection?: { saveData?: boolean };
}

/** Rendering tier from WebGL support and device hints. 'none' → 2D fallback. */
export function detectTier(): Tier {
  const nav = navigator as Navigator & NavigatorHints;
  if (nav.connection?.saveData) return 'none';

  const canvas = document.createElement('canvas');
  let gl: WebGLRenderingContext | WebGL2RenderingContext | null = null;
  let tier: Tier = 'high';
  try {
    gl = canvas.getContext('webgl2', { failIfMajorPerformanceCaveat: true }) ?? canvas.getContext('webgl', { failIfMajorPerformanceCaveat: true });
    if (!gl) {
      // Software rasteriser available: usable, but keep the scene small.
      gl = canvas.getContext('webgl2') ?? canvas.getContext('webgl');
      tier = 'low';
    }
  } catch {
    gl = null;
  }
  if (!gl) return 'none';
  gl.getExtension('WEBGL_lose_context')?.loseContext();

  const memory = nav.deviceMemory ?? 8;
  const cores = nav.hardwareConcurrency ?? 8;
  const small = Math.min(window.innerWidth, window.screen?.width ?? window.innerWidth) < 760;
  if (tier === 'low' || memory <= 2 || cores <= 2) return 'low';
  if (small || coarsePointer() || memory <= 4) return 'medium';
  return 'high';
}
