#!/usr/bin/env node
/**
 * maintenance_gate_selfcheck.mjs — drive the maintenance lockdown gate for
 * real, against a running server and database.
 *
 *   node tools/maintenance_gate_selfcheck.mjs [base-url]
 *
 * The gate decides who may play while the game is closed, so its failures are
 * of two kinds and this covers both:
 *
 *   Too open — someone the gate should stop gets through, or the construction
 *   page leaks the app around itself. The auth escape did exactly that: routing
 *   to the sign-in screen fell through to the full shell, rendering rail, HUD
 *   and every screen to a player the gate was meant to be showing a
 *   construction page.
 *
 *   Too closed — nobody can test a gated build at all. Staff pass the gate but
 *   cannot join a season (seasonJoin refuses any role but Player, so standings
 *   never contain a moderator), which left play-testing impossible while the
 *   gate was up. maintenance_access exists for that, and this asserts it works
 *   without turning a tester into staff.
 *
 * Flips the real gate via tools/admin.php and restores it on the way out.
 * Uses the preinstalled Chromium. Do NOT run `playwright install`.
 *
 * Needs DB_* in the environment (for tools/admin.php) and a running server.
 * Clean up accounts afterwards with:
 *   php tools/purge_test_accounts.php --handle=gate* --apply
 *
 * Exit: 0 = every check passed, 1 = at least one is wrong.
 */

import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
import { execFileSync } from 'node:child_process';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = join(HERE, '..');
const BASE = process.argv[2] || 'http://127.0.0.1:8000';
const STAMP = Date.now().toString(36).slice(-6);

let pass = 0, fail = 0;
const ok = (name, cond, detail) => {
    if (cond) { pass++; console.log(`  ok    ${name}`); }
    else { fail++; console.log(`  FAIL  ${name}${detail ? ' -- ' + detail : ''}`); }
};
const section = (name) => console.log(`\n${name}`);

const admin = (...args) =>
    execFileSync('php', [join(ROOT, 'tools', 'admin.php'), ...args], { cwd: ROOT }).toString();

const api = (page, action, body) => page.evaluate(async ([a, b, base]) => {
    const r = await fetch(`${base}/api/index.php?action=${a}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify(b),
    });
    return { status: r.status, body: await r.json() };
}, [action, body, BASE]);

const visible = (page, sel) => page.locator(sel).first().isVisible().catch(() => false);

async function session(browser) {
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await context.newPage();
    await page.goto(BASE, { waitUntil: 'domcontentloaded' });
    return page;
}

const browser = await chromium.launch();

try {
    admin('gate', 'off');

    section('the gate closes on a signed-in non-staff player');
    const playerHandle = `gateui${STAMP}`;
    const uiPage = await session(browser);
    const uiErrors = [];
    uiPage.on('console', (m) => { if (m.type() === 'error') uiErrors.push(m.text()); });
    uiPage.on('pageerror', (e) => uiErrors.push(String(e)));

    const reg = await api(uiPage, 'register', {
        handle: playerHandle, email: `${playerHandle}@example.test`, password: 'testpassword123',
    });
    ok('a plain account registers', !reg.body.error, JSON.stringify(reg.body));
    // Registration alone no longer grants access. This file is about the
    // maintenance gate, so the confirmation gate is cleared out of the way -
    // otherwise every check below would pass against the wrong refusal.
    admin('verify', playerHandle);
    await uiPage.reload({ waitUntil: 'networkidle' });
    await uiPage.waitForTimeout(1500);

    admin('gate', 'on');
    await uiPage.waitForTimeout(6000); // the client polls every 3s and flips itself

    ok('a signed-in non-staff player lands on the construction page',
        await visible(uiPage, '.construction-shell'));
    ok('no rail renders behind the gate', !(await visible(uiPage, '.rail')));

    section('the staff sign-in escape reaches sign-in, and only sign-in');
    await uiPage.click('button:has-text("Staff sign-in")');
    await uiPage.waitForTimeout(3500);

    const deadEnd = (await uiPage.locator('#app').innerText()).includes('Already signed in');
    ok('it reaches an actual login form', await visible(uiPage, '#login-email'), `deadEnd=${deadEnd}`);
    ok('it does not dead-end on "Already signed in"', !deadEnd);
    ok('it renders no rail', !(await visible(uiPage, '.rail')));
    ok('it renders no HUD', !(await visible(uiPage, '#hud')));
    ok('it does not route to the season screen', !(await visible(uiPage, '[data-screen="season"]')));
    ok('it offers a way back to the construction page',
        await visible(uiPage, 'button:has-text("Back to the construction page")'));

    await uiPage.click('button:has-text("Back to the construction page")');
    await uiPage.waitForTimeout(2000);
    ok('the way back works', await visible(uiPage, '.construction-shell'));
    ok('the gate flow logs no console errors', uiErrors.length === 0, uiErrors.slice(0, 3).join(' | '));

    section('who may act while the gate is up');
    admin('gate', 'off');

    const testerHandle = `gatetst${STAMP}`.slice(0, 16);
    const created = admin('tester', 'create', testerHandle);
    const password = (created.match(/password:\s*(\S+)/) || [])[1];
    ok('tester create prints a usable password', Boolean(password));

    const plainHandle = `gatepln${STAMP}`.slice(0, 16);
    const plainPage = await session(browser);
    await api(plainPage, 'register', {
        handle: plainHandle, email: `${plainHandle}@example.test`, password: 'testpassword123',
    });
    admin('verify', plainHandle);

    const staffHandle = `gatestf${STAMP}`.slice(0, 16);
    const staffPage = await session(browser);
    await api(staffPage, 'register', {
        handle: staffHandle, email: `${staffHandle}@example.test`, password: 'testpassword123',
    });
    admin('verify', staffHandle);
    admin('promote', staffHandle, 'Moderator');

    const testerPage = await session(browser);
    const login = await api(testerPage, 'login', {
        email: `${testerHandle.toLowerCase()}@playtest.invalid`, password,
    });
    // Pre-verified matters: a gated build is exactly when SMTP may not be
    // configured, so an account needing an email round trip would be useless.
    ok('the generated password signs the tester in without email verification',
        !login.body.error, JSON.stringify(login.body));

    admin('gate', 'on');

    const state = await api(testerPage, 'game_state', {});
    const season = (state.body.seasons || []).find((s) => s.status === 'Active');
    ok('an active season exists to test against', Boolean(season));
    const seasonId = season && season.season_id;

    const testerJoin = await api(testerPage, 'season_join', { season_id: seasonId });
    ok('a tester is not blocked by the gate', testerJoin.status !== 503, `status=${testerJoin.status}`);
    ok('a tester can join a season', !testerJoin.body.error, JSON.stringify(testerJoin.body));

    const plainJoin = await api(plainPage, 'season_join', { season_id: seasonId });
    ok('an ordinary account is still blocked', plainJoin.status === 503, `status=${plainJoin.status}`);
    ok('and is told why', plainJoin.body.reason_code === 'maintenance_lockdown');

    const staffJoin = await api(staffPage, 'season_join', { season_id: seasonId });
    ok('staff pass the gate but still cannot join — the gap testers fill',
        staffJoin.status !== 503 && staffJoin.body.reason_code === 'staff_participation_forbidden',
        `status=${staffJoin.status} ${JSON.stringify(staffJoin.body)}`);

    admin('tester', 'revoke', testerHandle);
    const afterRevoke = await api(testerPage, 'star_purchase_preview', { season_id: seasonId, quantity: 1 });
    ok('revoking gate access takes effect immediately', afterRevoke.status === 503,
        `status=${afterRevoke.status}`);
} catch (err) {
    ok('harness completed', false, String(err && err.message || err));
} finally {
    try { admin('gate', 'off'); } catch { /* reported below by the gate check itself */ }
    await browser.close();
}

console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail === 0 ? 0 : 1);
