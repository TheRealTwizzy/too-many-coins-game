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
import { getScreen, RAIL_PARENT } from './screens/index.js';
import { HANDLE_RE, PASSWORD_MIN } from './screens/auth.js';
import { constructionShell } from './screens/construction.js';

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
    // Trusted only after the first poll; defaulting to NORMAL avoids flashing
    // the construction page at every boot.
    serverMode: 'NORMAL',
    // Progression gates: null = ungated (server flag off or logged out).
    unlocks: null,
});

const clock = createClock();
const motion = createMotion();

// Art is referenced by name, never by glyph or path. Everything below asks for
// 'nav-home' or 'coin'; what those look like is core/assets.js's business, so
// swapping placeholders for real art touches that file and nothing here.
const assets = createAssets({ h, motion });

const api = createApi({
    onUnauthorized() {
        // The session is gone, so the stored token is worthless — keeping it
        // would send a dead credential on every subsequent request.
        clearToken();
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

    // ---- staff / admin (server enforces requireStaff/requireAdmin on every
    // one of these; the client gating is presentation only) ----

    async staffSearch() {
        const query = String(store.get('ui.staffQuery') || '').trim();
        const res = await api.request('staff_users_search', { query });
        if (!res || res.error) return toast((res && res.error) || 'Search failed', 'error');
        store.set('screens.staff.results', res.users || []);
    },

    async staffOpenUser(playerId) {
        const res = await api.request('staff_user_get', { target_player_id: playerId });
        if (!res || res.error) return toast((res && res.error) || 'Load failed', 'error');
        store.set('screens.staff.detail', res);
    },

    async staffServerMode(mode) {
        const res = await api.request('staff_server_mode', {
            mode,
            reason: String(store.get('ui.staffModeReason') || '').trim() || 'Toggled from staff screen',
        });
        if (!res || res.error) return toast((res && res.error) || 'Mode change failed', 'error');
        toast(`Server mode: ${res.server_mode}`, 'success');
        await poll();
    },

    async staffSetRole(playerId, currentRole) {
        const role = store.get('ui.staffRole') || currentRole;
        const res = await api.request('admin_role_update', {
            target_player_id: playerId,
            role,
            reason: String(store.get('ui.staffRoleReason') || '').trim() || 'Role update from staff screen',
        });
        if (!res || res.error) return toast((res && res.error) || 'Role update failed', 'error');
        toast(`Role set to ${role}`, 'success');
        await ctx.staffOpenUser(playerId);
    },

    async staffMute(playerId) {
        const minutes = parseInt(store.get('ui.staffMuteMinutes'), 10) || 0;
        const res = await api.request('staff_chat_mute_user', {
            target_player_id: playerId,
            scope: store.get('ui.staffMuteScope') || 'ALL',
            minutes: minutes > 0 ? minutes : null,
            reason: String(store.get('ui.staffMuteReason') || '').trim() || 'Muted by staff',
        });
        if (!res || res.error) return toast((res && res.error) || 'Mute failed', 'error');
        toast('Muted', 'success');
        await ctx.staffOpenUser(playerId);
    },

    async staffUnmute(muteId, playerId) {
        const res = await api.request('staff_chat_unmute_user', { mute_id: muteId });
        if (!res || res.error) return toast((res && res.error) || 'Unmute failed', 'error');
        toast('Unmuted', 'success');
        await ctx.staffOpenUser(playerId);
    },

    /** targetPlayerId null = broadcast to every player. */
    async staffNotify(targetPlayerId) {
        const title = String(store.get('ui.staffNoteTitle') || '').trim();
        const body = String(store.get('ui.staffNoteBody') || '').trim();
        if (!title) return toast('Notification title required', 'error');
        const action = targetPlayerId === null ? 'staff_notifications_send_all' : 'staff_notifications_send_player';
        const payload = { title, body: body || null };
        if (targetPlayerId !== null) payload.target_player_id = targetPlayerId;
        const res = await api.request(action, payload);
        if (!res || res.error) return toast((res && res.error) || 'Send failed', 'error');
        toast(targetPlayerId === null ? `Broadcast sent to ${res.count} players` : 'Notification sent', 'success');
    },

    /**
     * Progression gate check. null/undefined unlocks = the server is not
     * gating (flag off), so everything is visible; otherwise a feature is
     * visible only once its key has been discovered.
     */
    unlocked(key) {
        const unlocks = store.get('unlocks');
        if (unlocks === null || unlocks === undefined) return true;
        return unlocks.includes(key);
    },

    async joinSeason(seasonId) {
        store.set('ui.joining', seasonId);
        const res = await api.request('season_join', { season_id: seasonId });
        store.set('ui.joining', null);
        if (res && res.error) return actionFailed(res);

        // Refresh immediately rather than waiting up to 3s for the next poll —
        // joining is the one action where the whole screen changes meaning.
        await poll();
        ctx.openSeason(seasonId);

        // A first-ever join hands over starter sigils. Say so, and say what
        // they are for — a player who does not notice them learns the forge
        // several drop intervals later, which is the problem the grant exists
        // to solve. Also state the lock-in offset up front rather than letting
        // it turn up as a surprise deduction at the end of the season.
        const grant = res && res.starter_grant;
        if (grant && grant.count > 0) {
            const stars = new Intl.NumberFormat('en-US').format(grant.refund_offset_stars || 0);
            await ctx.confirm({
                title: 'Welcome — here are some sigils',
                body: `You have been given ${grant.count} Tier ${grant.tier} sigils so you can use the forge `
                    + `straight away: combine five of a tier into one of the next. `
                    + `They are a starting hand rather than a bonus, so ${stars} stars are deducted `
                    + `from your lock-in refund at the end.`,
                confirmLabel: 'Got it',
                dismissOnly: true,
            });
        }
    },

    switchAuthTab(tab) {
        store.set('ui.authTab', tab);
        store.set('ui.authError', null);
    },

    async doLogin() {
        const email = readField('login-email').trim();
        const password = readField('login-password');

        if (!email || !password) {
            return store.set('ui.authError', 'Enter your email and password.');
        }

        store.set('ui.authError', null);
        store.set('ui.authBusy', true);
        const res = await api.request('login', { email, password });
        store.set('ui.authBusy', false);

        if (!res || res.error) {
            // The server's message is the authoritative one — it distinguishes
            // a bad password from a deleted account from a rate limit, and
            // paraphrasing it here would lose that.
            return store.set('ui.authError', (res && res.error) || 'Could not sign in.');
        }

        await finishSignIn(res, password);
    },

    async doRegister() {
        const handle = readField('reg-handle').trim();
        const email = readField('reg-email').trim();
        const password = readField('reg-password');

        // Local checks first, purely to save a round trip. The server runs its
        // own and its verdict wins.
        if (!HANDLE_RE.test(handle)) {
            return store.set('ui.authError', 'Handle must be 3–16 characters: letters, numbers or underscores.');
        }
        if (password.length < PASSWORD_MIN) {
            return store.set('ui.authError', `Password must be at least ${PASSWORD_MIN} characters.`);
        }

        store.set('ui.authError', null);
        store.set('ui.authBusy', true);
        const res = await api.request('register', { handle, email, password });
        store.set('ui.authBusy', false);

        if (!res || res.error) {
            return store.set('ui.authError', (res && res.error) || 'Could not create the account.');
        }

        // Register does not always return a session, so fall back to logging in
        // with the credentials just used rather than dropping the player on a
        // login form they have already filled once.
        if (res.token) {
            await finishSignIn(res, password);
        } else {
            const login = await api.request('login', { email, password });
            if (!login || login.error) {
                store.set('ui.authTab', 'login');
                return store.set('ui.authError', 'Account created. Please sign in.');
            }
            await finishSignIn(login, password);
        }
    },

    async doLogout() {
        await api.request('logout', {});
        clearToken();

        // Drop every trace of the session, not just the player: leaving the
        // previous account's chat transcript or shop inventory in the store
        // would show it to whoever signs in next.
        store.patch({ player: null, seasons: [], screens: {} });
        store.set('ui.seasonId', null);
        store.set('screen', 'home');
        toast('Signed out.', 'info');
    },

    openSeason(seasonId) {
        store.set('ui.seasonId', Number(seasonId));
        store.set('screens.season', null);
        store.set('screen', 'season');
        // activateScreen only runs enter() on a *change* of screen id, so
        // reopening a different season from the same screen would otherwise
        // show the previous one's data until the next poll.
        ctx.loadSeasonDetail();
    },

    async loadSeasonDetail() {
        const seasonId = store.get('ui.seasonId');
        if (seasonId === null || seasonId === undefined) return;
        const res = await api.request('season_detail', { season_id: seasonId });
        if (!res) return;
        store.set('screens.season', res);
    },

    async buyStars(quantity, cost) {
        const confirmed = await ctx.confirm({
            title: `Buy ${quantity} star${quantity === 1 ? '' : 's'}?`,
            body: `This spends ${new Intl.NumberFormat('en-US').format(cost)} coins. `
                + 'Buying raises the price for everyone, including you.',
            confirmLabel: 'Buy',
        });
        if (!confirmed) return;

        store.set('ui.buyingStars', true);
        // The server reads stars_requested; sending { quantity } silently
        // requested zero stars on every purchase.
        const res = await api.request('purchase_stars', { stars_requested: quantity });
        store.set('ui.buyingStars', false);
        if (res && res.error) return actionFailed(res);
        await Promise.all([poll(), ctx.loadSeasonDetail()]);
    },

    async combineSigil(fromTier) {
        store.set('ui.forgeBusy', fromTier);
        const res = await api.request('combine_sigil', { from_tier: fromTier });
        store.set('ui.forgeBusy', null);
        if (res && res.error) return actionFailed(res);
        await poll();
    },

    async combineAll() {
        store.set('ui.forgeBusy', 'all');
        const res = await api.request('combine_all_sigils', {});
        store.set('ui.forgeBusy', null);
        if (res && res.error) return actionFailed(res);
        await poll();
    },

    /**
     * Lock in. The one action in the game with no undo, so the confirmation
     * states the stake in figures and requires an explicit acknowledgement
     * rather than a single click. The exact global-star payout (conversion
     * rate, sigil refund, fractional carry) is server math — the dialog names
     * the stake and the server's success message reports the real figures.
     */
    async lockIn(stake) {
        const confirmed = await ctx.confirm({
            title: 'Lock in and end your season?',
            body: `Your ${new Intl.NumberFormat('en-US').format(stake)} seasonal stars, plus a refund for `
                + 'unspent sigils, convert to global stars at the lock-in rate. '
                + 'Your seasonal position, coins and sigils are gone. This cannot be undone.',
            confirmLabel: 'Lock in',
            danger: true,
            requireAck: 'I understand this ends my season',
        });
        if (!confirmed) return;

        store.set('ui.lockingIn', true);
        const res = await api.request('lock_in', {});
        store.set('ui.lockingIn', false);
        if (res && res.error) return actionFailed(res);

        toast(res && res.message ? res.message : 'Locked in.', 'info');
        await poll();
        store.set('screen', 'home');
    },

    async freezeTarget() {
        const target = await ctx.pickTarget({ title: 'Freeze whose income?' });
        if (!target) return;

        const confirmed = await ctx.confirm({
            title: `Freeze ${target.handle}?`,
            body: 'This spends a sigil and stops their income for a time. They will be told it was you.',
            confirmLabel: 'Freeze',
            danger: true,
        });
        if (!confirmed) return;

        const res = await api.request('freeze_player_ubi', { target_player_id: target.player_id });
        if (res && res.error) return actionFailed(res);
        toast(`Froze ${target.handle}.`, 'info');
        await Promise.all([poll(), ctx.loadSeasonDetail()]);
    },

    async selfMelt() {
        const confirmed = await ctx.confirm({
            title: 'Melt your freeze?',
            body: 'Spends a T5 or T6 sigil to end the freeze on your own income early.',
            confirmLabel: 'Melt',
        });
        if (!confirmed) return;

        const res = await api.request('self_melt_freeze', {});
        if (res && res.error) return actionFailed(res);
        toast('Freeze melted.', 'info');
        await Promise.all([poll(), ctx.loadSeasonDetail()]);
    },

    /**
     * Ask a yes/no question. Resolves false on cancel, escape or backdrop
     * click, so every caller can treat "not true" as "do nothing".
     */
    confirm(options) {
        return new Promise(resolve => {
            store.set('ui.dialog', {
                kind: 'confirm',
                ...options,
                acked: false,
                resolve,
            });
        });
    },

    /** Choose a player from the current season's standings. Resolves null on cancel. */
    pickTarget(options) {
        const detail = store.get('screens.season');
        const me = store.get('player');
        const candidates = ((detail && detail.leaderboard) || [])
            .filter(r => !me || Number(r.player_id) !== Number(me.player_id));

        if (!candidates.length) {
            toast('Nobody else in this season yet.', 'error');
            return Promise.resolve(null);
        }

        return new Promise(resolve => {
            store.set('ui.dialog', { kind: 'target', ...options, candidates, resolve });
        });
    },

    closeDialog(result) {
        const dialog = store.get('ui.dialog');
        store.set('ui.dialog', null);
        if (dialog && dialog.resolve) dialog.resolve(result);
    },

    async loadLeaderboard() {
        // global_leaderboard takes no arguments and returns the full ranked
        // list; the old page/per_page params were ignored server-side and the
        // client pager just re-rendered the same list.
        const res = await api.request('global_leaderboard', {});
        if (!res || res.error) return;
        const entries = Array.isArray(res) ? res : (res.entries || res.leaderboard || []);
        store.set('screens.ranks', { entries });
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

        if (res && res.error) return actionFailed(res);

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
        if (res && res.error) return actionFailed(res);
        await Promise.all([ctx.loadShop(), poll()]);
    },

    async equipCosmetic(cosmeticId) {
        store.set('ui.shopBusy', cosmeticId);
        const res = await api.request('equip_cosmetic', { cosmetic_id: cosmeticId });
        store.set('ui.shopBusy', null);
        if (res && res.error) return actionFailed(res);
        await ctx.loadShop();
    },
};

/* ------------------------------------------------------------------ *
 * session
 * ------------------------------------------------------------------ */

const TOKEN_KEY = 'tmc_token';

function readField(id) {
    const el = document.getElementById(id);
    return el ? el.value : '';
}

function storeToken(token) {
    if (!token) return;
    try {
        localStorage.setItem(TOKEN_KEY, token);
    } catch {
        // Private browsing. The HttpOnly session cookie the server just set is
        // the primary credential anyway; the header is the fallback, so losing
        // it costs nothing on a same-origin request.
    }
}

function clearToken() {
    try {
        localStorage.removeItem(TOKEN_KEY);
    } catch {
        // Nothing to do — see storeToken.
    }
}

/**
 * Complete a sign-in.
 *
 * The session cookie is set server-side and is HttpOnly. It is deliberately
 * NOT rewritten from here: doing that replaces it with a script-readable
 * cookie and quietly undoes the HttpOnly protection. Only the bearer token
 * copy is kept, for the X-Session-Token fallback header.
 */
async function finishSignIn(result, password) {
    storeToken(result.token);

    // The local password variable is the last reference to it; make sure
    // nothing holds it once the exchange is done.
    password = null;
    void password;

    // Clear the form nodes directly. They are uncontrolled, so the reconciler
    // never writes to them and would happily leave a filled-in password box
    // sitting behind the next screen.
    for (const id of ['login-email', 'login-password', 'reg-handle', 'reg-email', 'reg-password']) {
        const el = document.getElementById(id);
        if (el) el.value = '';
    }

    store.set('ui.authError', null);
    await poll();
    store.set('screen', 'home');
}

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

/**
 * Standard surface for a rejected action: the server's message, the machine
 * reason_code when one exists, and a snapshot refresh. The refresh is the
 * important half — a rejection means the client's picture of legality was
 * stale, so keep rendering from that picture and the next click fails the
 * same way.
 */
function actionFailed(res, fallback = 'Action failed') {
    const msg = (res && res.error) || fallback;
    const code = res && res.reason_code;
    toast(code ? `${msg} [${code}]` : msg, 'error');
    poll();
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

const STAFF_NAV = { id: 'staff', label: 'Staff', icon: 'nav-staff' };

function navItems() {
    const player = store.get('player');
    const staff = player && (player.role === 'Admin' || player.role === 'Moderator');
    return staff ? [...NAV, STAFF_NAV] : NAV;
}

function rail(screen) {
    return h('nav', { id: 'rail', 'aria-label': 'Primary' },
        // A screen that is not itself on the rail still lights up its parent —
        // season detail keeps Seasons highlighted, so opening one does not read
        // as having navigated out of the section.
        navItems().map(item => h('button', {
            key: item.id,
            class: 'rail-btn' + (screen === item.id || RAIL_PARENT[screen] === item.id ? ' is-active' : ''),
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

    // Season-bound figures live under player.participation in game_state —
    // null when the player is not in a season, in which case the HUD shows
    // zeroes, which is the truth.
    const part = player.participation || {};

    return h('div', { id: 'hud', role: 'status', 'aria-live': 'off' },
        hudFigure('coins', 'Coins', displayedOr('coins', part.coins || 0), formatCount, 'coin'),
        hudFigure('stars', 'Stars', displayedOr('stars', part.effective_seasonal_stars ?? part.seasonal_stars ?? 0), formatCount, 'star-season'),
        hudFigure('sigils', 'Sigils', displayedOr('sigils', part.sigils_total || 0), formatCount, 'sigil'),
        hudFigure('rate', 'Rate', displayedOr('rate', part.net_rate_per_tick ?? part.rate_per_tick ?? 0), formatRate, null),
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

/**
 * Modal host.
 *
 * Rendered through the reconciler like everything else rather than being
 * appended to the DOM behind its back, so a dialog open across a poll keeps
 * its checkbox state and its focus.
 */
function dialogView() {
    const dialog = store.get('ui.dialog');
    if (!dialog) return null;

    const body = dialog.kind === 'target' ? targetDialog(dialog) : confirmDialog(dialog);

    return h('div', {
        class: 'dialog-backdrop',
        // Cancelling by clicking away must not fire when the click started
        // inside the dialog and merely ended on the backdrop — that is a drag,
        // not a dismissal, and losing a filled-in dialog to it is maddening.
        onMouseDown: (e) => { if (e.target === e.currentTarget) ctx.closeDialog(dialog.kind === 'target' ? null : false); },
    },
        h('div', {
            class: 'dialog' + (dialog.danger ? ' is-danger' : ''),
            role: 'dialog',
            'aria-modal': 'true',
            'aria-label': dialog.title || 'Confirm',
        }, body),
    );
}

function confirmDialog(dialog) {
    const needsAck = Boolean(dialog.requireAck);
    const acked = Boolean(store.get('ui.dialogAcked'));

    return [
        h('h2', { class: 'dialog-title' }, dialog.title || 'Are you sure?'),
        dialog.body ? h('p', { class: 'dialog-body' }, dialog.body) : null,

        needsAck
            ? h('label', { class: 'dialog-ack' },
                h('input', {
                    type: 'checkbox',
                    checked: acked,
                    onChange: (e) => store.set('ui.dialogAcked', e.target.checked),
                }),
                dialog.requireAck,
            )
            : null,

        h('div', { class: 'dialog-actions' },
            // An informational dialog has nothing to cancel — offering the
            // choice implies the thing has not happened yet, when it already has.
            dialog.dismissOnly ? null : h('button', {
                class: 'btn btn-ghost',
                onClick: () => { store.set('ui.dialogAcked', false); ctx.closeDialog(false); },
            }, 'Cancel'),
            h('button', {
                class: 'btn ' + (dialog.danger ? 'btn-danger' : 'btn-primary'),
                disabled: needsAck && !acked,
                onClick: () => { store.set('ui.dialogAcked', false); ctx.closeDialog(true); },
            }, dialog.confirmLabel || 'Confirm'),
        ),
    ];
}

function targetDialog(dialog) {
    return [
        h('h2', { class: 'dialog-title' }, dialog.title || 'Choose a player'),
        h('ul', { class: 'target-list' },
            dialog.candidates.map(c => h('li', { key: c.player_id },
                h('button', {
                    class: 'target-btn',
                    onClick: () => ctx.closeDialog(c),
                },
                    h('span', { class: 'target-handle' }, c.handle || '—'),
                    h('span', { class: 'target-stars tabular muted small' },
                        `${new Intl.NumberFormat('en-US').format(Math.round(Number(c.effective_seasonal_stars ?? c.seasonal_stars) || 0))} ★`),
                ),
            )),
        ),
        h('div', { class: 'dialog-actions' },
            h('button', { class: 'btn btn-ghost', onClick: () => ctx.closeDialog(null) }, 'Cancel'),
        ),
    ];
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

function userArea() {
    const player = store.get('player');

    if (!player) {
        return h('div', { class: 'user-area' },
            h('button', { class: 'btn btn-ghost btn-sm', onClick: () => store.set('screen', 'auth') }, 'Log in'),
        );
    }

    return h('div', { class: 'user-area' },
        h('span', { class: 'user-handle' }, player.handle || 'Player'),
        h('button', { class: 'btn btn-ghost btn-sm', onClick: () => ctx.doLogout() }, 'Log out'),
    );
}

function shell() {
    const screen = store.get('screen');

    // Maintenance gate. shell() is the only producer of the tree, so the
    // short-circuit hides rail, HUD and deck at once. The screen id in the
    // store is deliberately untouched — when the gate lifts, the player is
    // still where they were. The auth escape lets staff reach sign-in; once
    // signed in as staff the gate no longer applies to them.
    const player = store.get('player');
    const isStaff = Boolean(player && (player.role === 'Admin' || player.role === 'Moderator'));
    if (store.get('serverMode') === 'MAINTENANCE_LOCKDOWN' && !isStaff && screen !== 'auth') {
        return constructionShell(h, { onStaffSignIn: () => store.set('screen', 'auth') });
    }

    return h('div', { id: 'shell' },
        rail(screen),
        h('div', { id: 'stage' },
            userArea(),
            hud(store.get('player')),
            connectionNote(store.get('connection')),
            h('main', { id: 'deck', 'data-screen': screen }, deck(screen)),
            themeSwitch(),
        ),
        toastView(),
        h('div', { id: 'dialog-host' }, dialogView()),
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
 * routing
 *
 * Hash-based, so it needs no server rewrite and cannot collide with the
 * ?ui=next query the boot switch reads. Two things depend on this beyond
 * convenience: the browser back button, which otherwise leaves the site
 * entirely from the first screen you open, and shareable links to a season.
 *
 *   #/seasons
 *   #/season/1
 * ------------------------------------------------------------------ */

// Set while we are the ones writing the hash, so our own write does not come
// back through hashchange and re-enter navigation.
let writingHash = false;

function parseHash() {
    const raw = String(location.hash || '').replace(/^#\/?/, '');
    if (!raw) return null;
    const [screen, param] = raw.split('/');
    if (!screen) return null;
    if (screen === 'season' && param !== undefined && param !== '') {
        return { screen: 'season', seasonId: Number(param) };
    }
    return { screen, seasonId: null };
}

function writeHash(replace = false) {
    const screen = store.get('screen');
    const seasonId = store.get('ui.seasonId');
    const next = screen === 'season' && seasonId !== null && seasonId !== undefined
        ? `#/season/${seasonId}`
        : `#/${screen}`;

    if (location.hash === next) return;

    writingHash = true;
    try {
        // replaceState on boot so the first screen does not add a history entry
        // the player never navigated to.
        if (replace) history.replaceState(null, '', next);
        else history.pushState(null, '', next);
    } catch {
        // Some embedded webviews refuse history writes. Routing is a
        // convenience; losing it should not take the client down.
        location.hash = next;
    }
    writingHash = false;
}

function applyRoute(route) {
    if (!route) return;
    if (route.screen === 'season' && route.seasonId !== null) {
        store.set('ui.seasonId', route.seasonId);
    }
    if (getScreen(route.screen)) store.set('screen', route.screen);
}

function bindRouting() {
    window.addEventListener('hashchange', () => {
        if (writingHash) return;
        applyRoute(parseHash());
    });
    window.addEventListener('popstate', () => {
        if (writingHash) return;
        applyRoute(parseHash());
    });

    // Screen and season changes both write the URL.
    store.subscribe('screen', () => writeHash());
    store.subscribe('ui.seasonId', () => writeHash());
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
        serverMode: gs.server_mode || 'NORMAL',
        unlocks: gs.player ? (gs.player.unlocks ?? null) : null,
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

    // Figures animate toward their new values rather than jumping. The paths
    // match where game_state actually puts them: under player.participation.
    store.subscribe('player.participation.coins', (next) => animateField('coins', Number(next) || 0));
    store.subscribe('player.participation.effective_seasonal_stars', (next) => animateField('stars', Number(next) || 0));
    store.subscribe('player.participation.sigils_total', (next) => animateField('sigils', Number(next) || 0));
    store.subscribe('player.participation.net_rate_per_tick', (next) => animateField('rate', Number(next) || 0));

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

    // Routing is bound before the initial route is applied, so the very first
    // screen still writes a hash and the back button has somewhere to go.
    bindRouting();
    const initial = parseHash();
    if (initial) applyRoute(initial);
    writeHash(true);

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
