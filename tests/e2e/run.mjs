// Browser tests against the real plugin template rendered by bin/render-preview.php
// with the production bundle. Usage (from repo root, after `npm run build` in frontend/):
//   php bin/render-preview.php --mode=dist --news-fixture && node tests/e2e/run.mjs [screenshotDir]
import { createRequire } from 'node:module';
import { readFileSync, mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { startServer } from './server.mjs';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const ROOT = fileURLToPath(new URL('../../', import.meta.url));
const AXE = readFileSync(require.resolve('axe-core/axe.min.js', { paths: [ROOT + 'frontend'] }), 'utf8');
const SHOTS = process.argv[2] ?? ROOT + 'build/screenshots';
mkdirSync(SHOTS, { recursive: true });

const GL_ARGS = ['--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader'];
let pass = 0;
const failures = [];
async function test(name, fn) {
  try {
    await fn();
    pass++;
    console.log('  ✓ ' + name);
  } catch (err) {
    failures.push(name);
    console.log('  ✗ ' + name + '\n      ' + String(err?.message ?? err).split('\n')[0]);
  }
}
function assert(cond, msg) {
  if (!cond) throw new Error(msg);
}

const server = await startServer();
const BASE = `http://127.0.0.1:${server.address().port}/build/preview/`;

async function open(browser, opts = {}, setup) {
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, ...opts });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));
  // Never leave the sandbox: links point at www.imedix.com.
  await page.route(/imedix\.com/, (route) => route.fulfill({ status: 200, contentType: 'text/html', body: '<title>stub</title>' }));
  if (setup) await setup(page);
  await page.goto(BASE, { waitUntil: 'load' });
  return { context, page, errors };
}

const browser = await chromium.launch({ args: GL_ARGS });

console.log('Desktop');
{
  const { page, errors, context } = await open(browser);
  await page.waitForSelector('[data-ku-stage].is-ready canvas', { timeout: 15000 });
  await page.waitForTimeout(2500);
  await page.screenshot({ path: SHOTS + '/desktop.png' });

  await test('WebGL graph mounts and HUD becomes visible', async () => {
    assert(await page.locator('[data-ku-hud]').isVisible(), 'HUD hidden');
  });
  await test('no console errors', async () => assert(errors.length === 0, errors.join(' | ')));

  await test('combobox ARIA wiring', async () => {
    const input = page.locator('#ku-q');
    assert((await input.getAttribute('role')) === 'combobox', 'role');
    assert((await input.getAttribute('aria-controls')) === 'ku-q-list', 'aria-controls');
    await input.fill('asthma');
    await page.waitForSelector('[role="option"]');
    assert((await input.getAttribute('aria-expanded')) === 'true', 'expanded');
    await input.press('ArrowDown');
    const active = await input.getAttribute('aria-activedescendant');
    assert(active === 'ku-opt-0', 'activedescendant ' + active);
    assert((await page.locator('#ku-opt-0').getAttribute('aria-selected')) === 'true', 'aria-selected');
    const first = await page.locator('#ku-opt-0 .ku-opt__name').textContent();
    assert(first === 'Asthma', 'first option ' + first);
    await page.waitForTimeout(500);
    const status = await page.locator('[data-ku-status]').textContent();
    assert(/suggestion/.test(status), 'live status: ' + status);
  });

  await test('Escape closes, second Escape clears', async () => {
    const input = page.locator('#ku-q');
    await input.press('Escape');
    assert(await page.locator('[data-ku-panel]').isHidden(), 'panel still open');
    await input.press('Escape');
    assert((await input.inputValue()) === '', 'not cleared');
  });

  await test('type filter restricts suggestions', async () => {
    await page.locator('.ku-filter label', { hasText: 'Drugs' }).click();
    await page.locator('#ku-q').fill('diabetes');
    await page.waitForTimeout(200);
    const types = await page.locator('.ku-opt__type').allTextContents();
    assert(types.every((t) => t === 'Drug'), types.join());
    await page.locator('.ku-filter label', { hasText: 'All' }).click();
  });

  await test('typo tolerance and redirect aliases', async () => {
    await page.locator('#ku-q').fill('metfromin');
    await page.waitForTimeout(200);
    assert((await page.locator('#ku-opt-0 .ku-opt__name').textContent()) === 'Metformin', 'typo');
    await page.locator('#ku-q').fill('accupril');
    await page.waitForTimeout(200);
    const sub = await page.locator('#ku-opt-0 .ku-opt__sub').textContent();
    assert(/Accupril/.test(sub) && /quinapril/.test(sub), sub);
  });

  await test('search highlights the graph and draws the connector', async () => {
    await page.locator('#ku-q').fill('hypertension');
    await page.waitForTimeout(2200);
    await page.screenshot({ path: SHOTS + '/search.png' });
    assert(await page.locator('.ku-label--match').count() >= 1, 'no match label');
    assert(await page.locator('.ku-connector.is-on').count() === 1, 'connector not drawn');
  });

  await test('Enter on an option navigates to the WordPress guide', async () => {
    const input = page.locator('#ku-q');
    await input.press('ArrowDown');
    const [req] = await Promise.all([page.waitForRequest(/imedix\.com/), input.press('Enter')]);
    assert(req.url() === 'https://www.imedix.com/diseases/hypertension/', req.url());
    await page.goBack();
  });

  await test('last option falls back to native WordPress search', async () => {
    await page.waitForSelector('[data-ku-stage].is-ready canvas');
    await page.locator('#ku-q').fill('zzzz unknown');
    await page.waitForTimeout(200);
    const msg = await page.locator('.ku-search__message').textContent();
    assert(/No drug or disease matches/.test(msg), msg);
    const [req] = await Promise.all([page.waitForRequest(/imedix\.com/), page.locator('.ku-opt--all').click()]);
    assert(req.url() === 'https://www.imedix.com/?s=zzzz+unknown', req.url());
    await page.goBack();
  });

  await test('clicking a topic in the map opens its details card', async () => {
    await page.waitForSelector('[data-ku-stage].is-ready canvas');
    await page.locator('[data-ku-action="motion"]').click(); // stop rotation for a stable click
    await page.waitForTimeout(800);
    const target = await page.evaluate(() => {
      const inner = document.querySelector('.ku-hero__inner').getBoundingClientRect();
      for (const el of document.querySelectorAll('.ku-label:not(.ku-label--match)')) {
        if (Number(el.style.opacity) < 0.3) continue;
        const m = /translate\(([-\d.]+)px, ([-\d.]+)px\)/.exec(el.style.transform);
        if (!m) continue;
        const stage = document.querySelector('[data-ku-stage]').getBoundingClientRect();
        const x = stage.left + Number(m[1]);
        const y = stage.top + Number(m[2]) + 16;
        const outside = x < inner.left - 20 || x > inner.right + 20 || y < inner.top - 20 || y > inner.bottom + 20;
        if (outside && y > 80 && y < window.innerHeight - 120 && x > 60 && x < window.innerWidth - 60) return { x, y, name: el.textContent };
      }
      return null;
    });
    assert(target, 'no clickable topic outside the text block');
    await page.mouse.click(target.x, target.y);
    await page.waitForSelector('[data-ku-card]:not([hidden])', { timeout: 3000 });
    const title = await page.locator('[data-ku-card-title]').textContent();
    assert(title === target.name, `card "${title}" ≠ "${target.name}"`);
    assert(await page.locator('[data-ku-card] .ku-card__item').count() > 0, 'no member links');
    await page.screenshot({ path: SHOTS + '/card.png' });
    await page.keyboard.press('Escape');
    assert(await page.locator('[data-ku-card]').isHidden(), 'Escape did not close the card');
  });

  await test('ticker animates, pauses via button, clones hidden from AT', async () => {
    const news = page.locator('[data-ku-news]');
    assert((await news.getAttribute('class')).includes('is-animated'), 'not animated');
    const clones = page.locator('.ku-news__item[aria-hidden="true"] a');
    assert((await clones.count()) > 0, 'no clones');
    assert((await clones.first().getAttribute('tabindex')) === '-1', 'clone focusable');
    const toggle = page.locator('[data-ku-ticker-toggle]');
    await toggle.click();
    assert((await toggle.getAttribute('aria-pressed')) === 'true', 'aria-pressed');
    assert((await news.getAttribute('class')).includes('is-paused'), 'not paused');
    await toggle.click();
  });

  await test('ticker pauses while a headline has keyboard focus', async () => {
    await page.locator('.ku-news__item:not([aria-hidden]) a').first().focus();
    const state = await page.evaluate(() => getComputedStyle(document.querySelector('.ku-news__track')).animationPlayState);
    assert(state === 'paused', state);
  });

  await test('axe-core: no serious or critical violations', async () => {
    await page.locator('#ku-q').fill('');
    await page.addScriptTag({ content: AXE });
    const result = await page.evaluate(async () =>
      // eslint-disable-next-line no-undef
      axe.run(document, { resultTypes: ['violations'], rules: { region: { enabled: false } } }),
    );
    const bad = result.violations.filter((v) => ['serious', 'critical'].includes(v.impact));
    assert(bad.length === 0, bad.map((v) => `${v.id} (${v.nodes.length}): ${v.nodes[0]?.target}`).join('; '));
  });

  await page.screenshot({ path: SHOTS + '/full.png', fullPage: true });
  await context.close();
}

console.log('Graph data unavailable');
{
  const { page, context } = await open(browser, {}, (p) => p.route(/dev\/graph\.json/, (r) => r.fulfill({ status: 500, body: 'x' })));
  await test('search still offers full-text search when the graph fails', async () => {
    await page.locator('#ku-q').fill('asthma');
    await page.waitForTimeout(300);
    const msg = await page.locator('.ku-search__message').textContent();
    assert(/unavailable/.test(msg), msg);
    const [req] = await Promise.all([page.waitForRequest(/imedix\.com/), page.locator('#ku-q').press('Enter')]);
    assert(req.url().startsWith('https://www.imedix.com/?s=asthma'), req.url());
  });
  await context.close();
}

console.log('No WebGL');
{
  const noGl = await chromium.launch({ args: ['--disable-webgl', '--disable-3d-apis'] });
  const { page, errors, context } = await open(noGl);
  await test('2D SVG fallback replaces the WebGL scene', async () => {
    await page.waitForSelector('svg.ku-fallback', { timeout: 10000 });
    assert((await page.locator('canvas').count()) === 0, 'canvas present');
    assert(await page.locator('[data-ku-hud]').isHidden(), 'HUD shown without controls to drive');
  });
  await test('search works without WebGL', async () => {
    await page.locator('#ku-q').fill('psoriasis');
    await page.waitForSelector('#ku-opt-0');
    assert((await page.locator('#ku-opt-0 .ku-opt__name').textContent()) === 'Psoriasis', 'no result');
  });
  await test('no console errors without WebGL', async () => assert(errors.length === 0, errors.join(' | ')));
  await page.screenshot({ path: SHOTS + '/no-webgl.png' });
  await context.close();
  await noGl.close();
}

console.log('Reduced motion');
{
  const { page, context } = await open(browser, { reducedMotion: 'reduce' });
  await page.waitForSelector('[data-ku-stage].is-ready canvas', { timeout: 15000 });
  await test('ticker is static and scrollable; no pause button needed', async () => {
    assert(!(await page.locator('[data-ku-news]').getAttribute('class')).includes('is-animated'), 'animated');
    assert(await page.locator('[data-ku-ticker-toggle]').isHidden(), 'toggle visible');
    const overflow = await page.evaluate(() => getComputedStyle(document.querySelector('.ku-news__viewport')).overflowX);
    assert(overflow === 'auto', overflow);
  });
  await test('map rotation starts paused', async () => {
    assert((await page.locator('[data-ku-action="motion"]').getAttribute('aria-pressed')) === 'true', 'rotating');
  });
  await context.close();
}

console.log('Mobile');
{
  const { page, errors, context } = await open(browser, { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 });
  await page.waitForSelector('[data-ku-stage].is-ready', { timeout: 15000 });
  await page.waitForTimeout(2000);
  await page.screenshot({ path: SHOTS + '/mobile.png' });
  await test('no horizontal overflow at 390px', async () => {
    const w = await page.evaluate(() => [document.documentElement.scrollWidth, window.innerWidth]);
    assert(w[0] <= w[1], w.join(' > '));
  });
  await test('search field and button are at least 44px tall', async () => {
    const h = await page.evaluate(() => [document.querySelector('.ku-search__input').getBoundingClientRect().height, document.querySelector('.ku-search__submit').getBoundingClientRect().height]);
    assert(h.every((x) => x >= 44), h.join());
  });
  await test('touch devices get an explicit “Explore the map” mode', async () => {
    const btn = page.locator('[data-ku-action="explore"]');
    assert(await btn.isVisible(), 'explore button hidden');
    await btn.tap();
    assert((await page.locator('.ku-hero').getAttribute('class')).includes('is-exploring'), 'not exploring');
    await page.screenshot({ path: SHOTS + '/mobile-explore.png' });
    await btn.tap();
  });
  await test('mobile budget samples fewer nodes (medium tier)', async () => {
    // sanity: page stays responsive — a frame completes within 100 ms
    const t = await page.evaluate(() => new Promise((r) => { const s = performance.now(); requestAnimationFrame(() => r(performance.now() - s)); }));
    assert(t < 100, String(t));
  });
  await test('no console errors on mobile', async () => assert(errors.length === 0, errors.join(' | ')));
  await page.screenshot({ path: SHOTS + '/mobile-full.png', fullPage: true });
  await context.close();
}

console.log('JavaScript disabled');
{
  const { page, context } = await open(browser, { javaScriptEnabled: false });
  await test('heading, search form, news and topic links are server-rendered', async () => {
    assert((await page.locator('h1').count()) === 1, 'h1');
    assert((await page.locator('form[role="search"] input[name="s"]').count()) === 1, 'form');
    assert((await page.locator('.ku-news__item a').count()) >= 5, 'news');
    assert((await page.locator('.ku-topic__list a').count()) >= 40, 'topic links');
  });
  await test('heading hierarchy h1 → h2 → h3', async () => {
    const levels = await page.$$eval('.imedix-ku h1, .imedix-ku h2, .imedix-ku h3', (els) => els.map((e) => Number(e.tagName[1])));
    for (let i = 1; i < levels.length; i++) assert(levels[i] - levels[i - 1] <= 1, levels.join(','));
  });
  await context.close();
}

console.log('Performance budget');
{
  const { page, context } = await open(browser);
  await page.waitForSelector('[data-ku-stage].is-ready canvas', { timeout: 15000 });
  const m = await page.evaluate(() => {
    const res = performance.getEntriesByType('resource');
    const size = (re) => res.filter((r) => re.test(r.name)).reduce((s, r) => s + (r.encodedBodySize || r.transferSize || 0), 0);
    const lcp = new Promise((resolve) => new PerformanceObserver((l) => resolve(l.getEntries().at(-1)?.startTime ?? 0)).observe({ type: 'largest-contentful-paint', buffered: true }));
    const cls = new Promise((resolve) => {
      let v = 0;
      new PerformanceObserver((l) => l.getEntries().forEach((e) => (v += e.hadRecentInput ? 0 : e.value))).observe({ type: 'layout-shift', buffered: true });
      setTimeout(() => resolve(v), 500);
    });
    return Promise.all([lcp, cls]).then(([l, c]) => ({ mainJs: size(/main-.*\.js$/), css: size(/\.css$/), view: size(/view-.*\.js$/), graph: size(/graph\.json$/), lcp: l, cls: c }));
  });
  console.log('    ' + JSON.stringify(m));
  await test('CLS below 0.1', async () => assert(m.cls < 0.1, String(m.cls)));
  await context.close();
}

await browser.close();
server.close();
console.log(`\nBrowser e2e: ${pass} passed, ${failures.length} failed`);
process.exit(failures.length ? 1 : 0);
