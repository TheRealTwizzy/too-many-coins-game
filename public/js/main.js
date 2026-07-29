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
import { createAssets } from './core/assets.js';
import { h, render } from './core/render.js';
import { getScreen } from './screens/index.js';

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

// Art is referenced by name, never by glyph or path. Everything below asks for
// 'nav-home' or 'coin'; what those look like is core/assets.js's business, so
// swapping placeholders for real art touches that file and nothing here.
const assets = createAssets({ h, motion });

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

/* ------------------------------------------------------------------ *
 * screen context
 *
 * The single object every screen is handed. Screens get capabilities, not
 * globals: they never import the store or the api directly, which keeps them
 * testable and means a screen cannot quietly start its own poll.
 *
 * Actions live here rather than inside screens because most of them touch
 * state two screens care about — buying a cosmetic changes the shop *and* the
 * HUD's star balance.
 * ------------------------------------------------------------------ */

const ctx = {
    h,
    store,
    assets,
    motion,
    clock,

    navigate(screenId) {
        store.set('screen', screenId);
    },

    async joinSeason(seasonId) {
        store.set('ui.joining', seasonId);
        const res = await api.request('season_join', { season_id: seasonId });
        store.set('ui.joining', null);
        if (res && res.error) return toast(res.error, 'error');
        // Refresh immediately rather than waiting up to 3s for the next poll —
        // joining is the one action where the whole screen changes meaning.
        await poll();
    },

    async loadLeaderboard(page = 1) {
        const res = await api.request('global_leaderboard', { page, per_page: 25 });
        if (!res || res.error) return;
        const entries = Array.isArray(res) ? res : (res.entries || res.leaderboard || []);
        store.set('screens.ranks', { entries, page });
    },

    async loadChat() {
        const channel = store.get('ui.chatChannel') || 'GLOBAL';
        const res = await api.request('chat_messages', { channel }, { channel: 'chat', dedupe: true, respectBackoff: true });
        if (!res || res.error || res.skipped) return;
        const messages = Array.isArray(res) ? res : (res.messages || []);
        store.set('screens.chat', { messages, channel });
    },

    switchChat(channel) {
        store.set('ui.chatChannel', channel);
        store.set('screens.chat', null);
        ctx.chatWasPinned = true;
        ctx.loadChat();
    },

    async sendChat(formEl) {
        const draft = String(store.get('ui.chatDraft') || '').trim();
        if (!draft) return;

        store.set('ui.chatSending', true);
        const res = await api.request('chat_send', {
            channel: store.get('ui.chatChannel') || 'GLOBAL',
            content: draft,
        });
        store.set('ui.chatSending', false);

        if (res && res.error) return toast(res.error, 'error');

        store.set('ui.chatDraft', '');

        // The reconciler will not write `value` to a focused text field — that
        // guard is what stops a poll eating a half-typed message. It also means
        // it cannot clear the box after a successful send, so that is done
        // explicitly here. Deliberate clears are the caller's job; accidental
        // ones are what the guard exists to prevent.
        const input = formEl && formEl.querySelector('input');
        if (input) input.value = '';

        ctx.chatWasPinned = true;
        await ctx.loadChat();
    },

    async loadShop() {
        const [catalog, mine] = await Promise.all([
            api.request('cosmetic_catalog'),
            api.request('my_cosmetics'),
        ]);
        if (!catalog || catalog.error) return;
        store.set('screens.shop', {
            catalog: Array.isArray(catalog) ? catalog : (catalog.items || []),
            owned: mine && !mine.error ? (Array.isArray(mine) ? mine : (mine.owned || [])) : [],
            equipped: mine && !mine.error ? (mine.equipped || {}) : {},
        });
    },

    async buyCosmetic(cosmeticId) {
        store.set('ui.shopBusy', cosmeticId);
        const res = await api.request('purchase_cosmetic', { cosmetic_id: cosmeticId });
        store.set('ui.shopBusy', null);
        if (res && res.error) return toast(res.error, 'error');
        await Promise.all([ctx.loadShop(), poll()]);
    },

    async equipCosmetic(cosmeticId) {
        store.set('ui.shopBusy', cosmeticId);
        const res = await api.request('equip_cosmetic', { cosmetic_id: cosmeticId });
        store.set('ui.shopBusy', null);
        if (res && res.error) return toast(res.error, 'error');
        await ctx.loadShop();
    },
};

/**
 * Transient message. Kept in the store so it renders through the reconciler
 * like everything else rather than being appended to the DOM behind its back.
 */
let toastTimer = null;
function toast(text, kind = 'info') {
    store.set('ui.toast', { text, kind });
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => store.set('ui.toast', null), 4000);
}

/* ------------------------------------------------------------------ *
 * screen lifecycle
 * ------------------------------------------------------------------ */

let activeScreenId = null;

async function activateScreen(id) {
    if (activeScreenId === id) return;

    const previous = getScreen(activeScreenId);
    if (previous && previous.leave) {
        try { previous.leave(ctx); } catch (err) { console.error('[main] leave failed:', err); }
    }

    activeScreenId = id;
    scheduleRender();

    const next = getScreen(id);
    if (next && next.enter) {
        try { await next.enter(ctx); } catch (err) { console.error('[main] enter failed:', err); }
        scheduleRender();
    }
}

const NAV = [
    { id: 'home', label: 'Home', icon: 'nav-home' },
    { id: 'seasons', label: 'Seasons', icon: 'nav-seasons' },
    { id: 'ranks', label: 'Ranks', icon: 'nav-ranks' },
    { id: 'chat', label: 'Chat', icon: 'nav-chat' },
    { id: 'shop', label: 'Shop', icon: 'nav-shop' },
];

function rail(screen) {
    return h('nav', { id: 'rail', 'aria-label': 'Primary' },
        NAV.map(item => h('button', {
            key: item.id,
            class: 'rail-btn' + (screen === item.id ? ' is-active' : ''),
            'aria-current': screen === item.id ? 'page' : false,
            onClick: () => store.set('screen', item.id),
        },
            h('span', { class: 'rail-glyph' }, assets.icon(item.icon)),
            h('span', { class: 'rail-label' }, item.label),
        )),
    );
}

function hudFigure(field, label, value, format, iconName) {
    return h('div', { key: field, class: 'hud-figure' },
        h('span', { class: 'hud-label' },
            iconName ? assets.icon(iconName, { class: 'hud-icon' }) : null,
            label,
        ),
        h('span', { class: 'hud-value' }, format(value)),
    );
}

function hud(player) {
    if (!player) return null;

    return h('div', { id: 'hud', role: 'status', 'aria-live': 'off' },
        hudFigure('coins', 'Coins', displayedOr('coins', player.coins || 0), formatCount, 'coin'),
        hudFigure('stars', 'Stars', displayedOr('stars', player.seasonal_stars || 0), formatCount, 'star-season'),
        hudFigure('sigils', 'Sigils', displayedOr('sigils', player.sigils || 0), formatCount, 'sigil'),
        hudFigure('rate', 'Rate', displayedOr('rate', player.ubi_rate || 0), formatRate, null),
        tickIndicator(),
        // Moments play over the HUD rather than beside it. Empty and inert
        // until a sprite is registered for 'payout-burst'.
        h('div', { key: 'burst', id: 'payout-burst', class: 'sprite-host' }),
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

function toastView() {
    const t = store.get('ui.toast');
    if (!t) return null;
    // assertive rather than polite: a toast is almost always an error the
    // player needs before they retry the thing that failed.
    return h('div', { class: `toast toast-${t.kind}`, role: 'alert', 'aria-live': 'assertive' }, t.text);
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

function deck(screenId) {
    const screen = getScreen(screenId);
    if (!screen) {
        return h('p', { class: 'deck-placeholder' }, `No screen named "${screenId}".`);
    }
    try {
        return screen.view(ctx);
    } catch (err) {
        // A screen that throws must not take the shell down with it — the rail
        // has to stay usable so the player can navigate away from the broken
        // one rather than reloading.
        console.error(`[main] screen "${screenId}" failed to render:`, err);
        return h('div', { class: 'screen-error' },
            h('p', null, 'This screen hit an error.'),
            h('p', { class: 'muted small' }, String(err && err.message || err)),
        );
    }
}

function shell() {
    const screen = store.get('screen');
    return h('div', { id: 'shell' },
        rail(screen),
        h('div', { id: 'stage' },
            hud(store.get('player')),
            connectionNote(store.get('connection')),
            h('main', { id: 'deck', 'data-screen': screen }, deck(screen)),
            themeSwitch(),
        ),
        toastView(),
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

        // Post-render hook, for the small number of things that genuinely
        // cannot be expressed declaratively — chat pinning its scroll to the
        // newest message is the only current use. Runs after the DOM settles.
        const screen = getScreen(store.get('screen'));
        if (screen && screen.afterRender) {
            try { screen.afterRender(ctx); } catch (err) { console.error('[main] afterRender failed:', err); }
        }
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

    if (gs.timing) {
        clock.setTickPhase({
            periodSeconds: gs.timing.tick_real_seconds,
            lastTickAt: resolveLastTick(gs.timing),
        });
    }
}

/**
 * Work out when the last server tick fired, in *this device's* clock.
 *
 * Prefer tick_age_seconds: anchoring to our own Date.now() minus the age means
 * a device with a misset clock still counts down correctly, because the offset
 * cancels out. Falling back to the absolute epoch is only right when the two
 * clocks agree, which on phones is not a safe assumption.
 *
 * Returns null when the server publishes neither — a fresh install before its
 * first tick, or straight after a reset that cleared server_state. The clock
 * then reports phase as unknown and the HUD shows cadence instead of a
 * countdown, rather than guessing.
 */
function resolveLastTick(timing) {
    if (typeof timing.tick_age_seconds === 'number') {
        return Date.now() - timing.tick_age_seconds * 1000;
    }
    if (typeof timing.last_tick_at === 'number') {
        return timing.last_tick_at;
    }
    return null;
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

    // Screen transitions run enter/leave. Subscribing rather than doing this
    // inside navigate() means a screen change from anywhere — a deep link, an
    // action, a 401 bouncing us to auth — goes through the same path.
    store.subscribe('screen', (next) => { activateScreen(next); });

    // Figures animate toward their new values rather than jumping.
    store.subscribe('player.coins', (next) => animateField('coins', Number(next) || 0));
    store.subscribe('player.seasonal_stars', (next) => animateField('stars', Number(next) || 0));
    store.subscribe('player.sigils', (next) => animateField('sigils', Number(next) || 0));
    store.subscribe('player.ubi_rate', (next) => animateField('rate', Number(next) || 0));

    // One clock: the poll, the countdown repaint, and the payout pulse.
    clock.every(POLL_MS, poll);
    clock.every(1000, scheduleRender);
    // A payout landing gets two treatments: the CSS glow, which works today,
    // and a sprite, which is a no-op until 'payout-burst' has art behind it.
    // Both are driven by clock.onTick, so neither can fire while the tick
    // phase is unknown and the moment would be a guess.
    clock.onTick(() => {
        const el = document.getElementById('hud');
        if (el) motion.pulse(el, 'is-paid');

        const host = document.getElementById('payout-burst');
        if (host) assets.playSprite(host, 'payout-burst');
    });

    // Warm the sprite sheets so the first moment does not flash a blank frame.
    // Resolves immediately while every slot is still a placeholder.
    assets.preload(['payout-burst', 'sigil-drop', 'theft-strike']);

    scheduleRender();
    poll();

    // Run the initial screen's enter() — the subscription above only fires on
    // a *change*, and the first screen was set before anyone was listening.
    activateScreen(store.get('screen'));
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}
