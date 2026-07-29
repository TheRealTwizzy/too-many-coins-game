#!/usr/bin/env node
/**
 * Too Many Coins - UI smoke test
 *
 * Drives a page in a real browser across breakpoints and FAILS on any console
 * error or unhandled rejection. "Works flawlessly, without visual bugs" has to
 * be an enforced gate, not a hope.
 *
 * Uses the preinstalled Chromium. Do NOT run `playwright install`.
 *
 * Usage:
 *   node tools/ui_smoke.mjs [url-or-path] [--out DIR] [--headed]
 *
 * Defaults to public/design/deck.html.
 * Exit: 0 = clean, 1 = console errors / failed interactions.
 */

import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import path from 'node:path';
import fs from 'node:fs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(__dirname, '..');

const argv = process.argv.slice(2);
const outIdx = argv.indexOf('--out');
const outDir = outIdx !== -1 ? argv[outIdx + 1] : path.join(repoRoot, '.smoke');
const positional = argv.filter((a, i) => !a.startsWith('--') && i !== outIdx + 1);
const target = positional[0] || 'public/design/deck.html';

const url = /^https?:\/\//.test(target)
  ? target
  : pathToFileURL(path.resolve(repoRoot, target)).href;

fs.mkdirSync(outDir, { recursive: true });

const BREAKPOINTS = [
  { name: 'mobile',  width: 390,  height: 844 },
  { name: 'tablet',  width: 900,  height: 1000 },
  { name: 'desktop', width: 1440, height: 900 },
];

const problems = [];
let interactions = 0;

function record(kind, bp, detail) {
  problems.push({ kind, bp, detail });
  console.log(`  ✗ [${bp}] ${kind}: ${detail}`);
}

const browser = await chromium.launch({
  executablePath: '/opt/pw-browsers/chromium',
  headless: !argv.includes('--headed'),
});

console.log('UI smoke test');
console.log('-'.repeat(64));
console.log('target: ' + url);

for (const bp of BREAKPOINTS) {
  const ctx = await browser.newContext({
    viewport: { width: bp.width, height: bp.height },
    deviceScaleFactor: 2,
  });
  const page = await ctx.newPage();

  page.on('console', (m) => {
    if (m.type() === 'error') record('console.error', bp.name, m.text());
    if (m.type() === 'warning' && /deprecat/i.test(m.text())) {
      record('console.warn', bp.name, m.text());
    }
  });
  page.on('pageerror', (e) => record('pageerror', bp.name, e.message));
  page.on('requestfailed', (r) => {
    // A self-contained page must not reach the network at all.
    record('requestfailed', bp.name, `${r.url()} (${r.failure()?.errorText})`);
  });

  await page.goto(url, { waitUntil: 'load' });

  // Sit through at least one full tick.
  //
  // The comment here used to claim 1400ms let "one tick land", but the deck runs
  // on a 5s cadence, so no tick ever fired during a run. Anything that only goes
  // wrong on tick - which is most of a live game surface - was invisible to this
  // gate. The cadence is read off the page rather than hardcoded so the two
  // cannot drift.
  const cadenceMs = await page.evaluate(() => {
    const txt = document.getElementById('cadence')?.textContent || '';
    const m = txt.match(/(\d+(?:\.\d+)?)\s*s/i);
    return m ? Math.round(parseFloat(m[1]) * 1000) : 5000;
  });
  // entrance stagger, then a full tick, then a beat for the render it triggers
  await page.waitForTimeout(1400 + cadenceMs + 600);

  // --- structural assertions -------------------------------------------
  const cells = await page.locator('.cell:not([hidden])').count();
  if (cells === 0) record('assert', bp.name, 'no visible grid cells');

  // horizontal overflow is the classic responsive bug
  const overflow = await page.evaluate(() =>
    document.documentElement.scrollWidth - document.documentElement.clientWidth
  );
  if (overflow > 1) {
    record('layout', bp.name, `page scrolls horizontally by ${overflow}px`);
  }

  // Garbage rendered as text. A stale state field silently produces "NaN" or
  // "undefined" in the UI without ever throwing, so the console-error gate
  // above sees nothing. This is a visual bug the user would hit immediately.
  const garbage = await page.evaluate(() => {
    const bad = /(^|[\s>(·×/])(NaN|undefined|null|\[object Object\])([\s<),.·×/]|$)/;
    const hits = [];
    document.querySelectorAll('body *').forEach((el) => {
      // Script and style bodies are source, not rendered text - they legitimately
      // contain the words this check looks for.
      const tag = el.tagName;
      if (tag === 'SCRIPT' || tag === 'STYLE' || tag === 'TEMPLATE' || tag === 'NOSCRIPT') return;
      if (el.closest('script, style, template, noscript')) return;
      // own text only, so a parent does not re-report a child's text
      for (const node of el.childNodes) {
        if (node.nodeType !== Node.TEXT_NODE) continue;
        const t = node.textContent.trim();
        if (t && bad.test(t)) {
          hits.push(`<${el.tagName.toLowerCase()}${el.id ? '#' + el.id : ''}> "${t.slice(0, 60)}"`);
        }
      }
    });
    return hits;
  });
  for (const hit of garbage) {
    record('render', bp.name, `garbage in rendered text: ${hit}`);
  }

  // duplicate ids break querySelector-based wiring silently
  const dupes = await page.evaluate(() => {
    const seen = new Set(), dup = new Set();
    document.querySelectorAll('[id]').forEach((el) => {
      if (seen.has(el.id)) dup.add(el.id);
      seen.add(el.id);
    });
    return [...dup];
  });
  if (dupes.length) record('assert', bp.name, 'duplicate ids: ' + dupes.join(', '));

  await page.screenshot({ path: path.join(outDir, `${bp.name}-season.png`), fullPage: true });

  // --- interactions ------------------------------------------------------
  try {
    // focus a module, then release
    await page.locator('.cell:not([hidden])').first().click({ position: { x: 5, y: 5 } });
    await page.waitForTimeout(400);
    if (await page.locator('#grid.focused').count() === 0) {
      record('assert', bp.name, 'clicking a cell did not enter focus mode');
    }
    await page.screenshot({ path: path.join(outDir, `${bp.name}-focus.png`), fullPage: true });
    await page.keyboard.press('Escape');
    await page.waitForTimeout(300);
    if (await page.locator('#grid.focused').count() !== 0) {
      record('assert', bp.name, 'Escape did not exit focus mode');
    }
    interactions++;

    // buy stars -> should not throw, should toast
    const buy = page.locator('#buy');
    if (await buy.count()) {
      await buy.click();
      await page.waitForTimeout(300);
      if (await page.locator('.toast').count() === 0) {
        record('assert', bp.name, 'buy produced no toast feedback');
      }
      interactions++;
    }

    // open + close each overlay
    for (const key of ['ranks', 'cosmetics', 'chat', 'profile']) {
      const btn = page.locator(`[data-open="${key}"]`);
      if (!(await btn.count())) continue;
      await btn.click();
      await page.waitForTimeout(260);
      const open = await page.locator(`#ov-${key}[open]`).count();
      if (!open) { record('assert', bp.name, `overlay ${key} did not open`); continue; }
      await page.keyboard.press('Escape');
      await page.waitForTimeout(220);
      interactions++;
    }

    // context reflow
    for (const ctxName of ['lobby', 'blackout', 'season']) {
      const b = page.locator(`button[data-ctx="${ctxName}"]`).first();
      if (!(await b.count())) continue;
      await b.click();
      await page.waitForTimeout(500);
      if (await page.locator('.cell:not([hidden])').count() === 0) {
        record('assert', bp.name, `context ${ctxName} rendered no cells`);
      }
      if (ctxName === 'lobby') {
        await page.screenshot({ path: path.join(outDir, `${bp.name}-lobby.png`), fullPage: true });
      }
      if (ctxName === 'blackout') {
        await page.screenshot({ path: path.join(outDir, `${bp.name}-blackout.png`), fullPage: true });
      }
      interactions++;
    }
  } catch (e) {
    record('interaction', bp.name, e.message);
  }

  console.log(`  ${bp.name.padEnd(8)} ${cells} cells, overflow ${overflow}px`);
  await ctx.close();
}

// --- reduced motion pass -------------------------------------------------
{
  const ctx = await browser.newContext({
    viewport: { width: 1440, height: 900 },
    reducedMotion: 'reduce',
  });
  const page = await ctx.newPage();
  page.on('pageerror', (e) => record('pageerror', 'reduced-motion', e.message));
  page.on('console', (m) => { if (m.type() === 'error') record('console.error', 'reduced-motion', m.text()); });
  await page.goto(url, { waitUntil: 'load' });
  await page.waitForTimeout(900);
  const motion = await page.evaluate(() => document.documentElement.getAttribute('data-motion'));
  if (motion !== 'off') {
    record('a11y', 'reduced-motion', `prefers-reduced-motion not honoured (data-motion="${motion}")`);
  }
  // cells must still be visible, not stuck at opacity 0
  const invisible = await page.evaluate(() =>
    [...document.querySelectorAll('.cell:not([hidden])')]
      .filter((c) => parseFloat(getComputedStyle(c).opacity) < 0.5).length
  );
  if (invisible > 0) {
    record('a11y', 'reduced-motion', `${invisible} cell(s) stuck invisible with motion disabled`);
  }
  await page.screenshot({ path: path.join(outDir, 'reduced-motion.png'), fullPage: true });
  console.log(`  reduced  data-motion="${motion}", ${invisible} invisible cells`);
  await ctx.close();
}

await browser.close();

console.log('-'.repeat(64));
console.log(`interactions exercised: ${interactions}`);
console.log(`screenshots: ${outDir}`);
if (problems.length === 0) {
  console.log('Result: PASS - no console errors, no layout overflow, no failed interactions.');
  process.exit(0);
}
console.log(`Result: FAIL - ${problems.length} problem(s)`);
process.exit(1);
