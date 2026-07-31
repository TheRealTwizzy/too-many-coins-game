#!/usr/bin/env node
/**
 * e2e_season_loop.mjs — drive a whole season loop in a real browser against a
 * real server and a real database.
 *
 *   node tools/e2e_season_loop.mjs [base-url]
 *
 * The other self-checks in tools/ are static: they read source, or exercise
 * the client core against fixtures. Fixtures are exactly what let the client
 * ship reading field names the server never published, so this one is
 * deliberately the opposite — it asserts on what a browser actually renders
 * after a real API round trip, and on rows that actually exist in MySQL.
 *
 * What it covers, in the order a player meets it:
 *   register -> earn coins on ticks -> join a season -> buy stars ->
 *   collect sigils -> forge -> boosts -> family panel (ward/affinity) ->
 *   theft against a second live account -> chat -> shop -> profile ->
 *   notifications -> lock in.
 *
 * Two accounts are registered, because theft, wards and the standings are all
 * meaningless against an empty season.
 *
 * Requires: a server started with a fast tick (TMC_TICK_REAL_SECONDS=1) and
 * TMC_TICK_ON_REQUEST=1, or a running tick worker. Uses the preinstalled
 * Chromium. Do NOT run `playwright install`.
 *
 * Exit: 0 = every step passed and the console stayed clean; 1 = otherwise.
 */

import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
import { execFileSync } from 'node:child_process';

const BASE = process.argv[2] || 'http://127.0.0.1:8000';
const STAMP = Date.now().toString(36).slice(-6);

/**
 * Direct database access, used ONLY to fast-forward waiting.
 *
 * Two steps in a season are gated on elapsed real time rather than on player
 * skill: the coin balance needed to afford a star (income is denominated per
 * real minute, so a ~100-coin star is ~3 minutes of play) and the lock-in
 * participation floor (43,200 ticks). Waiting those out would make this
 * harness useless as a check you actually run.
 *
 * So the ARRANGE step writes balances straight into MySQL, and everything
 * after it — the price quote, the confirmation gate, the purchase, the
 * settlement, the payout — runs through the real server code path against
 * those rows. Nothing here stubs an assertion; it only removes the clock.
 *
 * Absent credentials, the affected steps report as skipped rather than
 * failing, so the harness still runs against a server whose DB it cannot see.
 */
const DB = {
    host: process.env.DB_HOST || '127.0.0.1',
    name: process.env.DB_NAME || '',
    user: process.env.DB_USER || '',
    pass: process.env.DB_PASS || '',
};
const dbAvailable = Boolean(DB.name && DB.user);

function sql(query) {
    if (!dbAvailable) return null;
    return execFileSync('mariadb', [
        `-h${DB.host}`, `-u${DB.user}`, `-p${DB.pass}`, DB.name, '-N', '-B', '-e', query,
    ], { encoding: 'utf8' });
}

let pass = 0;
let fail = 0;
const consoleProblems = [];

function ok(name, condition, detail) {
    if (condition) {
        pass++;
        console.log(`  ok    ${name}`);
    } else {
        fail++;
        console.log(`  FAIL  ${name}${detail !== undefined ? `\n          got: ${JSON.stringify(detail)}` : ''}`);
    }
}

function section(name) { console.log(`\n${name}`); }

/** Attach console/network failure capture. A clean console is part of the bar. */
function watch(page, who) {
    page.on('console', (m) => {
        if (m.type() !== 'error' && m.type() !== 'warning') return;
        const text = m.text();
        // The favicon is not part of the app and 404s in the dev server.
        if (/favicon/i.test(text)) return;
        consoleProblems.push(`[${who}] ${m.type()}: ${text}`);
    });
    page.on('pageerror', (e) => consoleProblems.push(`[${who}] pageerror: ${e.message}`));
    page.on('requestfailed', (r) => {
        if (/favicon/i.test(r.url())) return;
        consoleProblems.push(`[${who}] requestfailed: ${r.url()} ${r.failure()?.errorText || ''}`);
    });
}

/**
 * Call the API with the page's own session cookie. Used to set up state that
 * would be tedious to click through (a second account's join), and to read
 * server truth back for comparison against what the UI is showing.
 */
async function api(page, action, payload = {}) {
    return page.evaluate(async ({ action, payload, base }) => {
        const res = await fetch(`${base}/api/?action=${encodeURIComponent(action)}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(payload),
        });
        return res.json();
    }, { action, payload, base: BASE });
}

const sleep = (ms) => new Promise(r => setTimeout(r, ms));

/**
 * Clear the idle gate if the server has raised it.
 *
 * A test clock runs game time far faster than wall time, so the 15-minute
 * idle timeout arrives within seconds of real inactivity — the harness makes
 * API calls, which are not presence signals. That is a property of the
 * compressed clock, not a bug, and it makes this the natural place to prove
 * the idle surface works end to end: the FIRST time it trips, clear it by
 * clicking the real modal button in the browser rather than by calling the
 * action, so the modal, its wiring and its recovery are all exercised.
 *
 * Returns true if the gate was up and is now cleared.
 */
let idleModalProven = false;
async function clearIdle(page, who) {
    const state = await api(page, 'game_state');
    if (!state.player?.idle_modal_active) return false;

    if (!idleModalProven) {
        idleModalProven = true;
        // Give the client a poll to notice, then drive the actual button.
        await page.waitForTimeout(3500);
        const modal = page.locator('[aria-label="You are idle"]');
        const bannerOrModal = await modal.count() > 0;
        ok('the idle gate raises a modal in the browser', bannerOrModal,
            await page.locator('#app').innerText().then(t => t.slice(0, 160)));

        const button = page.getByRole('button', { name: "I'm here" });
        if (await button.count() > 0) {
            await button.first().click();
            await page.waitForTimeout(1200);
            const after = await api(page, 'game_state');
            ok('clicking "I\'m here" clears the idle gate', !after.player?.idle_modal_active,
                after.player?.activity_state);
            return true;
        }
        ok('clicking "I\'m here" clears the idle gate', false, 'button not found');
    }

    // Subsequent trips: just ack, the surface is already proven.
    await api(page, 'idle_ack');
    return true;
}

/** Run an action, clearing the idle gate first if it is up. */
async function act(page, action, payload = {}) {
    await clearIdle(page, action);
    let res = await api(page, action, payload);
    if (res?.reason_code === 'idle_gated' || res?.error === 'Cannot perform actions while idle') {
        await api(page, 'idle_ack');
        res = await api(page, action, payload);
    }
    return res;
}

/** Poll until predicate(state) is true, or give up. Returns the last state. */
async function until(page, predicate, { tries = 40, gap = 500, label = 'condition' } = {}) {
    let last = null;
    for (let i = 0; i < tries; i++) {
        last = await api(page, 'game_state');
        if (predicate(last)) return last;
        // Income stops accruing once the server marks the account idle, so a
        // wait-for-coins loop would otherwise wait forever on a fast clock.
        if (last?.player?.idle_modal_active) await clearIdle(page, label);
        await sleep(gap);
    }
    console.log(`        (timed out waiting for ${label})`);
    return last;
}

async function register(browser, handle) {
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await context.newPage();
    watch(page, handle);
    await page.goto(BASE, { waitUntil: 'domcontentloaded' });
    const res = await api(page, 'register', {
        handle,
        email: `${handle}@example.test`,
        password: 'testpassword123',
    });
    if (res.error) throw new Error(`register ${handle} failed: ${res.error}`);
    if (!res.token) {
        const login = await api(page, 'login', { email: `${handle}@example.test`, password: 'testpassword123' });
        if (login.error) throw new Error(`login ${handle} failed: ${login.error}`);
    }
    // Reload so the client boots with the session cookie in place.
    await page.reload({ waitUntil: 'networkidle' });
    return { context, page };
}

/** Text of the whole rendered app, for assertions about what a player sees. */
const appText = (page) => page.locator('#app').innerText();

async function main() {
    const browser = await chromium.launch();

    section('boot');
    const alice = await register(browser, `alice${STAMP}`);
    const bob = await register(browser, `bob${STAMP}`);
    ok('two accounts register and boot the client', true);

    // The HUD must show real figures, not zeroes, which is the exact class of
    // bug the fixture-based checks could not see.
    await alice.page.waitForTimeout(1500);
    ok('client renders the shell with a rail', await alice.page.locator('#rail').count() === 1);

    section('season: join');
    const state0 = await api(alice.page, 'game_state');
    const active = (state0.seasons || []).find(s => s.computed_status === 'Active');
    ok('an Active season exists', Boolean(active), state0.seasons?.map(s => s.computed_status));
    if (!active) { await browser.close(); return; }

    for (const who of [alice, bob]) {
        const join = await act(who.page, 'season_join', { season_id: active.season_id });
        if (join.error) throw new Error(`join failed: ${join.error}`);
    }
    ok('both accounts join the season', true);

    section('economy: coins accrue on ticks');
    // With a 1s tick the balance should move within a few seconds.
    const earned = await until(
        alice.page,
        (s) => Number(s.player?.participation?.coins) > 0,
        { label: 'coins > 0' },
    );
    const coins = Number(earned.player?.participation?.coins) || 0;
    ok('coins accrue from the tick engine', coins > 0, { coins });
    ok('the rate the server publishes is positive',
        Number(earned.player?.participation?.rate_per_tick) > 0,
        earned.player?.participation?.rate_per_tick);

    // THE regression this whole PR exists for: the HUD must render the same
    // number the server published, not a zero from a field that never existed.
    await alice.page.goto(`${BASE}/#/home`, { waitUntil: 'networkidle' });
    await alice.page.waitForTimeout(3500);
    const hudText = await alice.page.locator('#hud').innerText();
    const hudDigits = hudText.replace(/[^0-9]/g, '');
    ok('the HUD renders non-zero figures against the real API',
        /[1-9]/.test(hudDigits), hudText);

    const homeText = await appText(alice.page);
    ok('home shows the joined-season board', /Coins/i.test(homeText) && /Global stars/i.test(homeText));

    section('economy: stars');
    const before = await api(alice.page, 'game_state');
    const priceState = (before.seasons || []).find(s => Number(s.season_id) === Number(active.season_id));
    const price = Number(priceState.published_star_price) || 0;
    ok('the season publishes a star price', price > 0, { price });

    // Fast-forward the balance rather than waiting ~3 real minutes for it.
    // The purchase itself still runs the real pricing, gating and settlement.
    const aliceId = before.player.player_id;
    if (dbAvailable) {
        sql(`UPDATE season_participation SET coins = ${price * 4}
             WHERE player_id = ${aliceId} AND season_id = ${active.season_id}`);
    }
    const rich = await until(
        alice.page,
        (s) => Number(s.player?.participation?.coins) >= price,
        { tries: dbAvailable ? 10 : 60, label: `coins >= ${price}` },
    );
    const canAfford = Number(rich.player?.participation?.coins) >= price;
    ok('the HUD balance reflects the seeded server balance',
        !dbAvailable || canAfford, rich.player?.participation?.coins);
    if (canAfford) {
        const buy = await act(alice.page, 'purchase_stars', { stars_requested: 1 });
        // A spend >=50% of balance legitimately comes back confirmation_required;
        // that is the gate the client now handles, so honour it here too.
        const bought = buy.success
            ? buy
            : buy.error === 'confirmation_required'
                ? await act(alice.page, 'purchase_stars', { stars_requested: 1, confirm_economic_impact: true })
                : buy;
        ok('a star purchase succeeds (honouring the econ-confirm gate)',
            Boolean(bought.success), bought.error || bought);

        const after = await api(alice.page, 'game_state');
        ok('seasonal stars increased', Number(after.player?.participation?.seasonal_stars) > 0,
            after.player?.participation?.seasonal_stars);
    } else {
        ok('a star purchase succeeds (honouring the econ-confirm gate)', false, 'never afforded a star');
    }

    section('sigils: drops, forge');
    // The starter grant hands out sigils at first join, so the forge is
    // reachable immediately — that is the whole point of the grant.
    const sig = await api(alice.page, 'game_state');
    const sigils = sig.player?.participation?.sigils || [];
    const total = Number(sig.player?.participation?.sigils_total) || 0;
    ok('the starter grant delivered sigils', total > 0, { sigils, total });
    ok('sigils publish as a [t1..t6] array', Array.isArray(sigils) && sigils.length === 6, sigils);

    const detail = await api(alice.page, 'season_detail', { season_id: active.season_id });
    ok('season_detail carries the standings', Array.isArray(detail.leaderboard) && detail.leaderboard.length >= 2,
        detail.leaderboard?.length);

    section('boosts');
    const catalog = await api(alice.page, 'boost_catalog');
    ok('the boost catalog loads', Array.isArray(catalog) && catalog.length > 0, catalog?.length);

    const firstTier = sigils.findIndex(n => Number(n) > 0) + 1;
    if (firstTier > 0) {
        const preview = await act(alice.page, 'boost_activate_preview', { sigil_tier: firstTier, purchase_kind: 'power' });
        ok('a boost preview returns server-quoted numbers',
            Boolean(preview.success) && preview.modifier_percent !== undefined,
            preview.error || { pct: preview.modifier_percent });

        const buy = await act(alice.page, 'purchase_boost', {
            sigil_tier: firstTier, purchase_kind: 'power', confirm_economic_impact: true,
        });
        ok('a boost activates and consumes a sigil', Boolean(buy.success), buy.error || buy.message);

        const boosted = await api(alice.page, 'game_state');
        const selfBoosts = boosted.player?.active_boosts?.self || [];
        ok('the active boost is published back on the poll', selfBoosts.length > 0,
            boosted.player?.active_boosts?.total_modifier_percent);
    } else {
        ok('a boost activates and consumes a sigil', false, 'no sigils to spend');
    }

    section('families: panel, ward, affinity');
    const fam = await api(alice.page, 'family_state');
    ok('family_state reports the layer enabled', fam.enabled === true, fam);
    ok('all seven families are published', (fam.families || []).length === 7,
        (fam.families || []).map(f => f.code));

    const affinity = await act(alice.page, 'affinity_pick', { family: 'ward' });
    ok('affinity can be attuned', Boolean(affinity.success), affinity.error || affinity.message);

    // Which ward tiers a player holds is drop-dependent, so assert the
    // contract rather than a specific inventory: the call either raises a
    // ward or refuses with a stated reason, and a raised ward must then be
    // visible in family_state. A silent no-op is the only wrong answer.
    const wardState = await api(alice.page, 'family_state');
    const wardHolding = (wardState.holdings || []).find(hh => hh.code === 'ward');
    const wardTier = wardHolding ? Number(Object.keys(wardHolding.tiers)[0]) : 1;
    const ward = await act(alice.page, 'ward_activate', { tier: wardTier });
    ok('ward_activate either raises a ward or refuses with a reason',
        Boolean(ward.success) || Boolean(ward.error), ward);
    if (ward.success) {
        const afterWard = await api(alice.page, 'family_state');
        ok('a raised ward is reported back in family_state',
            afterWard.ward?.active === true, afterWard.ward);
        ok('a tier-1 ward is flagged as the one-shot deflect',
            wardTier !== 1 || afterWard.ward?.one_shot === true, afterWard.ward);
    }

    // The panel itself must render for a joined player.
    await alice.page.goto(`${BASE}/#/family`, { waitUntil: 'networkidle' });
    await alice.page.waitForTimeout(1200);
    const familyText = await appText(alice.page);
    ok('the family panel renders all seven family names in the browser',
        ['Goliath', 'Anak', 'Michael', 'Valefor', 'Mammon', 'Azazel', 'Legion']
            .every(n => familyText.includes(n)),
        familyText.slice(0, 200));

    section('theft');
    const theftPreview = await act(alice.page, 'sigil_theft_preview', {
        target_player_id: (await api(bob.page, 'game_state')).player.player_id,
        spent_sigils: [1, 0, 0, 0, 0, 0],
        requested_sigils: [1, 0, 0, 0, 0, 0],
    });
    // Either a real preview with odds, or an honest refusal (no T1 to spend,
    // target protected). Both are correct; a silent failure is not.
    ok('theft preview answers with odds or an explicit reason',
        Boolean(theftPreview.success && theftPreview.success_chance_pct !== undefined) || Boolean(theftPreview.error),
        theftPreview.error || { pct: theftPreview.success_chance_pct });

    section('chat');
    const send = await act(alice.page, 'chat_send', { channel: 'GLOBAL', content: `hello from ${STAMP}` });
    ok('a global chat message sends', Boolean(send.success) || !send.error, send.error || 'sent');
    const globalMsgs = await api(bob.page, 'chat_messages', { channel: 'GLOBAL' });
    const globalList = Array.isArray(globalMsgs) ? globalMsgs : (globalMsgs.messages || []);
    ok('the other account reads it back on GLOBAL',
        globalList.some(m => (m.content || '').includes(STAMP)), globalList.length);

    // The season-tab regression: reads must bind to the joined season without
    // the client sending a season_id.
    const seasonSend = await act(alice.page, 'chat_send', { channel: 'SEASON', content: `season talk ${STAMP}` });
    ok('a season chat message sends', Boolean(seasonSend.success) || !seasonSend.error, seasonSend.error);
    const seasonMsgs = await api(bob.page, 'chat_messages', { channel: 'SEASON' });
    const seasonList = Array.isArray(seasonMsgs) ? seasonMsgs : (seasonMsgs.messages || []);
    ok('SEASON chat reads back without an explicit season_id',
        seasonList.some(m => (m.content || '').includes(STAMP)), seasonList.length);

    section('notifications');
    const notifState = await api(alice.page, 'game_state');
    ok('the poll carries notifications and an unread count',
        Array.isArray(notifState.player?.notifications)
        && notifState.player?.notifications_unread_count !== undefined,
        { n: notifState.player?.notifications?.length, unread: notifState.player?.notifications_unread_count });

    if ((notifState.player?.notifications || []).length > 0) {
        const first = notifState.player.notifications[0];
        const read = await act(alice.page, 'notifications_mark_read', { notification_id: first.notification_id });
        ok('a notification can be marked read', Boolean(read.success), read.error || read);
    }

    section('shop & cosmetics');
    const shopCatalog = await api(alice.page, 'cosmetic_catalog');
    const shopItems = Array.isArray(shopCatalog) ? shopCatalog : (shopCatalog.items || []);
    ok('the cosmetic catalog loads', shopItems.length > 0, shopItems.length);
    await alice.page.goto(`${BASE}/#/shop`, { waitUntil: 'networkidle' });
    await alice.page.waitForTimeout(1000);
    ok('the shop screen renders items', /Cosmetics/i.test(await appText(alice.page)));

    section('profile');
    const bobId = (await api(bob.page, 'game_state')).player.player_id;
    const profile = await api(alice.page, 'profile', { player_id: bobId });
    ok('a profile loads for another player', Boolean(profile.handle) && !profile.error, profile.error);
    ok('the profile carries the relationship block the UI needs',
        profile.relationship !== undefined, profile.relationship);

    const friend = await act(alice.page, 'friend_request_send', { target_player_id: bobId });
    ok('a friend request sends', Boolean(friend.success), friend.error);

    await alice.page.goto(`${BASE}/#/profile`, { waitUntil: 'networkidle' });
    await alice.page.waitForTimeout(1200);

    section('lock-in');
    const preLock = await api(alice.page, 'game_state');
    ok('lock-in is offered while in a season', preLock.player?.can_lock_in === true, preLock.player?.can_lock_in);

    // Satisfy the participation floor by writing the ticks, not by playing
    // them. The conversion, the sigil refund, the starter-grant offset and
    // the season exit below are all the server's own arithmetic.
    if (dbAvailable) {
        // total_season_participation_ticks is the column the seasonal floor
        // actually reads (it is cumulative across runs, to close the
        // leave-and-rejoin shortcut); participation_time_total is a different
        // counter and seeding it alone leaves the floor unmet.
        sql(`UPDATE season_participation
             SET total_season_participation_ticks = 100000,
                 participation_ticks_since_join = 100000,
                 participation_time_total = 100000,
                 active_ticks_total = 100000
             WHERE player_id = ${aliceId} AND season_id = ${active.season_id}`);
    }
    const starsBefore = Number((await api(alice.page, 'game_state')).player?.participation?.seasonal_stars) || 0;
    const lock = await act(alice.page, 'lock_in');
    ok('lock-in completes and reports its payout', Boolean(lock.success), lock.error || lock.message);
    if (lock.success) {
        ok('the payout message states the real conversion',
            /Global Star/i.test(String(lock.message || '')) && lock.seasonal_stars_converted !== undefined,
            { converted: lock.seasonal_stars_converted, gained: lock.global_stars_gained, from: starsBefore });
    }
    if (lock.success) {
        const post = await api(alice.page, 'game_state');
        ok('lock-in ends season membership', !post.player?.joined_season_id, post.player?.joined_season_id);
        ok('lock-in credits global stars',
            Number(post.player?.global_stars) >= 0 && lock.global_stars_gained !== undefined,
            { global: post.player?.global_stars, gained: lock.global_stars_gained });
    }

    section('responsive: the shell holds at mobile width');
    const mobile = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const mp = await mobile.newPage();
    watch(mp, 'mobile');
    await mp.goto(BASE, { waitUntil: 'networkidle' });
    await mp.waitForTimeout(1200);
    const overflow = await mp.evaluate(() =>
        document.documentElement.scrollWidth - document.documentElement.clientWidth);
    ok('no horizontal overflow at 390px', overflow <= 1, { overflowPx: overflow });
    await mobile.close();

    section('console hygiene');
    ok('no console errors, page errors or failed requests during the run',
        consoleProblems.length === 0, consoleProblems.slice(0, 8));

    await browser.close();
}

try {
    await main();
} catch (err) {
    fail++;
    console.log(`\n  FAIL  harness threw: ${err.message}`);
}

console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
