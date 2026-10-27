// Readability audit: measures the *rendered* contrast of every visible text element.
// Unlike axe-core it composites ancestor opacity and translucent backgrounds, and treats
// the WebGL canvas as the hero's base colour, so text over the map is measured too.
// Usage: export async function auditContrast(page) → { failures, checked, worst }

export async function auditContrast(page, scope = '.imedix-ku') {
  return page.evaluate((scope) => {
    const parse = (c) => {
      const m = c.match(/rgba?\(([^)]+)\)/);
      if (!m) return null;
      const p = m[1].split(/[ ,/]+/).filter(Boolean).map(Number);
      return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 };
    };
    const over = (fg, bg) => ({
      r: fg.r * fg.a + bg.r * (1 - fg.a),
      g: fg.g * fg.a + bg.g * (1 - fg.a),
      b: fg.b * fg.a + bg.b * (1 - fg.a),
      a: 1,
    });
    const lum = (c) => {
      const f = (v) => {
        v /= 255;
        return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
      };
      return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b);
    };
    const ratio = (a, b) => {
      const [x, y] = [lum(a), lum(b)].sort((m, n) => n - m);
      return (x + 0.05) / (y + 0.05);
    };
    const heroBase = parse(getComputedStyle(document.querySelector('.ku-hero') ?? document.body).backgroundColor) ?? { r: 17, g: 34, b: 54, a: 1 };

    // Background behind an element: composite translucent ancestor backgrounds bottom-up.
    const backgroundOf = (el) => {
      const layers = [];
      for (let n = el; n && n !== document.documentElement; n = n.parentElement) {
        const cs = getComputedStyle(n);
        const bg = parse(cs.backgroundColor);
        if (bg && bg.a > 0) layers.push(bg);
        if (bg && bg.a >= 1) break;
        if (n.classList?.contains('ku-hero')) {
          layers.push(heroBase);
          break;
        }
      }
      let out = { r: 255, g: 255, b: 255, a: 1 };
      for (let i = layers.length - 1; i >= 0; i--) out = over(layers[i], out);
      return out;
    };
    const opacityOf = (el) => {
      let o = 1;
      for (let n = el; n; n = n.parentElement) o *= Number(getComputedStyle(n).opacity);
      return o;
    };

    const failures = [];
    let checked = 0;
    let worst = 99;
    const root = document.querySelector(scope);
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    const seen = new Set();
    for (let t = walker.nextNode(); t; t = walker.nextNode()) {
      const el = t.parentElement;
      if (!el || seen.has(el) || !t.textContent.trim()) continue;
      seen.add(el);
      if (el.closest('[hidden], .ku-visually-hidden, script, [aria-hidden="true"]:not(.ku-labels):not(.ku-labels *)')) continue;
      const rect = el.getBoundingClientRect();
      if (!rect.width || !rect.height) continue;
      const cs = getComputedStyle(el);
      if (cs.visibility === 'hidden') continue;
      const o = opacityOf(el);
      if (o < 0.05) continue; // fully faded = not displayed
      const fg = parse(cs.color);
      const bg = backgroundOf(el);
      const shown = over({ ...fg, a: fg.a * o }, bg);
      const r = ratio(shown, bg);
      const size = parseFloat(cs.fontSize);
      const bold = Number(cs.fontWeight) >= 700;
      const large = size >= 24 || (bold && size >= 18.66);
      const need = large ? 3 : 4.5;
      checked++;
      worst = Math.min(worst, r);
      if (r < need) {
        failures.push({ text: t.textContent.trim().slice(0, 40), cls: String(el.className), ratio: Math.round(r * 100) / 100, need, size });
      }
    }
    return { checked, worst: Math.round(worst * 100) / 100, failures };
  }, scope);
}
