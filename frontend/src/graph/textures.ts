/**
 * Procedural surface textures (no image assets). Each is a white-based map that
 * multiplies the sphere's instance colour, so one texture serves a whole family.
 *  - capsule bands: satin stripes, read as polished pharmaceutical ceramic (drugs);
 *  - cells: soft organic cell pattern (diseases).
 * Topic spheres use a clean iridescent clear-coat instead.
 */
import { CanvasTexture, RepeatWrapping, SRGBColorSpace } from 'three';
import { mulberry32 } from '../util/env';

function canvas(w: number, h: number): [HTMLCanvasElement, CanvasRenderingContext2D] {
  const c = document.createElement('canvas');
  c.width = w;
  c.height = h;
  const ctx = c.getContext('2d');
  if (!ctx) throw new Error('2D canvas unavailable');
  ctx.fillStyle = '#ffffff';
  ctx.fillRect(0, 0, w, h);
  return [c, ctx];
}

function finish(c: HTMLCanvasElement): CanvasTexture {
  const t = new CanvasTexture(c);
  t.colorSpace = SRGBColorSpace;
  t.wrapS = RepeatWrapping;
  t.wrapT = RepeatWrapping;
  t.anisotropy = 4;
  return t;
}

export function capsuleBands(): CanvasTexture {
  const [c, ctx] = canvas(256, 256);
  // Vertical bands on the equirect map become meridian stripes on the sphere.
  for (let x = 0; x < 256; x += 32) {
    const g = ctx.createLinearGradient(x, 0, x + 32, 0);
    g.addColorStop(0, 'rgba(255,255,255,1)');
    g.addColorStop(0.45, 'rgba(214,222,232,1)');
    g.addColorStop(0.55, 'rgba(214,222,232,1)');
    g.addColorStop(1, 'rgba(255,255,255,1)');
    ctx.fillStyle = g;
    ctx.fillRect(x, 0, 32, 256);
  }
  return finish(c);
}

export function cells(): CanvasTexture {
  const [c, ctx] = canvas(512, 256);
  const rand = mulberry32(42);
  for (let i = 0; i < 70; i++) {
    const x = rand() * 512;
    const y = rand() * 256;
    const r = 18 + rand() * 30;
    const g = ctx.createRadialGradient(x, y, r * 0.2, x, y, r);
    g.addColorStop(0, 'rgba(255,255,255,0)');
    g.addColorStop(0.75, 'rgba(232,222,218,0.28)');
    g.addColorStop(1, 'rgba(255,255,255,0)');
    ctx.fillStyle = g;
    ctx.beginPath();
    ctx.arc(x, y, r, 0, Math.PI * 2);
    ctx.fill();
  }
  return finish(c);
}

/** Soft radial shadow for the grounding halo behind each sphere. */
export function softShadow(): CanvasTexture {
  const c = document.createElement('canvas');
  c.width = c.height = 128;
  const ctx = c.getContext('2d')!;
  const g = ctx.createRadialGradient(64, 64, 0, 64, 64, 64);
  g.addColorStop(0, 'rgba(17,34,54,0.55)');
  g.addColorStop(0.5, 'rgba(17,34,54,0.18)');
  g.addColorStop(1, 'rgba(17,34,54,0)');
  ctx.fillStyle = g;
  ctx.fillRect(0, 0, 128, 128);
  const t = new CanvasTexture(c);
  t.colorSpace = SRGBColorSpace;
  return t;
}
