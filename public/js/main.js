/**
 * main.js — entry point for the ?ui=next client
 *
 * Stage 1 scope: the foundation and a shell that proves it works end to end.
 * The rail, the HUD and the live poll are real; the screens themselves arrive
 * in Stage 2 and mount into #deck.
 *
 * The legacy client is not touched by any of this. index.html loads exactly
 * one of the two.
 */

import { createStore } from './core/store.js';
import { createApi } from './core/api.js';
import { createClock } from './core/clock.js';
import { createMotion } from './core/motion.js';
import { h, render } from './core/render.js';

const POLL_MS = 3000;
const THEMES = ['nocturne', 'gilded', 'ember', 'tide'];

/* ------------------------------------------------------------------ *
 * services
 * ------------------------------------------------------------------ */

const store = createStore({
    player: null,
    seasons: [],
    timing: null,
    screen: 'home',
    connection: 'connecting', // connecting | live | retrying
});

const clock = createClock();
const motion = createMotion();

const api = createApi({
    onUnauthorized() {
        store.patch({ player: null, screen: 'auth' });
    },
    onRateLimit({ retryInMs }) {
        store.set('connection', 'retrying');
        // Reflect recovery when the backoff window closes.
        setTimeout(() => {
            if (!api.isBackingOff('poll')) store.set('connection', 'live');
        }, retryInMs + 50);
    },
});

/**
 * Presentation state that is not server truth: the number the HUD is currently
 * showing mid-count, which theme is active, and so on. Kept apart from `store`
 * so a poll can never clobber it.
 */
const view = {
    theme: readTheme(),
    displayed: new Map(), // field -> number currently on screen
    counting: new Map(),  // field -> cancel fn
};

/* ------------------------------------------------------------------ *
 * formatting
 * ------------------------------------------------------------------ */

const GROUPED = new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 });

function formatCount(value) {
    const n = Number(value) || 0;
    if (Math.abs(n) < 100000) return GROUPED.format(Math.round(n));
    if (Math.abs(n) < 1e6) return (n / 1e3).toFixed(1).replace(/\.0$/, '') + 'k';
    if (Math.abs(n) < 1e9) return (n / 1e6).toFixed(2).replace(/\.?0+$/, '') + 'M';
    return (n / 1e9).toFixed(2).replace(/\.?0+$/, '') + 'B';
}

function formatRate(value) {
    const n = Number(value) || 0;
    return (n >= 100 ? Math.round(n) : n.toFixed(1)) + '/tick';
}

/* ------------------------------------------------------------------ *
 * animated counters
 * ------------------------------------------------------------------ */

/**
 * Move a HUD figure toward its new value.
 *
 * The animated number lives in `view.displayed`, not in the store, so the
 * reconciler renders whatever the count has reached this frame and the server
 * value stays authoritative underneath.
 */
function animateField(field, next) {
    const from = view.displayed.has(field) ? view.displayed.get(field) : next;
    if (from === next) return;

    const cancelPrevious = view.counting.get(field);
    if (cancelPrevious) cancelPrevious();

    const cancel = motion.countTo(from, next, (value, done) => {
        view.displayed.set(field, value);
        if (done) view.counting.delete(field);
        scheduleRender();
    }, { duration: 'moment', easing: 'gain' });

    view.counting.set(field, cancel);
}

function displayedOr(field, fallback) {
    return view.displayed.has(field) ? view.displayed.get(field) : fallback;
}

/* ------------------------------------------------------------------ *
 * view
 * ------------------------------------------------------------------ */

const NAV = [
    { id: 'home', label: 'Home', glyph: '★' },
    { id: 'seasons', label: 'Seasons', glyph: '\u{1F3C6}' },
    { id: 'ranks', label: 'Ranks', glyph: '\u{1F3C5}' },
    { id: 'chat', label: 'Chat', glyph: '\u{1F4AC}' },
    { id: 'shop', label: 'Shop', glyph: '\u{1F48E}' },
];

function rail(screen) {
    return h('nav', { id: 'rail', 'aria-label': 'Primary' },
        NAV.map(item => h('button', {
            key: item.id,
            class: 'rail-btn' + (screen === item.id ? ' is-active' : ''),
            'aria-current': screen === item.id ? 'page' : false,
            onClick: () => store.set('screen', item.id),
        },
            h('span', { class: 'rail-glyph', 'aria-hidden': 'true' }, item.glyph),
            h('span', { class: 'rail-label' }, item.label),
        )),
    );
}

function hudFigure(field, label, value, format) {
    return h('div', { key: field, class: 'hud-figure' },
        h('span', { class: 'hud-label' }, label),
        h('span', { class: 'hud-value' }, format(value)),
    );
}

function hud(player) {
    if (!player) return null;

    return h('div', { id: 'hud', role: 'status', 'aria-live': 'off' },
        hudFigure('coins', 'Coins', displayedOr('coins', player.coins || 0), formatCount),
        hudFigure('stars', 'Stars', displayedOr('stars', player.seasonal_stars || 0), formatCount),
        hudFigure('sigils', 'Sigils', displayedOr('sigils', player.sigils || 0), formatCount),
        hudFigure('rate', 'Rate', displayedOr('rate', player.ubi_rate || 0), formatRate),
        tickIndicator(),
    );
}

/**
 * The tick indicator deliberately shows one of two different things.
 *
 * With phase known it is a real countdown to the next payout. Without it, the
 * client knows the period but not where in the period it is, so it shows the
 * cadence as a static fact instead of a countdown that would hit zero at the
 * wrong moment. Stage 2 adds last_tick_at and this becomes a countdown for
 * everyone; nothing here needs to change when it does.
 */
function tickIndicator() {
    const seconds = clock.secondsToNextTick();
    const timing = store.get('timing');
    const period = timing && Number(timing.tick_real_seconds);

    if (seconds === null) {
        if (!period) return null;
        return h('div', { key: 'tick', class: 'hud-figure hud-tick is-cadence' },
            h('span', { class: 'hud-label' }, 'Payout'),
            h('span', { class: 'hud-value' }, `every ${period}s`),
        );
    }

    return h('div', { key: 'tick', class: 'hud-figure hud-tick' },
        h('span', { class: 'hud-label' }, 'Next payout'),
        h('span', { class: 'hud-value' }, `${Math.ceil(seconds)}s`),
    );
}

function connectionNote(state) {
    if (state !== 'retrying') return null;
    return h('div', { class: 'conn-note', role: 'status' }, 'Reconnecting…');
}

function themeSwitch() {
    return h('div', { class: 'theme-switch' },
        THEMES.map(name => h('button', {
            key: name,
            class: 'theme-dot' + (view.theme === name ? ' is-active' : ''),
            'data-theme-name': name,
            title: name,
            'aria-label': `${name} theme`,
            onClick: () => setTheme(name),
        })),
    );
}

function shell() {
    const screen = store.get('screen');
    return h('div', { id: 'shell' },
        rail(screen),
        h('div', { id: 'stage' },
            hud(store.get('player')),
            connectionNote(store.get('connection')),
            h('main', { id: 'deck', 'data-screen': screen },
                // Stage 2 mounts screen modules here.
                h('p', { class: 'deck-placeholder' },
                    'Foundation online. Screens land in the next stage.'),
            ),
            themeSwitch(),
        ),
        h('div', { id: 'dialog-host' }),
    );
}

/* ------------------------------------------------------------------ *
 * render scheduling
 * ------------------------------------------------------------------ */

let root = null;
let renderQueued = false;

function scheduleRender() {
    if (renderQueued) return;
    renderQueued = true;
    requestAnimationFrame(() => {
        renderQueued = false;
        render(shell(), root);
    });
}

/* ------------------------------------------------------------------ *
 * theme
 * ------------------------------------------------------------------ */

function readTheme() {
    try {
        const stored = localStorage.getItem('tmc_theme');
        if (stored && THEMES.includes(stored)) return stored;
    } catch {
        // Storage unavailable; the default theme is a fine answer.
    }
    return 'nocturne';
}

function setTheme(name) {
    if (!THEMES.includes(name)) return;
    view.theme = name;
    document.documentElement.setAttribute('data-theme', name);
    motion.invalidateTokens();
    try {
        localStorage.setItem('tmc_theme', name);
    } catch {
        // Not worth failing a theme change over.
    }
    scheduleRender();
}

/* ------------------------------------------------------------------ *
 * data
 * ------------------------------------------------------------------ */

async function poll() {
    const gs = await api.request('game_state', {}, {
        channel: 'poll',
        dedupe: true,
        respectBackoff: true,
    });

    if (!gs || gs.skipped) return;
    if (gs.error) {
        store.set('connection', 'retrying');
        return;
    }

    store.patch({
        player: gs.player || null,
        seasons: gs.seasons || [],
        timing: gs.timing || null,
        connection: 'live',
    });

    // last_tick_at is not published yet. Passing it through as undefined keeps
    // the clock honest — it reports "phase unknown" rather than inventing one —
    // and this line starts working the moment Stage 2 adds the field.
    if (gs.timing) {
        clock.setTickPhase({
            periodSeconds: gs.timing.tick_real_seconds,
            lastTickAt: gs.timing.last_tick_at ?? null,
        });
    }
}

/* ------------------------------------------------------------------ *
 * boot
 * ------------------------------------------------------------------ */

function boot() {
    document.documentElement.setAttribute('data-theme', view.theme);

    // The legacy markup is inert here: index.html ships it for the legacy
    // client, and this client owns the container instead.
    root = document.getElementById('app');
    if (!root) {
        console.error('[main] #app missing; cannot mount');
        return;
    }
    while (root.firstChild) root.removeChild(root.firstChild);

    // Any change to server truth redraws. The reconciler makes a redundant
    // redraw free, so there is no need to be clever about which paths matter.
    store.subscribe('*', scheduleRender);

    // Figures animate toward their new values rather than jumping.
    store.subscribe('player.coins', (next) => animateField('coins', Number(next) || 0));
    store.subscribe('player.seasonal_stars', (next) => animateField('stars', Number(next) || 0));
    store.subscribe('player.sigils', (next) => animateField('sigils', Number(next) || 0));
    store.subscribe('player.ubi_rate', (next) => animateField('rate', Number(next) || 0));

    // One clock: the poll, the countdown repaint, and the payout pulse.
    clock.every(POLL_MS, poll);
    clock.every(1000, scheduleRender);
    clock.onTick(() => {
        const el = document.getElementById('hud');
        if (el) motion.pulse(el, 'is-paid');
    });

    scheduleRender();
    poll();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}
