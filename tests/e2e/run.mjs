// Browser tests against the real plugin template rendered by bin/render-preview.php
// with the production bundle. Usage (from repo root, after `npm run build` in frontend/):
//   php bin/render-preview.php --mode=dist --news-fixture && node tests/e2e/run.mjs [screenshotDir]
import { createRequire } from 'node:module';
import { readFileSync, mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { startServer } from './server.mjs';
import { auditContrast } from './contrast-audit.mjs';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const ROOT = fileURLToPath(new URL('../../', import.meta.url));
const AXE = readFileSync(require.resolve('axe-core/axe.min.js', { paths: [ROOT + 'frontend'] }), 'utf8');
const SHOTS = process.argv[2] ?? ROOT + 'build/screenshots';
mkdirSync(SHOTS, { recursive: true });

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

const browser = await chromium.launch();

console.log('Desktop');
{
  const { page, errors, context } = await open(browser);
  await page.waitForSelector('.ku-float-item:not([hidden])', { timeout: 15000 });
  await page.waitForTimeout(1200);
  await page.screenshot({ path: SHOTS + '/desktop.png' });

  await test('floating labels are real drug and disease guides linking to WordPress', async () => {
    const labels = await page.$$eval('.ku-float-item:not([hidden])', (els) =>
      els.map((li) => ({ type: li.dataset.type, href: li.querySelector('a').href, name: li.querySelector('.ku-float__name').textContent })),
    );
    assert(labels.length >= 6, 'only ' + labels.length + ' labels');
    for (const l of labels) {
      assert(l.type === 'drug' || l.type === 'disease', 'type ' + l.type);
      assert(new RegExp(`^https://www\\.imedix\\.com/${l.type}s/[a-z0-9-]+/$`).test(l.href), l.href);
    }
    const chips = await page.$$eval('.ku-chip', (els) => els.map((e) => e.textContent.trim()));
    assert(!labels.some((l) => chips.some((c) => c.startsWith(l.name))), 'a label repeats a "Recently updated" chip');
  });
  await test('no 3D renderer: no canvas, no WebGL bundle, statistics visible', async () => {
    assert((await page.locator('canvas').count()) === 0, 'canvas present');
    const chunks = await page.evaluate(() => performance.getEntriesByType('resource').filter((r) => /\.js$/.test(r.name)).map((r) => r.name));
    assert(chunks.length === 1 && /main-/.test(chunks[0]), chunks.join());
    assert(await page.locator('.ku-legend').isVisible(), 'statistics hidden');
    assert((await page.locator('.ku-legend li').count()) === 2, 'legend items');
  });
  await test('hero: official title as the H1 in two lines, principles above, search below', async () => {
    const h1 = (await page.locator('h1').innerText()).replace(/\s+/g, ' ').trim();
    assert(h1 === 'iMedix: Independent Drug & Health Platform', h1);
    const lines = await page.evaluate(() => {
      const h = document.querySelector('.ku-title');
      return Math.round(h.getBoundingClientRect().height / parseFloat(getComputedStyle(h).lineHeight));
    });
    assert(lines === 2, lines + ' lines');
    const pillars = await page.locator('.ku-pillars span').allTextContents();
    assert(pillars.join('|') === 'Independent|Evidence-based|Transparent', pillars.join());
    assert(await page.locator('label[for="ku-q"]').count() === 1, 'search keeps its (visually hidden) label');
  });
  await test('no editorial-topic UI is visible in the hero', async () => {
    const hero = await page.locator('.ku-hero').innerText();
    assert(!/editorial topic/i.test(hero) && !/knowledge universe/i.test(hero), 'topic/KU wording in hero');
    assert((await page.locator('.ku-glyph--topic, .ku-float-item[data-type="topic"]').count()) === 0, 'topic glyph or label');
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
    await page.locator('#ku-q').focus();
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

  await test('search highlights the best match beside the search field', async () => {
    await page.locator('#ku-q').fill('hypertension');
    await page.waitForTimeout(1400);
    await page.screenshot({ path: SHOTS + '/search.png' });
    const r = await page.evaluate(() => {
      const best = document.querySelector('.ku-float-item.is-best:not([hidden])');
      if (!best) return null;
      const a = best.getBoundingClientRect();
      const f = document.querySelector('.ku-search__field').getBoundingClientRect();
      return { name: best.querySelector('.ku-float__name').textContent, x: a.left + a.width / 2, y: a.top + a.height / 2, f: [f.left, f.right, f.top, f.bottom], muted: document.querySelectorAll('.ku-float-item.is-muted:not([hidden])').length };
    });
    assert(r, 'no best-match label');
    assert(r.name === 'Hypertension', 'best match ' + r.name);
    assert(r.x > r.f[1] || r.x < r.f[0], 'best match is not beside the search field');
    assert(Math.abs(r.y - (r.f[2] + r.f[3]) / 2) < 220, 'best match is far from the search field');
    assert(r.muted > 0, 'other labels did not step back');
  });

  await test('Enter on an option navigates to the WordPress guide', async () => {
    const input = page.locator('#ku-q');
    await input.press('ArrowDown');
    const [req] = await Promise.all([page.waitForRequest(/imedix\.com/), input.press('Enter')]);
    assert(req.url() === 'https://www.imedix.com/diseases/hypertension/', req.url());
    await page.goBack();
  });

  await test('last option falls back to native WordPress search', async () => {
    await page.waitForSelector('.ku-float-item:not([hidden])');
    await page.locator('#ku-q').fill('zzzz unknown');
    await page.waitForTimeout(200);
    const msg = await page.locator('.ku-search__message').textContent();
    assert(/No drug or disease matches/.test(msg), msg);
    const [req] = await Promise.all([page.waitForRequest(/imedix\.com/), page.locator('.ku-opt--all').click()]);
    assert(req.url() === 'https://www.imedix.com/?s=zzzz+unknown', req.url());
    await page.goBack();
  });

  await test('clicking a floating label opens its WordPress guide', async () => {
    await page.locator('#ku-q').fill('');
    await page.keyboard.press('Escape');
    await page.waitForTimeout(900);
    const label = page.locator('.ku-float-item:not([hidden]) .ku-float').first();
    const href = await label.getAttribute('href');
    // The label drifts slowly (never "stable" for Playwright); click it like a person would.
    const box = await label.boundingBox();
    const [req] = await Promise.all([page.waitForRequest(/imedix\.com/), page.mouse.click(box.x + box.width / 2, box.y + box.height / 2)]);
    assert(req.url() === href, req.url() + ' ≠ ' + href);
    await page.waitForLoadState();
    await page.goto(BASE, { waitUntil: 'load' });
    await page.waitForSelector('.ku-float-item:not([hidden])', { timeout: 10000 });
    await page.waitForTimeout(800);
  });

  await test('the highlighted suggestion highlights its floating label', async () => {
    const name = await page.locator('.ku-float-item:not([hidden]) .ku-float__name').first().textContent();
    await page.locator('#ku-q').fill(name);
    await page.waitForTimeout(600);
    await page.locator('#ku-q').press('ArrowDown');
    const active = await page.locator('.ku-float-item.is-active .ku-float__name').allTextContents();
    assert(active.includes(name), 'active: ' + active.join());
    await page.locator('#ku-q').fill('');
    await page.keyboard.press('Escape');
  });

  await test('news ticker sits under the hero: title then date, round all-news link', async () => {
    const order = await page.evaluate(() => [...document.querySelector('[data-ku-root]').children].map((e) => e.className.split(' ')[0]));
    assert(order[0] === 'ku-hero' && order[1] === 'ku-news', order.join());
    const first = await page.evaluate(() => [...document.querySelector('.ku-news__item a').children].map((e) => e.tagName));
    assert(first.join() === 'SPAN,TIME', first.join());
    assert((await page.locator('.ku-news__all').innerText()).trim() === 'All news', 'all-news link name');
  });

  await test('resting hero is quiet: type filter appears only while searching', async () => {
    await page.locator('#ku-q').fill('');
    await page.locator('h1').click();
    assert(await page.locator('.ku-filter').isHidden(), 'filter visible at rest');
    await page.locator('#ku-q').focus();
    assert(await page.locator('.ku-filter').isVisible(), 'filter hidden while searching');
    await page.locator('h1').click();
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

  await test('category colours come from the design tokens', async () => {
    const t = await page.evaluate(() => {
      const s = getComputedStyle(document.querySelector('.imedix-ku'));
      return ['--ku-drug', '--ku-disease'].map((v) => s.getPropertyValue(v).trim());
    });
    assert(t.every(Boolean) && new Set(t).size === 2, t.join());
  });

  await test('no web fonts are bundled (theme fonts are inherited)', async () => {
    const fonts = await page.evaluate(() => performance.getEntriesByType('resource').filter((r) => /\.woff2?$/.test(r.name)).length);
    assert(fonts === 0, fonts + ' font files');
  });

  await test('readability: every visible text element meets WCAG AA contrast (incl. floating labels)', async () => {
    await page.locator('#ku-q').fill('hyper');
    await page.waitForTimeout(1200);
    const withPanel = await auditContrast(page);
    await page.locator('#ku-q').fill('');
    await page.keyboard.press('Escape');
    const page1 = await auditContrast(page);
    const bad = [...withPanel.failures, ...page1.failures];
    assert(page1.checked > 200, 'only ' + page1.checked + ' text elements checked');
    assert(bad.length === 0, bad.slice(0, 4).map((f) => `${f.cls} "${f.text}" ${f.ratio}:1 < ${f.need}`).join('; '));
    console.log(`      (${page1.checked} text elements, lowest ratio ${Math.min(page1.worst, withPanel.worst)}:1)`);
  });

  await test('floating labels are fully opaque and never overlap each other', async () => {
    await page.waitForTimeout(1000);
    const r = await page.evaluate(() => {
      const shown = [...document.querySelectorAll('.ku-float-item:not([hidden])')];
      const odd = shown.filter((l) => getComputedStyle(l).opacity !== '1');
      const rects = shown.map((l) => l.querySelector('.ku-float').getBoundingClientRect());
      let overlaps = 0;
      for (let i = 0; i < rects.length; i++)
        for (let j = i + 1; j < rects.length; j++) {
          const a = rects[i], b = rects[j];
          if (a.left < b.right && b.left < a.right && a.top < b.bottom && b.top < a.bottom) overlaps++;
        }
      return { shown: shown.length, odd: odd.length, overlaps };
    });
    assert(r.shown >= 6, 'only ' + r.shown + ' labels visible');
    assert(r.odd === 0, r.odd + ' half-faded labels');
    assert(r.overlaps === 0, r.overlaps + ' overlapping labels');
  });

  await test('labels never cover the headline, search block or statistics', async () => {
    const hits = await page.evaluate(() => {
      const boxes = ['.ku-title', '.ku-pillars', '.ku-lede', '.ku-search', '.ku-try', '.ku-legend'].map((s) => document.querySelector(s).getBoundingClientRect());
      return [...document.querySelectorAll('.ku-float-item:not([hidden]) .ku-float')]
        .filter((l) => {
          const a = l.getBoundingClientRect();
          return boxes.some((b) => a.left < b.right && b.left < a.right && a.top < b.bottom && b.top < a.bottom);
        })
        .map((l) => l.textContent);
    });
    assert(hits.length === 0, hits.join(', '));
  });

  await test('categories are colour-distinct: marks and text colours', async () => {
    const c = await page.evaluate(() => {
      const s = getComputedStyle(document.querySelector('.imedix-ku'));
      return ['drug', 'disease'].flatMap((k) => [s.getPropertyValue(`--ku-cat-${k}-mark`).trim(), s.getPropertyValue(`--ku-cat-${k}-strong`).trim()]);
    });
    assert(new Set(c).size === 4 && c.every(Boolean), c.join());
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

console.log('Reduced motion');
{
  const { page, context } = await open(browser, { reducedMotion: 'reduce' });
  await page.waitForSelector('.ku-float-item:not([hidden])', { timeout: 15000 });
  await test('ticker is static and scrollable; no pause button needed', async () => {
    assert(!(await page.locator('[data-ku-news]').getAttribute('class')).includes('is-animated'), 'animated');
    assert(await page.locator('[data-ku-ticker-toggle]').isHidden(), 'toggle visible');
    const overflow = await page.evaluate(() => getComputedStyle(document.querySelector('.ku-news__viewport')).overflowX);
    assert(overflow === 'auto', overflow);
  });
  await test('floating labels do not drift', async () => {
    const anim = await page.evaluate(() => getComputedStyle(document.querySelector('.ku-float')).animationName);
    assert(anim === 'none', anim);
  });
  await context.close();
}

console.log('Tablet');
{
  const { page, errors, context } = await open(browser, { viewport: { width: 820, height: 1180 }, hasTouch: true, isMobile: true, deviceScaleFactor: 2 });
  await page.waitForSelector('.ku-float-item:not([hidden])', { timeout: 15000 });
  await page.waitForTimeout(1500);
  await page.screenshot({ path: SHOTS + '/tablet.png' });
  await test('tablet: no horizontal overflow, no console errors', async () => {
    const w = await page.evaluate(() => [document.documentElement.scrollWidth, window.innerWidth]);
    assert(w[0] <= w[1], w.join(' > '));
    assert(errors.length === 0, errors.join(' | '));
  });
  await page.locator('.ku-discover').screenshot({ path: SHOTS + '/tablet-library.png' });
  await context.close();
}

console.log('Mobile');
{
  const { page, errors, context } = await open(browser, { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 });
  await page.waitForSelector('.ku-float-item:not([hidden])', { timeout: 15000 });
  await page.waitForTimeout(2000);
  await page.screenshot({ path: SHOTS + '/mobile.png' });
  await test('no horizontal overflow at 390px (incl. content clipped by overflow-x)', async () => {
    const w = await page.evaluate(() => [document.documentElement.scrollWidth, window.innerWidth]);
    assert(w[0] <= w[1], w.join(' > '));
    const wide = await page.evaluate(() =>
      [...document.querySelectorAll('.ku-hero__inner, .ku-hero__inner *, .ku-wrap, .ku-wrap *')]
        .filter((e) => e.getBoundingClientRect().right > window.innerWidth + 1)
        .map((e) => e.className.baseVal ?? e.className),
    );
    assert(wide.length === 0, wide.slice(0, 5).join(', '));
  });
  await test('health-area cards collapse to three guides and expand on demand', async () => {
    const card = page.locator('.ku-topic').first();
    const visible = () => card.locator('.ku-topic__list li:visible').count();
    assert((await visible()) === 3, 'collapsed shows ' + (await visible()));
    await card.locator('[data-ku-expand]').tap();
    assert((await visible()) > 3, 'did not expand');
    assert((await card.locator('[data-ku-expand]').getAttribute('aria-expanded')) === 'true', 'aria-expanded');
  });
  await test('mobile readability: WCAG AA contrast', async () => {
    const r = await auditContrast(page);
    assert(r.failures.length === 0, r.failures.slice(0, 4).map((f) => `${f.cls} "${f.text}" ${f.ratio}`).join('; '));
  });
  await test('search field and button are at least 44px tall', async () => {
    const h = await page.evaluate(() => [document.querySelector('.ku-search__input').getBoundingClientRect().height, document.querySelector('.ku-search__submit').getBoundingClientRect().height]);
    assert(h.every((x) => x >= 44), h.join());
  });
  await test('mobile search: best match surfaces above the text, highlighted', async () => {
    await page.locator('#ku-q').fill('asthma');
    await page.waitForTimeout(1400);
    await page.screenshot({ path: SHOTS + '/mobile-search.png' });
    const r = await page.evaluate(() => {
      const best = document.querySelector('.ku-float-item.is-best:not([hidden])');
      if (!best) return null;
      const inner = document.querySelector('.ku-hero__inner').getBoundingClientRect();
      const a = best.querySelector('.ku-float').getBoundingClientRect();
      return { name: best.querySelector('.ku-float__name').textContent, overlaps: a.top < inner.bottom && inner.top < a.bottom && a.left < inner.right && inner.left < a.right };
    });
    assert(r, 'no best-match label');
    assert(r.name === 'Asthma', r.name);
    assert(!r.overlaps, 'label covers the text column');
    await page.locator('#ku-q').fill('');
    await page.keyboard.press('Escape');
  });
  await test('mobile stays responsive (a frame completes within 100 ms)', async () => {
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
  await page.waitForSelector('.ku-float-item:not([hidden])', { timeout: 15000 });
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
