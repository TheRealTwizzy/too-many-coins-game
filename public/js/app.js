/**
 * Too Many Coins - Game Client
 * Complete JavaScript client for the economic competition game
 */
const TMC = {
    // State
    state: {
        player: null,
        seasons: [],
        currentScreen: 'home',
        currentSeason: null,
        currentChat: 'GLOBAL',
        gameState: null,
        pollInterval: null,
        realtimeInterval: null,
        seasonCountdowns: {},
        chatPollInterval: null,
        shopFilter: 'all',
        cosmetics: [],
        myCosmetics: [],
        boostCountdowns: {},
        freezeCountdown: null,
        leaderboardTab: 'global',
        pendingTheftTargetId: null,
        notifications: [],
        notificationsUnread: 0,
        notificationsOpen: false,
        pollBackoffMs: 0,
        pollBackoffUntil: 0,
        chatBackoffMs: 0,
        chatBackoffUntil: 0,
        lastRateLimitToastAt: 0,
    },

    API_BASE: '/api/index.php',
    _seasonDetailLeaderboardExpanded: false,
    _globalSeasonalLeaderboardExpanded: false,
    _globalSeasonalLeaderboardPage: 1,
    _globalLeaderboardPage: 1,
    _historyBound: false,
    _combineAllPending: false,

    // ==================== API ====================
    async api(action, data = {}) {
        try {
            const token = localStorage.getItem('tmc_token');
            const body = JSON.stringify({ action, ...data });
            const resp = await fetch(this.API_BASE + '?action=' + action, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Session-Token': token || ''
                },
                body: body
            });

            let json = {};
            try {
                json = await resp.json();
            } catch (parseErr) {
                json = {};
            }

            if (resp.status === 429) {
                return {
                    ...json,
                    status: 429,
                    error: json.error || 'Rate limit exceeded. Please slow down.'
                };
            }

            if (resp.status === 401 || resp.status === 403 || (json.error && json.error.includes('Authentication required'))) {
                this.handleLoggedOut();
            }

            if (!resp.ok) {
                return {
                    ...json,
                    status: resp.status,
                    error: json.error || `Request failed (${resp.status})`
                };
            }

            return json;
        } catch (e) {
            console.error('API error:', e);
            return { error: 'Network error. Please try again.', status: 0 };
        }
    },

    // ==================== INIT ====================
    async init() {
        this._bindHistoryNavigation();

        // Check for stored session
        const token = localStorage.getItem('tmc_token');
        if (token) {
            document.cookie = `tmc_session=${token}; path=/; max-age=86400`;
        }

        await this.refreshGameState();
        this.renderUserArea();

        // Route priority on boot: existing history state -> URL -> saved local
        // route (logged-in only) -> home. We replace the current entry so the
        // active tab always has a valid in-site history anchor.
        const stateRoute = this._routeFromHistoryState(window.history.state);
        const hashRoute = this._parseRouteFromHash();
        const urlRoute = this._parseRouteFromUrl();
        const savedRoute = this._loadRoute();

        let initialRoute = stateRoute || hashRoute || urlRoute;
        if (!initialRoute && savedRoute && savedRoute.screen && savedRoute.screen !== 'auth' && this.state.player) {
            initialRoute = { screen: savedRoute.screen, data: savedRoute.data !== undefined ? savedRoute.data : null };
        }
        if (!initialRoute) {
            initialRoute = { screen: 'home', data: null };
        }

        this.navigate(initialRoute.screen, initialRoute.data, { history: 'replace', source: 'init' });

        // Start polling
        this.startPolling();
        this.startRealtimeClock();
        this.setupNotificationCenter();
    },

    startPolling() {
        if (this.state.pollInterval) clearInterval(this.state.pollInterval);
        this.state.pollInterval = setInterval(() => this.refreshGameState(), 3000);
    },

    startRealtimeClock() {
        if (this.state.realtimeInterval) clearInterval(this.state.realtimeInterval);
        this.state.realtimeInterval = setInterval(() => this.tickRealtimeViews(), 1000);
    },

    _isBackoffActive(kind) {
        const now = Date.now();
        if (kind === 'chat') {
            return now < (this.state.chatBackoffUntil || 0);
        }
        return now < (this.state.pollBackoffUntil || 0);
    },

    _applyBackoff(kind, baseMs) {
        const keyMs = kind === 'chat' ? 'chatBackoffMs' : 'pollBackoffMs';
        const keyUntil = kind === 'chat' ? 'chatBackoffUntil' : 'pollBackoffUntil';
        const previous = Number(this.state[keyMs] || 0);
        const next = previous > 0 ? Math.min(previous * 2, 30000) : Math.max(baseMs, 3000);
        const jitter = Math.floor(Math.random() * 400);
        this.state[keyMs] = next;
        this.state[keyUntil] = Date.now() + next + jitter;
        this._maybeShowRateLimitToast();
    },

    _resetBackoff(kind) {
        if (kind === 'chat') {
            this.state.chatBackoffMs = 0;
            this.state.chatBackoffUntil = 0;
            return;
        }
        this.state.pollBackoffMs = 0;
        this.state.pollBackoffUntil = 0;
    },

    _maybeShowRateLimitToast() {
        const now = Date.now();
        const cooldownMs = 30000;
        if (now - (this.state.lastRateLimitToastAt || 0) < cooldownMs) {
            return;
        }
        this.state.lastRateLimitToastAt = now;
        this.toast('Server is busy. Staying connected and retrying shortly.', 'info');
    },

    async refreshGameState() {
        if (this._isBackoffActive('poll')) return;

        const gs = await this.api('game_state');

        if (gs && (gs.status === 429 || gs.status >= 500 || gs.status === 0)) {
            this._applyBackoff('poll', 3000);
            return;
        }

        if (gs.error) return;

        this._resetBackoff('poll');
        this.state.gameState = gs;
        this.state.seasons = gs.seasons || [];
        this.syncSeasonCountdowns(this.state.seasons);
        this.state.player = gs.player || null;
        this.syncNotificationsFromPlayer(this.state.player);
        this.syncBoostCountdowns();
        this.syncFreezeCountdown();
        this.renderUserArea();

        if (this.state.currentScreen === 'auth' && this.state.player) {
            this.navigate('home', null, { history: 'replace', source: 'auth-refresh' });
        }

        this.updateHUD();
        this.checkIdleModal();

        // Refresh current screen data (but don't re-render season detail to preserve input state)
        if (this.state.currentScreen === 'seasons') this.renderSeasons();
        if (this.state.currentScreen === 'season-detail' && this.state.currentSeason) {
            this.updateSeasonDetailLive();
        }
        if (this.state.currentScreen === 'global-lb') this.loadGlobalLeaderboard();
        this.updateNotificationUI();
    },

    // ==================== AUTH ====================
    async login(e) {
        e.preventDefault();
        const email = document.getElementById('login-email').value;
        const password = document.getElementById('login-password').value;
        const result = await this.api('login', { email, password });
        if (result.error) {
            this.toast(result.error, 'error', { category: 'error_auth' });
            return;
        }
        localStorage.setItem('tmc_token', result.token);
        document.cookie = `tmc_session=${result.token}; path=/; max-age=86400`;
        this.toast('Welcome back, ' + result.handle + '!', 'success', { category: 'auth_login' });
        await this.refreshGameState();
        if (!this.state.player || this.state.player.player_id != result.player_id) {
            await this.refreshGameState();
        }
        if (!this.state.player) {
            this.state.player = {
                player_id: result.player_id,
                handle: result.handle,
                global_stars: 0
            };
        }
        this.renderUserArea();
        localStorage.removeItem('tmc_route');
        this.navigate('home', null, { history: 'replace', source: 'login' });
    },

    async register(e) {
        e.preventDefault();
        const handle = document.getElementById('reg-handle').value;
        const email = document.getElementById('reg-email').value;
        const password = document.getElementById('reg-password').value;
        const result = await this.api('register', { handle, email, password });
        if (result.error) {
            this.toast(result.error, 'error', { category: 'error_auth' });
            return;
        }
        localStorage.setItem('tmc_token', result.token);
        document.cookie = `tmc_session=${result.token}; path=/; max-age=86400`;
        this.toast('Account created! Welcome, ' + result.handle + '!', 'success', { category: 'auth_register' });
        await this.refreshGameState();
        if (!this.state.player || this.state.player.player_id != result.player_id) {
            await this.refreshGameState();
        }
        if (!this.state.player) {
            this.state.player = {
                player_id: result.player_id,
                handle: result.handle,
                global_stars: 0
            };
        }
        this.renderUserArea();
        localStorage.removeItem('tmc_route');
        this.navigate('home', null, { history: 'replace', source: 'register' });
    },

    async logout() {
        await this.api('logout');
        localStorage.removeItem('tmc_token');
        localStorage.removeItem('tmc_route');
        document.cookie = 'tmc_session=; path=/; max-age=0';
        this.state.player = null;
        this.state.gameState = null;
        this.state.notifications = [];
        this.state.notificationsUnread = 0;
        this.state.notificationsOpen = false;
        this.renderUserArea();
        this.updateNotificationUI();
        this.navigate('home', null, { history: 'replace', source: 'logout' });
        this.toast('Logged out.', 'info');
    },

    handleLoggedOut() {
        localStorage.removeItem('tmc_token');
        localStorage.removeItem('tmc_route');
        this.state.player = null;
        this.state.notifications = [];
        this.state.notificationsUnread = 0;
        this.state.notificationsOpen = false;
        this.renderUserArea();
        this.updateNotificationUI();
    },

    showAuthTab(tab) {
        document.querySelectorAll('.auth-tab').forEach(t => t.classList.remove('active'));
        if (tab === 'login') {
            document.getElementById('login-form').style.display = '';
            document.getElementById('register-form').style.display = 'none';
            document.querySelectorAll('.auth-tab')[0].classList.add('active');
        } else {
            document.getElementById('login-form').style.display = 'none';
            document.getElementById('register-form').style.display = '';
            document.querySelectorAll('.auth-tab')[1].classList.add('active');
        }
    },

    // ==================== NAVIGATION ====================
    navigate(screen, data, options = {}) {
        const route = this._normalizeRoute(screen, data);
        const historyMode = options.history || 'push';

        screen = route.screen;
        data = route.data;

        if (screen === 'home') {
            const activeSeason = this.getActiveJoinedSeason();
            if (activeSeason) {
                screen = 'season-detail';
                data = activeSeason.season_id;
            }
        }

        this.state.currentScreen = screen;
        this._saveRoute(screen, data);
        this._syncBrowserHistory(screen, data, historyMode);

        // Hide all screens
        document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));

        // Update desktop nav
        document.querySelectorAll('.nav-btn').forEach(b => b.classList.remove('active'));
        const navBtn = document.querySelector(`.nav-btn[data-screen="${screen}"]`);
        if (navBtn) navBtn.classList.add('active');

        // Update mobile bottom nav
        document.querySelectorAll('.bottom-nav-btn').forEach(b => b.classList.remove('active'));
        const bottomBtn = document.querySelector(`.bottom-nav-btn[data-screen="${screen}"]`);
        if (bottomBtn) bottomBtn.classList.add('active');

        const homeBottomBtn = document.querySelector('.bottom-nav-btn[data-screen="home"]');
        if (homeBottomBtn) {
            const activeSeason = this.getActiveJoinedSeason();
            const onDefaultHome = screen === 'home';
            const onActiveSeasonDetail =
                screen === 'season-detail'
                && !!activeSeason
                && Number(data) === Number(activeSeason.season_id);
            homeBottomBtn.classList.toggle('home-context-active', onDefaultHome || onActiveSeasonDetail);
        }

        // Scroll to top on screen change
        window.scrollTo(0, 0);

        // Show target screen
        const el = document.getElementById('screen-' + screen);
        if (el) el.classList.add('active');

        // Screen-specific logic
        switch (screen) {
            case 'home':
                this.renderHome();
                break;
            case 'auth':
                break;
            case 'seasons':
                this.renderSeasons();
                break;
            case 'season-detail':
                this.state.currentSeason = data;
                this.loadSeasonDetail(data);
                break;
            case 'global-lb':
                if (data && data.tab === 'seasonal') {
                    this.state.leaderboardTab = 'seasonal';
                }
                this.loadGlobalLeaderboard();
                break;
            case 'shop':
                this.loadShop();
                break;
            case 'chat':
                this.initChat();
                break;
            case 'profile':
                this.loadProfile(data);
                break;
            case 'theft':
                this.renderTheftScreen(data);
                break;
        }
    },

    _bindHistoryNavigation() {
        if (this._historyBound) return;
        window.addEventListener('popstate', (event) => {
            const route = this._routeFromHistoryState(event.state) || this._parseRouteFromHash() || this._parseRouteFromUrl() || { screen: 'home', data: null };
            this.navigate(route.screen, route.data, { history: 'none', source: 'popstate' });
        });
        window.addEventListener('hashchange', () => {
            const route = this._parseRouteFromHash();
            if (!route) return;
            const current = this._routeFromHistoryState(window.history.state)
                || this._parseRouteFromUrl()
                || this._normalizeRoute(this.state.currentScreen, null);
            if (this._routesEqual(current, route)) return;
            this.navigate(route.screen, route.data, { history: 'none', source: 'hashchange' });
        });
        this._historyBound = true;
    },

    _syncBrowserHistory(screen, data, mode) {
        if (!window.history || mode === 'none') return;

        const route = this._normalizeRoute(screen, data);
        const url = this._buildRouteUrl(route.screen, route.data);
        const stateObj = { tmcRoute: route };
        const currentRoute = this._routeFromHistoryState(window.history.state);
        const currentUrl = window.location.pathname + window.location.search + window.location.hash;

        if (currentRoute && this._routesEqual(currentRoute, route)) {
            if (currentUrl !== url) {
                window.history.replaceState(stateObj, '', url);
            }
            return;
        }

        if (mode === 'replace') {
            window.history.replaceState(stateObj, '', url);
            return;
        }

        window.history.pushState(stateObj, '', url);
    },

    _normalizeRoute(screen, data) {
        const allowed = ['home', 'auth', 'seasons', 'season-detail', 'global-lb', 'shop', 'chat', 'profile', 'theft'];
        const safeScreen = allowed.includes(screen) ? screen : 'home';

        if (safeScreen === 'season-detail') {
            const seasonId = parseInt(data, 10);
            return Number.isFinite(seasonId) && seasonId > 0
                ? { screen: safeScreen, data: seasonId }
                : { screen: 'home', data: null };
        }

        if (safeScreen === 'profile') {
            const playerId = parseInt(data, 10);
            return Number.isFinite(playerId) && playerId > 0
                ? { screen: safeScreen, data: playerId }
                : { screen: 'home', data: null };
        }

        if (safeScreen === 'global-lb') {
            const tab = data && data.tab === 'seasonal' ? 'seasonal' : null;
            return tab ? { screen: safeScreen, data: { tab } } : { screen: safeScreen, data: null };
        }

        if (safeScreen === 'theft') {
            const seasonId = parseInt(data && data.seasonId, 10);
            const targetPlayerId = parseInt(data && data.targetPlayerId, 10);
            if (Number.isFinite(seasonId) && seasonId > 0 && Number.isFinite(targetPlayerId) && targetPlayerId > 0) {
                return { screen: safeScreen, data: { seasonId, targetPlayerId } };
            }
            return { screen: 'home', data: null };
        }

        return { screen: safeScreen, data: null };
    },

    _buildRouteUrl(screen, data) {
        const route = this._normalizeRoute(screen, data);
        const baseParams = new URLSearchParams(window.location.search || '');

        if (route.screen !== 'home') {
            baseParams.set('screen', route.screen);
        } else {
            baseParams.delete('screen');
            baseParams.delete('seasonId');
            baseParams.delete('playerId');
            baseParams.delete('tab');
            baseParams.delete('targetPlayerId');
        }

        if (route.screen === 'season-detail') {
            baseParams.set('seasonId', String(route.data));
            baseParams.delete('playerId');
            baseParams.delete('tab');
            baseParams.delete('targetPlayerId');
        } else if (route.screen === 'profile') {
            baseParams.set('playerId', String(route.data));
            baseParams.delete('seasonId');
            baseParams.delete('tab');
            baseParams.delete('targetPlayerId');
        } else if (route.screen === 'global-lb' && route.data && route.data.tab === 'seasonal') {
            baseParams.set('tab', 'seasonal');
            baseParams.delete('seasonId');
            baseParams.delete('playerId');
            baseParams.delete('targetPlayerId');
        } else if (route.screen === 'theft' && route.data) {
            baseParams.set('seasonId', String(route.data.seasonId));
            baseParams.set('targetPlayerId', String(route.data.targetPlayerId));
            baseParams.delete('playerId');
            baseParams.delete('tab');
        } else if (route.screen !== 'season-detail' && route.screen !== 'profile' && route.screen !== 'global-lb') {
            baseParams.delete('seasonId');
            baseParams.delete('playerId');
            baseParams.delete('tab');
            baseParams.delete('targetPlayerId');
        }

        const query = baseParams.toString();
        const hash = this._buildRouteHash(route.screen, route.data);
        return window.location.pathname + (query ? '?' + query : '') + hash;
    },

    _parseRouteFromUrl() {
        const params = new URLSearchParams(window.location.search || '');
        const screen = params.get('screen') || 'home';

        if (screen === 'season-detail') {
            return this._normalizeRoute(screen, params.get('seasonId'));
        }

        if (screen === 'profile') {
            return this._normalizeRoute(screen, params.get('playerId'));
        }

        if (screen === 'global-lb') {
            const tab = params.get('tab');
            return this._normalizeRoute(screen, tab === 'seasonal' ? { tab: 'seasonal' } : null);
        }

        if (screen === 'theft') {
            return this._normalizeRoute(screen, {
                seasonId: params.get('seasonId'),
                targetPlayerId: params.get('targetPlayerId')
            });
        }

        return this._normalizeRoute(screen, null);
    },

    _buildRouteHash(screen, data) {
        const route = this._normalizeRoute(screen, data);
        if (route.screen === 'home') return '';

        const params = new URLSearchParams();
        params.set('screen', route.screen);

        if (route.screen === 'season-detail') {
            params.set('seasonId', String(route.data));
        } else if (route.screen === 'profile') {
            params.set('playerId', String(route.data));
        } else if (route.screen === 'global-lb' && route.data && route.data.tab === 'seasonal') {
            params.set('tab', 'seasonal');
        } else if (route.screen === 'theft' && route.data) {
            params.set('seasonId', String(route.data.seasonId));
            params.set('targetPlayerId', String(route.data.targetPlayerId));
        }

        const hashBody = params.toString();
        return hashBody ? '#!' + hashBody : '';
    },

    _parseRouteFromHash() {
        const hash = window.location.hash || '';
        if (!hash) return null;

        const raw = hash.startsWith('#!') ? hash.slice(2) : hash.slice(1);
        if (!raw) return null;

        const params = new URLSearchParams(raw);
        const screen = params.get('screen');
        if (!screen) return null;

        if (screen === 'season-detail') {
            return this._normalizeRoute(screen, params.get('seasonId'));
        }

        if (screen === 'profile') {
            return this._normalizeRoute(screen, params.get('playerId'));
        }

        if (screen === 'global-lb') {
            const tab = params.get('tab');
            return this._normalizeRoute(screen, tab === 'seasonal' ? { tab: 'seasonal' } : null);
        }

        if (screen === 'theft') {
            return this._normalizeRoute(screen, {
                seasonId: params.get('seasonId'),
                targetPlayerId: params.get('targetPlayerId')
            });
        }

        return this._normalizeRoute(screen, null);
    },

    _routeFromHistoryState(state) {
        if (!state || !state.tmcRoute) return null;
        const route = state.tmcRoute;
        if (!route || !route.screen) return null;
        return this._normalizeRoute(route.screen, route.data);
    },

    _routesEqual(a, b) {
        if (!a || !b) return false;
        if (a.screen !== b.screen) return false;
        return JSON.stringify(a.data !== undefined ? a.data : null) === JSON.stringify(b.data !== undefined ? b.data : null);
    },

    // ==================== ROUTE PERSISTENCE ====================
    // Save the current route to localStorage so page refresh returns the
    // player to the same screen they were on.  The 'auth' screen is never
    // persisted – on refresh an unauthenticated visitor always sees home.
    _saveRoute(screen, data) {
        const route = this._normalizeRoute(screen, data);

        if (route.screen === 'auth') {
            try { localStorage.removeItem('tmc_route'); } catch (e) {}
            return;
        }
        try {
            localStorage.setItem('tmc_route', JSON.stringify({ screen: route.screen, data: route.data !== undefined ? route.data : null }));
        } catch (e) {}
    },

    _loadRoute() {
        try {
            const raw = localStorage.getItem('tmc_route');
            if (!raw) return null;
            const parsed = JSON.parse(raw);
            if (!parsed || !parsed.screen) return null;
            return this._normalizeRoute(parsed.screen, parsed.data !== undefined ? parsed.data : null);
        } catch (e) { return null; }
    },

    // ==================== BOOST COUNTDOWNS ====================
    // Sync active-boost countdowns using the absolute wall-clock expiry
    // timestamp (expires_at_real) returned by the server.  This allows the
    // client to compute accurate remaining time at any point, including after
    // a page refresh or idle/reconnect period.

    _getBoostKey(boost) {
        return boost.id !== undefined ? boost.id : boost.boost_id;
    },

    _getPrimaryBoost() {
        const boosts = this.state.player && this.state.player.active_boosts;
        if (!boosts || !Array.isArray(boosts.self) || boosts.self.length === 0) return null;
        return boosts.self[0] || null;
    },

    syncBoostCountdowns() {
        const activeBoost = this._getPrimaryBoost();
        this.state.boostCountdowns = {};
        if (!activeBoost) return;

        const key = this._getBoostKey(activeBoost);
        if (key === undefined || key === null) return;
        this.state.boostCountdowns[key] = {
            expiresAtReal: parseInt(activeBoost.expires_at_real) || 0
        };
    },

    syncFreezeCountdown() {
        const p = this.state.player;
        const freeze = p && p.participation ? p.participation.freeze : null;
        if (!freeze || !freeze.is_frozen) {
            this.state.freezeCountdown = null;
            return;
        }

        const remainingSeconds = Math.max(0, parseInt(freeze.remaining_real_seconds, 10) || 0);
        this.state.freezeCountdown = {
            remainingSeconds,
            syncedAtMs: Date.now()
        };
    },

    getLiveFreezeRemainingSeconds() {
        const freeze = this.state.freezeCountdown;
        if (!freeze) return 0;
        const elapsedSeconds = Math.floor((Date.now() - freeze.syncedAtMs) / 1000);
        return Math.max(0, freeze.remainingSeconds - elapsedSeconds);
    },

    _formatFreezeTimeLeft(remainingSeconds) {
        return remainingSeconds > 0 ? this.formatSecondsRemaining(remainingSeconds) : 'Expiring\u2026';
    },

    // Compute live remaining seconds for a boost using the authoritative
    // wall-clock expiry timestamp.  Returns 0 (never negative) once expired.
    getLiveBoostRemainingSeconds(boost) {
        const key = this._getBoostKey(boost);
        const entry = key !== undefined ? this.state.boostCountdowns[key] : null;
        const expiresAtReal = entry ? entry.expiresAtReal
            : (parseInt(boost.expires_at_real) || 0);
        if (!expiresAtReal) return 0;
        return Math.max(0, expiresAtReal - Math.floor(Date.now() / 1000));
    },

    _formatBoostTimeLeft(remainingSeconds) {
        return remainingSeconds > 0 ? this.formatBoostSecondsRemaining(remainingSeconds) : 'Expiring\u2026';
    },

    _getCountdownExpiryUnix(rawValue, fallbackRemainingSeconds = 0) {
        const parsed = parseInt(rawValue, 10) || 0;
        if (parsed > 0) return parsed;
        const remaining = Math.max(0, parseInt(fallbackRemainingSeconds, 10) || 0);
        if (remaining <= 0) return 0;
        return Math.floor(Date.now() / 1000) + remaining;
    },

    _renderUbiTimerIndicator(options = {}) {
        const boostPercent = Number(options.boostPercent || 0);
        const hasBoost = !!options.hasBoost;
        const boostExpiresAtReal = parseInt(options.boostExpiresAtReal, 10) || 0;
        const boostId = options.boostId;
        const freeze = options.freeze || null;
        const isFrozen = !!(freeze && freeze.is_frozen);
        const freezeExpiresAtReal = this._getCountdownExpiryUnix(
            freeze ? freeze.expires_at_real : 0,
            freeze ? freeze.remaining_real_seconds : 0
        );

        if (!hasBoost && !isFrozen) return '';

        const leftBits = [];
        if (hasBoost) {
            leftBits.push(`<span class="ubi-boost-percent">+${boostPercent.toFixed(1)}%</span>`);
        }
        if (isFrozen) {
            leftBits.push('<span class="ubi-frozen-label">Frozen</span>');
        }

        const rightBits = [];
        if (isFrozen && freezeExpiresAtReal > 0) {
            const freezeRemaining = Math.max(0, freezeExpiresAtReal - Math.floor(Date.now() / 1000));
            rightBits.push(`<span class="ubi-timer ubi-freeze-time js-freeze-time" data-expires-at-real="${freezeExpiresAtReal}">${this._formatFreezeTimeLeft(freezeRemaining)}</span>`);
        }
        if (hasBoost && boostExpiresAtReal > 0) {
            const boostRemaining = Math.max(0, boostExpiresAtReal - Math.floor(Date.now() / 1000));
            const boostAttr = (boostId !== undefined && boostId !== null) ? ` data-boost-id="${boostId}"` : '';
            rightBits.push(`<span class="ubi-timer ubi-boost-time js-boost-time" data-expires-at-real="${boostExpiresAtReal}"${boostAttr}>${this._formatBoostTimeLeft(boostRemaining)}</span>`);
        }

        return `<div class="ubi-timer-indicator">
            <div class="ubi-indicator-left">${leftBits.join('')}</div>
            <div class="ubi-indicator-right">${rightBits.join('')}</div>
        </div>`;
    },

    formatBoostSecondsRemaining(seconds) {
        const total = Math.max(0, parseInt(seconds, 10) || 0);
        if (total <= 0) return 'Ended';

        const days = Math.floor(total / 86400);
        const hours = Math.floor((total % 86400) / 3600);
        const minutes = Math.floor((total % 3600) / 60);
        const secs = total % 60;

        if (days > 0) return `${days}d ${hours}h ${minutes}m ${secs}s`;
        if (hours > 0) return `${hours}h ${minutes}m ${secs}s`;
        if (minutes > 0) return `${minutes}m ${secs}s`;
        return `${secs}s`;
    },

    // Called every second by the realtime interval to update boost remaining-
    // time labels without a full re-render.
    _tickBoostCountdowns() {
        const nowUnix = Math.floor(Date.now() / 1000);
        document.querySelectorAll('.js-boost-time[data-expires-at-real]').forEach((el) => {
            const expiresAtReal = parseInt(el.getAttribute('data-expires-at-real'), 10) || 0;
            if (expiresAtReal <= 0) return;
            const remaining = Math.max(0, expiresAtReal - nowUnix);
            el.textContent = this._formatBoostTimeLeft(remaining);
        });

        this._tickTimePurchaseBoostSelector();
    },

    _tickTimePurchaseBoostSelector() {
        if (!this._timePurchaseFlowOpen || this._timePurchaseStep < 2) return;

        const selectedTier = this._selectedTimeSigilTier;
        if (!selectedTier) return;

        const candidates = this.getTimePurchaseCandidates(selectedTier);
        const prevSelected = this._selectedTimeBoostId;
        const selectedBoostId = candidates.some(c => c.boostId === prevSelected)
            ? prevSelected
            : (candidates.length > 0 ? candidates[0].boostId : null);
        this._selectedTimeBoostId = selectedBoostId;

        const toggle = document.getElementById('time-boost-picker-toggle');
        if (toggle) {
            if (selectedBoostId) {
                const selectedCandidate = candidates.find(c => c.boostId === selectedBoostId);
                if (selectedCandidate) {
                    toggle.textContent = `${selectedCandidate.boostName} (${this.formatBoostSecondsRemaining(selectedCandidate.remainingSeconds)} left)`;
                }
            } else {
                toggle.textContent = candidates.length > 0 ? 'Choose boost' : 'No active boosts eligible';
            }
            toggle.disabled = candidates.length === 0;
        }

        candidates.forEach((c) => {
            const timerEl = document.querySelector(`[data-boost-timer-for="${c.boostId}"]`);
            if (timerEl) {
                timerEl.textContent = `${this.formatBoostSecondsRemaining(c.remainingSeconds)} left`;
            }
        });

        document.querySelectorAll('.time-boost-menu-item').forEach((item) => {
            const id = parseInt(item.getAttribute('data-boost-id'), 10) || 0;
            item.classList.toggle('is-selected', id === selectedBoostId);
        });

        const applyBtn = document.getElementById('time-apply-btn');
        if (applyBtn) {
            applyBtn.disabled = candidates.length === 0;
            applyBtn.title = candidates.length === 0 ? 'No active boosts eligible for this +Time action' : '';
        }
    },

    _tickFreezeCountdowns() {
        const nowUnix = Math.floor(Date.now() / 1000);
        document.querySelectorAll('.js-freeze-time[data-expires-at-real]').forEach((el) => {
            const expiresAtReal = parseInt(el.getAttribute('data-expires-at-real'), 10) || 0;
            if (expiresAtReal <= 0) return;
            const remaining = Math.max(0, expiresAtReal - nowUnix);
            el.textContent = this._formatFreezeTimeLeft(remaining);
        });

        const p = this.state.player;
        const freeze = p && p.participation ? p.participation.freeze : null;
        if (!freeze || !freeze.is_frozen) return;

        const freezeTimeText = this._formatFreezeTimeLeft(this.getLiveFreezeRemainingSeconds());
        const hudRateLabel = document.querySelector('.hud-rate .hud-label');
        if (hudRateLabel) {
            hudRateLabel.textContent = `Rate Frozen (${freezeTimeText})`;
        }
    },

    // ==================== RENDER: USER AREA ====================
    renderUserArea() {
        const area = document.getElementById('user-area');
        if (this.state.player) {
            area.innerHTML = `
                <div class="user-info">
                    <span class="user-global-stars" title="Global Stars">&#11088; ${this.formatNumber(this.state.player.global_stars)}</span>
                    <span class="user-handle" onclick="TMC.navigate('profile', ${this.state.player.player_id})">${this.escapeHtml(this.state.player.handle)}</span>
                    <button class="btn btn-sm btn-outline" onclick="TMC.logout()">Logout</button>
                </div>
            `;
        } else {
            area.innerHTML = `
                <button class="btn btn-primary btn-sm" onclick="TMC.navigate('auth')">Login / Register</button>
            `;
        }
    },

    // ==================== RENDER: HOME ====================
    renderHome() {
        const cta = document.getElementById('hero-cta');
        if (this.state.player) {
            if (this.state.player.joined_season_id) {
                cta.innerHTML = `
                    <button class="btn btn-primary btn-lg" onclick="TMC.navigate('season-detail', ${this.state.player.joined_season_id})">
                        Go to Your Season
                    </button>
                `;
            } else {
                cta.innerHTML = `
                    <button class="btn btn-primary btn-lg" onclick="TMC.navigate('seasons')">
                        Browse Seasons
                    </button>
                `;
            }
        } else {
            cta.innerHTML = `
                <button class="btn btn-primary btn-lg" onclick="TMC.navigate('auth')">
                    Get Started
                </button>
            `;
        }
    },

    // ==================== RENDER: HUD ====================
    updateHUD() {
        const hud = document.getElementById('player-hud');
        const p = this.state.player;
        if (!p || !p.player_id || !p.participation) {
            hud.style.display = 'none';
            return;
        }
        hud.style.display = '';
        document.getElementById('hud-coins').textContent = this.formatNumber(p.participation.coins);
        document.getElementById('hud-seasonal-stars').textContent = this.formatNumber(p.participation.seasonal_stars);
        const totalSigils = p.participation.sigils.reduce((a, b) => a + b, 0);
        document.getElementById('hud-sigils').textContent = totalSigils;
        const ratePerTick = Number(p.participation.rate_per_tick || 0);
        const sinkPerTick = Number(p.participation.hoarding_sink_per_tick || 0);
        // Bug fix: do NOT use `|| ratePerTick` here – when net_rate_per_tick is exactly 0
        // (sink fully absorbs gross), the falsy fallback would display the gross rate as the
        // net rate, misleading users into thinking they are earning coins when they are not.
        const rawNet = p.participation.net_rate_per_tick;
        const netRatePerTick = (rawNet !== undefined && rawNet !== null) ? Number(rawNet) : ratePerTick;
        const rateEl = document.getElementById('hud-rate');
        const rateLabelEl = document.querySelector('.hud-rate .hud-label');
        const freeze = p.participation.freeze || { is_frozen: false };
        if (rateEl) {
            if (freeze.is_frozen) {
                rateEl.textContent = '0';
                rateEl.classList.add('rate-frozen-active');
            } else {
                rateEl.textContent = this.formatNumber(ratePerTick);
                rateEl.classList.remove('rate-frozen-active');
            }
        }
        if (rateLabelEl) {
            if (freeze.is_frozen) {
                const freezeTime = this._formatFreezeTimeLeft(this.getLiveFreezeRemainingSeconds());
                rateLabelEl.textContent = `Rate Frozen (${freezeTime})`;
            } else if (sinkPerTick > 0) {
                rateLabelEl.textContent = `Rate (Gross, -${this.formatNumber(sinkPerTick)} sink, Net ${this.formatNumber(netRatePerTick)})`;
            } else {
                rateLabelEl.textContent = 'Rate (Gross)';
            }
        }

        // Boost total modifier only
        const boosts = p.active_boosts || { self: [], total_modifier_percent: 0 };
        const totalBoostPercent = Number(boosts.total_modifier_percent || 0);
        const boostEl = document.getElementById('hud-boosts');
        if (boostEl) {
            boostEl.textContent = `${totalBoostPercent}%`;
            if (totalBoostPercent > 0) {
                boostEl.className = 'hud-value boost-active';
            } else {
                boostEl.className = 'hud-value';
            }
        }

        // Check for new sigil drops and show notification
        this.checkSigilDropNotifications(p);
    },

    tickRealtimeViews() {
        if (this.state.currentScreen === 'seasons') {
            const timerValues = document.querySelectorAll('.season-timer-value');
            timerValues.forEach((el) => {
                const seasonId = el.getAttribute('data-season-id');
                const season = this.state.seasons.find(s => s.season_id == seasonId);
                if (season) el.textContent = this.getSeasonTimerText(season);
            });
        }

        if (this.state.currentScreen === 'season-detail' && this.state.currentSeason) {
            const season = this.state.seasons.find(s => s.season_id == this.state.currentSeason);
            const timerValue = document.querySelector('.timer-value');
            if (season && timerValue) {
                timerValue.textContent = this.getSeasonTimerText(season);
            }
        }

        this._tickBoostCountdowns();
        this._tickFreezeCountdowns();
    },

    syncSeasonCountdowns(seasons) {
        if (!Array.isArray(seasons)) return;
        const syncedAtMs = Date.now();
        seasons.forEach((season) => {
            if (!season || season.season_id === undefined || season.season_id === null) return;
            if (season.time_remaining_real_seconds === undefined || season.time_remaining_real_seconds === null) return;
            const remainingSeconds = Math.max(0, parseInt(season.time_remaining_real_seconds, 10) || 0);
            this.state.seasonCountdowns[season.season_id] = {
                remainingSeconds,
                syncedAtMs
            };
        });
    },

    getLiveSeasonSeconds(season) {
        if (!season || season.season_id === undefined || season.season_id === null) return null;
        const countdown = this.state.seasonCountdowns[season.season_id];
        if (!countdown) return null;
        const elapsedSeconds = Math.floor((Date.now() - countdown.syncedAtMs) / 1000);
        return Math.max(0, countdown.remainingSeconds - elapsedSeconds);
    },

    formatSecondsRemaining(seconds) {
        const total = Math.max(0, parseInt(seconds, 10) || 0);
        if (total <= 0) return 'Ended';

        const days = Math.floor(total / 86400);
        const hours = Math.floor((total % 86400) / 3600);
        const minutes = Math.floor((total % 3600) / 60);
        const secs = total % 60;

        if (days > 0) return `${days}d ${hours}h ${minutes}m`;
        if (hours > 0) return `${hours}h ${minutes}m ${secs}s`;
        if (minutes > 0) return `${minutes}m ${secs}s`;
        return `${secs}s`;
    },

    getSeasonCountdownMode(season) {
        if (season && season.countdown_mode) return season.countdown_mode;
        const status = season && (season.computed_status || season.status);
        if (status === 'Scheduled') return 'scheduled';
        if (status === 'Active' || status === 'Blackout') return 'running';
        return 'ended';
    },

    getSeasonCardTimerLabel(season) {
        const mode = this.getSeasonCountdownMode(season);
        if (mode === 'scheduled') return 'Begins in';
        if (mode === 'running') return 'Time Left';
        return 'Ended';
    },

    getSeasonDetailTimerLabel(season) {
        const mode = this.getSeasonCountdownMode(season);
        if (mode === 'scheduled') return 'Begins in';
        if (mode === 'running') return 'Time Remaining';
        return 'Ended';
    },

    getSeasonTimerText(season) {
        const liveSeconds = this.getLiveSeasonSeconds(season);
        if (liveSeconds !== null) return this.formatSecondsRemaining(liveSeconds);
        return season.time_remaining_formatted || 'Ended';
    },

    // Track last known drop count to detect new drops
    _lastDropCount: 0,
    _notificationOutsideHandler: null,
    checkSigilDropNotifications(p) {
        const drops = p.recent_drops || [];
        const currentCount = drops.length;
        // Gameplay notifications are persisted in the notification center.
        this._lastDropCount = currentCount;
    },

    checkIdleModal() {
        const modal = document.getElementById('idle-modal');
        if (this.state.player && this.state.player.idle_modal_active) {
            modal.style.display = 'flex';
        } else {
            modal.style.display = 'none';
        }
    },

    async idleAck() {
        const result = await this.api('idle_ack');
        if (result.success) {
            document.getElementById('idle-modal').style.display = 'none';
            await this.refreshGameState();
        }
    },

    // ==================== RENDER: SEASONS ====================
    renderSeasons() {
        const container = document.getElementById('seasons-list');
        if (!this.state.seasons || this.state.seasons.length === 0) {
            container.innerHTML = '<div class="empty-state"><p>No seasons available yet. Check back soon!</p></div>';
            return;
        }

        let html = '';
        for (const s of this.state.seasons) {
            const status = s.computed_status || s.status;
            const statusClass = status.toLowerCase();
            const timerLabel = this.getSeasonCardTimerLabel(s);
            const timerText = this.getSeasonTimerText(s);
            const canJoin = this.state.player && !this.state.player.joined_season_id &&
                           (status === 'Active' || status === 'Blackout');
            const isMyseason = this.state.player && this.state.player.joined_season_id == s.season_id;
            const playerCount = s.player_count || 0;

            html += `
                <div class="season-card ${statusClass} ${isMyseason ? 'my-season' : ''}" onclick="TMC.navigate('season-detail', ${s.season_id})">
                    <div class="season-card-header">
                        <span class="season-id">Season #${s.season_id}</span>
                        <span class="season-status badge badge-${statusClass}">${status}</span>
                    </div>
                    <div class="season-card-body">
                        <div class="season-stat">
                            <span class="stat-label">Players</span>
                            <span class="stat-value">${playerCount}</span>
                        </div>
                        <div class="season-stat">
                            <span class="stat-label">Star Price</span>
                            <span class="stat-value">${this.formatNumber(s.current_star_price)} coins</span>
                        </div>
                        <div class="season-stat">
                            <span class="stat-label season-timer-label" data-season-id="${s.season_id}">${timerLabel}</span>
                            <span class="stat-value season-timer-value" data-season-id="${s.season_id}">${timerText}</span>
                        </div>
                        <div class="season-stat">
                            <span class="stat-label">Coin Supply</span>
                            <span class="stat-value">${this.formatNumber(s.total_coins_supply)}</span>
                        </div>
                    </div>
                    <div class="season-card-footer">
                        ${isMyseason ? '<span class="badge badge-active">YOUR SEASON</span>' : ''}
                        ${canJoin ? '<button class="btn btn-primary btn-sm" onclick="event.stopPropagation(); TMC.joinSeason(' + s.season_id + ')">Join</button>' : ''}
                        ${status === 'Expired' ? '<span class="badge badge-expired">Completed</span>' : ''}
                    </div>
                </div>
            `;
        }
        container.innerHTML = html;
    },

    // ==================== SEASON DETAIL ====================
    updateSeasonDetailLive() {
        // Update dynamic values without re-rendering the entire season detail
        const seasonId = this.state.currentSeason;
        const season = this.state.seasons.find(s => s.season_id == seasonId);
        if (!season) return;

        // Update timer and label for all viewers (including non-participants)
        const timerLabel = document.querySelector('.timer-label');
        const timerValue = document.querySelector('.timer-value');
        if (timerLabel) timerLabel.textContent = this.getSeasonDetailTimerLabel(season);
        if (timerValue) timerValue.textContent = this.getSeasonTimerText(season);

        const p = this.state.player;
        if (!p || !p.participation) {
            this.loadSeasonLeaderboard(seasonId);
            return;
        }

        const isBlackout = (season.computed_status || season.status) === 'Blackout';

        // Update economy bar values if they exist
        const econValues = document.querySelectorAll('.econ-value');
        if (econValues.length >= 3) {
            econValues[0].textContent = this.formatNumber(season.current_star_price) + ' coins';
            econValues[1].textContent = this.formatNumber(season.total_coins_supply);
            econValues[2].textContent = season.player_count || 0;
        }

        // Update coin display in purchase panel
        const panelInfos = document.querySelectorAll('.panel-info');
        panelInfos.forEach(el => {
            if (el.textContent.includes('Current price:')) {
                el.innerHTML = `Current price: <strong>${this.formatNumber(season.current_star_price)} coins</strong> per star`;
            }
        });

        // Update sigil counts
        const sigilCounts = document.querySelectorAll('.sigil-count');
        const visibleSigils = this.getVisibleSigils(p.participation);
        if (sigilCounts.length === visibleSigils.length) {
            visibleSigils.forEach((row, i) => {
                sigilCounts[i].textContent = row.count;
            });
        }

        // Update lock-in star count and disabled state
        const lockInBtn = document.querySelector('.panel-lockin .btn-danger');
        if (lockInBtn) {
            lockInBtn.textContent = `Lock-In (${this.formatNumber(p.participation.seasonal_stars)} Stars)`;
            lockInBtn.disabled = !p.can_lock_in || isBlackout;
        }

        // Keep forge in sync with live inventory changes, including adding/removing it.
        const combineRecipes = Array.isArray(p.participation.combine_recipes) ? p.participation.combine_recipes : [];
        this.reconcileSigilForge(combineRecipes, isBlackout);

        // Re-render the active boosts panel with fresh data from the latest poll
        this.renderActiveBoosts();

        // Refresh leaderboard
        this.loadSeasonLeaderboard(seasonId);
    },

    async loadSeasonDetail(seasonId) {
        const detail = await this.api('season_detail', { season_id: seasonId });
        if (detail.error) {
            this.toast(detail.error, 'error', { category: 'error_action' });
            document.getElementById('season-detail-content').innerHTML =
                `<div class="error-state"><p>Season details are temporarily unavailable.</p></div>`;
            return;
        }
        this.renderSeasonDetail(seasonId, detail);
    },

    renderSeasonDetail(seasonId, detail) {
        if (!detail) {
            // Use cached data from game state
            detail = this.state.seasons.find(s => s.season_id == seasonId);
            if (!detail) return;
        }

        const p = this.state.player;
        const isParticipating = p && p.joined_season_id == seasonId;
        const part = (isParticipating && p) ? (p.participation || null) : null;
        const status = detail.computed_status || detail.status;
        const isBlackout = status === 'Blackout';
        const isExpired = status === 'Expired';
        const timerLabel = this.getSeasonDetailTimerLabel(detail);
        const combineRecipes = (p && p.participation && Array.isArray(p.participation.combine_recipes))
            ? p.participation.combine_recipes
            : [];
        const visibleCombineRecipes = combineRecipes.filter((recipe) => !!recipe.can_combine);
        const sigilForgeHtml = this.renderSigilForgeSection(visibleCombineRecipes, isBlackout);
        const boosts = p ? p.active_boosts : null;
        const primaryBoost = boosts && Array.isArray(boosts.self) && boosts.self.length > 0 ? boosts.self[0] : null;
        const freeze = part ? part.freeze : null;
        const sigilsIndicatorHtml = this._renderUbiTimerIndicator({
            hasBoost: !!primaryBoost,
            boostPercent: Number(boosts?.total_modifier_percent || 0),
            boostExpiresAtReal: primaryBoost ? this._getCountdownExpiryUnix(primaryBoost.expires_at_real, primaryBoost.remaining_real_seconds) : 0,
            boostId: primaryBoost ? this._getBoostKey(primaryBoost) : null,
            freeze
        });

        let html = `
            <div class="season-header">
                <div class="season-header-left">
                    <h2>Season #${seasonId}</h2>
                    <span class="badge badge-${status.toLowerCase()} badge-lg">${status}</span>
                    ${isBlackout ? '<span class="badge badge-warning badge-lg">BLACKOUT - No new actions</span>' : ''}
                </div>
                <div class="season-header-right">
                    <div class="season-timer">
                        <span class="timer-label">${timerLabel}</span>
                        <span class="timer-value">${this.getSeasonTimerText(detail)}</span>
                    </div>
                </div>
            </div>

            <div class="season-economy-bar">
                <div class="economy-stat">
                    <span class="econ-label">Star Price</span>
                    <span class="econ-value">${this.formatNumber(detail.current_star_price)} coins</span>
                </div>
                <div class="economy-stat">
                    <span class="econ-label">Coin Supply</span>
                    <span class="econ-value">${this.formatNumber(detail.total_coins_supply)}</span>
                </div>
                <div class="economy-stat">
                    <span class="econ-label">Players</span>
                    <span class="econ-value">${detail.player_count || 0}</span>
                </div>
            </div>
        `;

        // Action panel (if participating)
        if (isParticipating && !isExpired && part) {
            html += `
                <div class="action-panels">
                    <!-- Purchase Stars Panel -->
                    <div class="action-panel">
                        <h3>Purchase Seasonal Stars</h3>
                        <p class="panel-info">Current price: <strong>${this.formatNumber(detail.current_star_price)} coins</strong> per star</p>
                        <div class="action-row">
                            <input type="number" id="purchase-stars" min="1" placeholder="Star quantity" class="input-field" oninput="TMC.updatePurchaseEstimate()">
                            <button id="purchase-stars-btn" class="btn btn-primary" onclick="TMC.purchaseStarsGated()" ${isBlackout ? 'disabled' : ''}>Buy Stars</button>
                            <button id="purchase-max-btn" class="btn btn-outline" onclick="TMC.buyMaxStars()" ${isBlackout ? 'disabled' : ''}>Buy Max</button>
                        </div>
                        <p id="purchase-estimate" class="panel-info">Enter a star quantity to see estimated coin cost.</p>
                    </div>

                    <!-- Sigils Panel -->
                    <div class="action-panel">
                        <h3>Sigils</h3>
                        ${sigilsIndicatorHtml}
                        <p class="panel-info">Sigil cap: ${part.sigils_total}/${(part.sigil_caps && part.sigil_caps.total) ? part.sigil_caps.total : 25}</p>
                        <div class="sigil-inventory-row">
                            <div class="sigil-display">
                                ${this.getVisibleSigils(part).map((sigil) => `
                                    <div class="sigil-item tier-${sigil.tier} ${sigil.tier <= 5 && sigil.count > 0 && !isBlackout ? 'is-clickable' : ''} ${this._selectedSigilActionTier === sigil.tier ? 'is-selected' : ''}"
                                        ${sigil.tier <= 5 && sigil.count > 0 && !isBlackout ? `onclick="TMC.openSigilActionPicker(${sigil.tier})"` : ''}>
                                        <span class="sigil-tier">T${sigil.tier}</span>
                                        <span class="sigil-count">${sigil.count}</span>
                                    </div>
                                `).join('')}
                            </div>
                            <div class="sigil-side-actions ${this._selectedSigilActionTier ? 'active' : 'inactive'}">
                                <button class="btn btn-sm btn-primary"
                                    onclick="TMC.spendSigilBoostGated(${this._selectedSigilActionTier || 0}, 'power')"
                                    ${this._selectedSigilActionTier && !isBlackout ? '' : 'disabled'}>+Power</button>
                                <button class="btn btn-sm btn-outline"
                                    onclick="TMC.spendSigilBoostGated(${this._selectedSigilActionTier || 0}, 'time')"
                                    ${this._selectedSigilActionTier && !isBlackout ? '' : 'disabled'}>+Time</button>
                            </div>
                        </div>
                        ${sigilForgeHtml}
                    </div>

                    <!-- Lock-In Panel -->
                    <div class="action-panel panel-lockin">
                        <h3>Lock-In</h3>
                        <button class="btn btn-danger btn-lg" onclick="TMC.confirmLockIn()" 
                            ${!p.can_lock_in || isBlackout ? 'disabled' : ''}>
                            Lock-In (${this.formatNumber(part.seasonal_stars)} Stars)
                        </button>
                    </div>
                </div>
            `;
        } else if (!isParticipating && !isExpired && this.state.player) {
            html += `
                <div class="join-panel">
                    <h3>Join This Season</h3>
                    <p>Start earning Coins through UBI and compete for the top of the leaderboard.</p>
                    ${this.state.player.joined_season_id ? 
                        '<p class="panel-warning">You are already participating in another season. Lock-In or wait for it to end first.</p>' :
                        `<button class="btn btn-primary btn-lg" onclick="TMC.joinSeason(${seasonId})">Join Season #${seasonId}</button>`
                    }
                </div>
            `;
        }

        // Leaderboard
        html += `
            <div class="season-leaderboard">
                <h3><button class="season-lb-title-link" type="button" onclick="TMC.openSeasonalLeaderboardTab()">Season Leaderboard</button></h3>
                <table class="leaderboard-table">
                    <thead>
                        <tr>
                            <th>Rate</th>
                            <th>Player</th>
                            <th>Stars</th>
                            <th>Boost</th>
                            <th>Coins</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="season-lb-body">
                    </tbody>
                </table>
                <div id="season-lb-toggle-wrap" class="leaderboard-toggle-wrap" style="display:none;">
                    <button id="season-lb-toggle-btn" type="button" class="leaderboard-toggle-btn" onclick="TMC.toggleSeasonDetailLeaderboard()"></button>
                </div>
                <div id="season-lb-empty" class="empty-state" style="display:none;">
                    <p>No ranked players yet.</p>
                </div>
            </div>
        `;

        document.getElementById('season-detail-content').innerHTML = html;

        // Load leaderboard
        this.loadSeasonLeaderboard(seasonId);

        this.updatePurchaseEstimate();
    },

    async loadSeasonLeaderboard(seasonId) {
        const lb = await this.api('leaderboard', { season_id: seasonId, limit: 20 });
        const body = document.getElementById('season-lb-body');
        const empty = document.getElementById('season-lb-empty');
        const toggleWrap = document.getElementById('season-lb-toggle-wrap');
        const toggleBtn = document.getElementById('season-lb-toggle-btn');
        if (!body) return;

        if (!Array.isArray(lb) || lb.length === 0 || lb.error) {
            body.innerHTML = '';
            if (empty) empty.style.display = '';
            if (toggleWrap) toggleWrap.style.display = 'none';
            return;
        }
        if (empty) empty.style.display = 'none';

        const capped = lb.slice(0, 20);
        const rows = this._seasonDetailLeaderboardExpanded ? capped : capped.slice(0, 3);
        body.innerHTML = this.renderSeasonLeaderboardRows(rows, false, { firstCol: 'rate', showRateColumn: false });

        if (toggleWrap && toggleBtn) {
            if (lb.length > 3) {
                toggleWrap.style.display = '';
                toggleBtn.textContent = this._seasonDetailLeaderboardExpanded
                    ? '▴ Hide to Top 3'
                    : '▾ Show Top 20';
            } else {
                toggleWrap.style.display = 'none';
            }
        }
    },

    toggleSeasonDetailLeaderboard() {
        this._seasonDetailLeaderboardExpanded = !this._seasonDetailLeaderboardExpanded;
        if (this.state.currentSeason) this.loadSeasonLeaderboard(this.state.currentSeason);
    },

    openSeasonalLeaderboardTab() {
        this.navigate('global-lb', { tab: 'seasonal' });
    },

    // ==================== ACTIONS ====================
    async joinSeason(seasonId) {
        if (!this.state.player) {
            this.navigate('auth');
            return;
        }
        const result = await this.api('season_join', { season_id: seasonId });
        if (result.error) {
            this.toast(result.error, 'error');
            return;
        }
        this.toast('Joined the season! Start earning Coins.', 'success', {
            category: 'season_join',
            payload: { season_id: Number(seasonId) || null }
        });
        await this.refreshGameState();
        this.navigate('season-detail', seasonId);
    },

    async purchaseStars() {
        const input = document.getElementById('purchase-stars');
        const starsRequested = parseInt(input.value);
        if (!starsRequested || starsRequested <= 0) {
            this.toast('Enter a valid star quantity.', 'error');
            return;
        }
        const result = await this.api('purchase_stars', { stars_requested: starsRequested });
        if (result.error) {
            this.toast(result.error, 'error');
            return;
        }
        this.toast(`Purchased ${this.formatNumber(result.stars_purchased)} stars for ${this.formatNumber(result.coins_spent)} coins!`, 'success', {
            category: 'purchase_star',
            payload: {
                stars_purchased: Number(result.stars_purchased) || 0,
                coins_spent: Number(result.coins_spent) || 0,
                season_id: Number(this.state.currentSeason) || null
            }
        });
        input.value = '';
        this.updatePurchaseEstimate();
        await this.refreshGameState();
    },

    async buyMaxStars() {
        const input = document.getElementById('purchase-stars');
        if (!input) return;

        const season = this.state.seasons.find(s => s.season_id == this.state.currentSeason);
        const starPrice = season ? parseInt(season.current_star_price, 10) : 0;
        const coinsOwned = this.state.player && this.state.player.participation ? this.state.player.participation.coins : 0;

        if (!starPrice || starPrice <= 0) {
            this.toast('Cannot calculate max stars right now.', 'error');
            return;
        }

        const maxStars = Math.floor(coinsOwned / starPrice);
        if (maxStars < 1) {
            this.toast('Not enough coins to buy 1 star at current price.', 'error');
            return;
        }

        input.value = maxStars;
        this.updatePurchaseEstimate();
        await this.purchaseStarsGated();
    },

    updatePurchaseEstimate() {
        const input = document.getElementById('purchase-stars');
        const estimateEl = document.getElementById('purchase-estimate');
        const buyButton = document.getElementById('purchase-stars-btn');
        const buyMaxButton = document.getElementById('purchase-max-btn');
        if (!input || !estimateEl) return;

        const starsRequested = parseInt(input.value, 10);
        const season = this.state.seasons.find(s => s.season_id == this.state.currentSeason);
        const status = season && season.computed_status ? season.computed_status : (season ? season.status : null);
        const isBlackout = status === 'Blackout';

        if (buyButton) {
            buyButton.disabled = isBlackout;
        }
        if (buyMaxButton) {
            buyMaxButton.disabled = isBlackout;
        }

        if (!starsRequested || starsRequested <= 0) {
            estimateEl.classList.remove('panel-warning');
            estimateEl.textContent = 'Enter a star quantity to see estimated coin cost.';
            return;
        }

        const starPrice = season ? parseInt(season.current_star_price, 10) : 0;
        if (!starPrice || starPrice <= 0) {
            if (buyButton && !isBlackout) buyButton.disabled = true;
            if (buyMaxButton && !isBlackout) buyMaxButton.disabled = true;
            estimateEl.classList.remove('panel-warning');
            estimateEl.textContent = 'Coin cost estimate unavailable right now.';
            return;
        }

        const coinsNeeded = starsRequested * starPrice;
        const coinsOwned = this.state.player && this.state.player.participation ? this.state.player.participation.coins : null;
        const maxStars = coinsOwned !== null ? Math.floor(coinsOwned / starPrice) : 0;

        if (buyMaxButton && !isBlackout) {
            buyMaxButton.disabled = maxStars < 1;
        }

        if (coinsOwned !== null && coinsNeeded > coinsOwned) {
            if (buyButton && !isBlackout) buyButton.disabled = true;
            estimateEl.classList.add('panel-warning');
            estimateEl.textContent = `Estimated cost: ${this.formatNumber(coinsNeeded)} coins (${this.formatNumber(starPrice)} each). You need ${this.formatNumber(coinsNeeded - coinsOwned)} more coins.`;
            return;
        }

        if (buyButton && !isBlackout) buyButton.disabled = false;
        estimateEl.classList.remove('panel-warning');
        estimateEl.textContent = `Estimated cost: ${this.formatNumber(coinsNeeded)} coins (${this.formatNumber(starPrice)} each).`;
    },

    getVisibleSigils(participation) {
        const sigils = Array.isArray(participation?.sigils) ? participation.sigils : [];
        const visible = [];
        for (let tier = 1; tier <= 5; tier++) {
            visible.push({ tier, count: Number(sigils[tier - 1] || 0) });
        }

        const hasTier6 = Number(sigils[5] || 0) > 0;
        if (hasTier6) {
            visible.push({ tier: 6, count: Number(sigils[5] || 0) });
        }

        return visible;
    },

    renderSigilForgeSection(visibleCombineRecipes, isBlackout) {
        if (!Array.isArray(visibleCombineRecipes) || visibleCombineRecipes.length === 0) {
            return '';
        }

        const combineAllDisabled = isBlackout || this._combineAllPending;
        return `
            <div class="sigil-combine-section">
                <div class="action-row">
                    <h4>Sigil Forge</h4>
                    <button id="combine-all-btn" class="btn btn-sm btn-primary"
                        onclick="TMC.combineAllSigils()"
                        ${combineAllDisabled ? 'disabled' : ''}>
                        Combine All
                    </button>
                </div>
                <div class="vault-grid">
                    ${visibleCombineRecipes.map((recipe) => `
                        <div class="vault-item tier-${recipe.from_tier}">
                            <span class="vault-tier">T${recipe.from_tier} x${recipe.required} to T${recipe.to_tier}</span>
                            <span class="vault-remaining">Owned: ${recipe.owned}</span>
                            <button class="btn btn-sm btn-primary"
                                onclick="TMC.combineSigil(${recipe.from_tier})"
                                ${isBlackout ? 'disabled' : ''}>
                                Combine
                            </button>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;
    },

    reconcileSigilForge(combineRecipes, isBlackout) {
        const visibleCombineRecipes = Array.isArray(combineRecipes)
            ? combineRecipes.filter((recipe) => !!recipe.can_combine)
            : [];

        const sigilPanel = document.querySelector('.sigil-inventory-row')?.closest('.action-panel');
        if (!sigilPanel) {
            return;
        }

        const existingForge = sigilPanel.querySelector('.sigil-combine-section');
        if (visibleCombineRecipes.length === 0) {
            if (existingForge) {
                existingForge.remove();
            }
            return;
        }

        const forgeHtml = this.renderSigilForgeSection(visibleCombineRecipes, isBlackout).trim();
        if (!existingForge) {
            const inventoryRow = sigilPanel.querySelector('.sigil-inventory-row');
            if (!inventoryRow) {
                return;
            }
            inventoryRow.insertAdjacentHTML('afterend', forgeHtml);
            return;
        }

        existingForge.outerHTML = forgeHtml;
    },

    async combineSigil(fromTier) {
        const result = await this.api('combine_sigil', { from_tier: fromTier });
        if (result.error) {
            this.toast(result.error, 'error', { category: 'error_action' });
            return;
        }
        this.toast(result.message || `Combined Tier ${fromTier} sigils.`, 'success', {
            category: 'sigil_combine',
            payload: {
                from_tier: Number(result.from_tier) || Number(fromTier),
                to_tier: Number(result.to_tier) || (Number(fromTier) + 1),
                consumed: Number(result.consumed) || 0,
                produced: Number(result.produced) || 1
            }
        });
        await this.refreshGameState();
        if (this.state.currentSeason) this.loadSeasonDetail(this.state.currentSeason);
    },

    async combineAllSigils() {
        if (this._combineAllPending) {
            return;
        }

        this._combineAllPending = true;
        if (this.state.currentScreen === 'season-detail' && this.state.currentSeason) {
            this.updateSeasonDetailLive();
        }

        try {
            const result = await this.api('combine_all_sigils', {});
            if (result.error) {
                this.toast(result.error, 'error', { category: 'error_action' });
                return;
            }

            const totalOperations = Number(result.total_operations || 0);
            this.toast(result.message || `Combined sigils across ${totalOperations} operation${totalOperations === 1 ? '' : 's'}.`, 'success', {
                category: 'sigil_combine',
                payload: {
                    total_operations: totalOperations,
                    total_consumed: Number(result.total_consumed || 0),
                    total_produced: Number(result.total_produced || 0)
                }
            });
            await this.refreshGameState();
            if (this.state.currentSeason) this.loadSeasonDetail(this.state.currentSeason);
        } finally {
            this._combineAllPending = false;
            if (this.state.currentScreen === 'season-detail' && this.state.currentSeason) {
                this.updateSeasonDetailLive();
            }
        }
    },

    openSigilActionPicker(tier) {
        const parsedTier = parseInt(tier, 10) || 0;
        if (parsedTier < 1 || parsedTier > 5) {
            return;
        }
        if (this._selectedSigilActionTier === parsedTier) {
            this._selectedSigilActionTier = null;
            this.loadSeasonDetail(this.state.currentSeason);
            return;
        }
        this._selectedSigilActionTier = parsedTier;
        this.loadSeasonDetail(this.state.currentSeason);
    },

    clearSigilActionPicker() {
        this._selectedSigilActionTier = null;
        this.loadSeasonDetail(this.state.currentSeason);
    },

    async freezeByPlayerId(targetPlayerId, refreshProfileId = null) {
        const result = await this.api('freeze_player_ubi', { target_player_id: targetPlayerId });
        if (result.error) {
            this.toast(result.error, 'error', { category: 'error_action' });
            return;
        }
        this.toast(result.message || 'Freeze applied.', 'success', {
            category: 'freeze_apply',
            payload: {
                target_player_id: Number(result.target_player_id) || Number(targetPlayerId),
                freeze_duration_ticks: Number(result.freeze_duration_ticks) || 0,
                expires_tick: Number(result.expires_tick) || null
            }
        });
        await this.refreshGameState();
        if (this.state.currentScreen === 'profile' && refreshProfileId) {
            this.loadProfile(refreshProfileId);
            return;
        }
        if (this.state.currentSeason) this.loadSeasonDetail(this.state.currentSeason);
    },

    async freezeByHandle() {
        const input = document.getElementById('freeze-target-handle');
        const targetHandle = input ? String(input.value || '').trim() : '';
        if (!targetHandle) {
            this.toast('Enter a target handle.', 'error', { category: 'error_validation' });
            return;
        }

        const result = await this.api('freeze_player_ubi', { target_handle: targetHandle });
        if (result.error) {
            this.toast(result.error, 'error', { category: 'error_action' });
            return;
        }
        this.toast(result.message || 'Freeze applied.', 'success', {
            category: 'freeze_apply',
            payload: {
                target_player_id: Number(result.target_player_id) || null,
                target_handle: result.target_handle || targetHandle,
                freeze_duration_ticks: Number(result.freeze_duration_ticks) || 0,
                expires_tick: Number(result.expires_tick) || null
            }
        });
        if (input) input.value = '';
        await this.refreshGameState();
        if (this.state.currentSeason) this.loadSeasonDetail(this.state.currentSeason);
    },

    async selfMeltFreeze(requestedTier = null) {
        let meltTier = parseInt(requestedTier, 10) || 0;
        if (!meltTier) {
            const select = document.getElementById('profile-melt-tier');
            meltTier = parseInt(select ? select.value : '0', 10) || 0;
        }

        const result = await this.api('self_melt_freeze', meltTier ? { requested_tier: meltTier } : {});
        if (result.error) {
            this.toast(result.error, 'error', { category: 'error_action' });
            return;
        }
        this.toast(result.message || 'Melt applied.', 'success', {
            category: 'freeze_melt',
            payload: {
                consumed_tier: Number(result.consumed_tier) || meltTier || null,
                reduction_ticks: Number(result.reduction_ticks) || 0,
                new_remaining_ticks: Number(result.new_remaining_ticks) || 0,
                expires_tick: Number(result.expires_tick) || null
            }
        });
        await this.refreshGameState();
        if (this.state.currentScreen === 'profile' && this.state.player) {
            this.loadProfile(this.state.player.player_id);
            return;
        }
        if (this.state.currentSeason) this.loadSeasonDetail(this.state.currentSeason);
    },

    // ==================== BOOSTS ====================
    _boostCatalog: null,
    _boostCatalogCollapsed: true,
    _selectedTimeSigilTier: null,
    _selectedTimeBoostId: null,
    _timePurchaseFlowOpen: false,
    _timePurchaseStep: 1,
    _timeTierPickerOpen: false,
    _timeBoostPickerOpen: false,
    _selectedSigilActionTier: null,

    async loadBoostCatalog() {
        const catalog = await this.api('boost_catalog');
        if (catalog.error) {
            this.toast(catalog.error, 'error');
            return;
        }
        this._boostCatalog = catalog;
        this._boostCatalogCollapsed = false;
        this.renderBoostCatalogToggle();
        this.renderBoostCatalog();
        this.renderActiveBoosts();
    },

    toggleBoostCatalog() {
        if (!this._boostCatalog) {
            this.loadBoostCatalog();
            return;
        }
        this._boostCatalogCollapsed = !this._boostCatalogCollapsed;
        this.renderBoostCatalogToggle();
        this.renderBoostCatalog();
    },

    renderBoostCatalogToggle() {
        const toggleBtn = document.getElementById('boost-catalog-toggle');
        if (!toggleBtn) return;

        if (!this._boostCatalog || this._boostCatalog.length === 0) {
            toggleBtn.textContent = 'Show Available Purchases';
            return;
        }

        toggleBtn.textContent = this._boostCatalogCollapsed ? 'Show Available Purchases' : 'Hide Available Purchases';
    },

    renderBoostCatalog() {
        const grid = document.getElementById('boost-catalog-grid');
        if (!grid || !this._boostCatalog) return;

        const p = this.state.player;
        const part = p ? p.participation : null;

        grid.classList.toggle('boost-catalog-collapsed', this._boostCatalogCollapsed);
        if (this._boostCatalogCollapsed) {
            grid.innerHTML = '';
            return;
        }

        const activeSelfBoosts = (p && p.active_boosts && Array.isArray(p.active_boosts.self)) ? p.active_boosts.self : [];
        const nowTick = parseInt((p && p.active_boosts && p.active_boosts.server_now) || 0, 10) || 0;
        const activeBoost = activeSelfBoosts[0] || null;
        const totalActiveModifierFp = activeBoost ? (parseInt(activeBoost.modifier_fp, 10) || 0) : 0;
        const purchaseCards = this._boostCatalog.map(b => {
            const tier = parseInt(b.tier_required);
            const hasSigil = part && part.sigils[tier - 1] >= parseInt(b.sigil_cost);
            const modPercent = (parseInt(b.modifier_fp) / 10000).toFixed(1);
            const durationTicks = parseInt(b.duration_ticks);
            const durationRealSeconds = parseInt(b.duration_real_seconds || 0, 10) || 0;
            const activeBoost = activeSelfBoosts.find((ab) => parseInt(ab.boost_id, 10) === parseInt(b.boost_id, 10)) || null;
            const currentModifierFp = activeBoost ? (parseInt(activeBoost.modifier_fp, 10) || 0) : 0;
            const powerCapFp = parseInt(b.power_cap_fp || 1000000, 10) || 1000000;
            const totalPowerCapFp = parseInt(b.total_power_cap_fp || 5000000, 10) || 5000000;
            const baseModifierFp = parseInt(b.base_modifier_fp || b.modifier_fp || 0, 10) || 0;
            const projectedModifierFp = currentModifierFp + baseModifierFp;
            const projectedTotalFp = totalActiveModifierFp - currentModifierFp + projectedModifierFp;
            const currentPowerPercent = (currentModifierFp / 10000).toFixed(1);
            const powerCapPercent = (powerCapFp / 10000).toFixed(1);
            const canBuyPower = !!hasSigil && projectedModifierFp <= powerCapFp && projectedTotalFp <= totalPowerCapFp;
            const description = this.getBoostDescription(b);
            const displayName = 'Boost';
            const durationLabel = durationRealSeconds > 0
                ? this.formatDurationFromSeconds(durationRealSeconds, 'short')
                : this.formatBoostDuration(durationTicks, 'short');
            const powerTitle = !hasSigil
                ? 'Not enough Sigils'
                : (projectedModifierFp > powerCapFp
                    ? 'This product is capped at +100% power'
                    : (projectedTotalFp > totalPowerCapFp ? 'Combined boost cap is +500%' : ''));

            if (!canBuyPower) {
                return '';
            }

            return `
                <div class="boost-card ${hasSigil ? '' : 'boost-locked'}">
                    <div class="boost-card-header">
                        <div class="boost-title">
                            <span class="boost-name">${this.escapeHtml(displayName)}</span>
                        </div>
                        <div class="boost-inline-meta">
                            <span class="boost-modifier">+${modPercent}% UBI</span>
                            <span class="boost-duration">${durationLabel}</span>
                        </div>
                        <span class="boost-have boost-have-inline">Power: +${currentPowerPercent}% / +${powerCapPercent}%</span>
                        <span class="boost-have boost-have-inline">(You have: ${part ? part.sigils[tier-1] : 0})</span>
                    </div>
                    ${description ? `<p class="boost-desc">${this.escapeHtml(description)}</p>` : ''}
                    <div class="action-row">
                        <button class="btn btn-sm ${canBuyPower ? 'btn-primary' : 'btn-outline'}"
                            onclick="TMC.purchaseBoostPowerGated(${b.boost_id})"
                            ${!canBuyPower ? `disabled title="${this.escapeHtml(powerTitle)}"` : ''}>
                            Power +${modPercent}%
                        </button>
                    </div>
                </div>
            `;
        }).join('');

        const spendableTiers = this.getSpendableTimeSigilTiers(part);
        const hasTimeSigils = spendableTiers.length > 0;
        const selectedTier = spendableTiers.some(o => o.tier === this._selectedTimeSigilTier)
            ? this._selectedTimeSigilTier
            : (hasTimeSigils ? spendableTiers[0].tier : null);
        this._selectedTimeSigilTier = selectedTier;

        const timeCandidates = selectedTier ? this.getTimePurchaseCandidates(selectedTier) : [];
        const selectedBoostId = timeCandidates.some(c => c.boostId === this._selectedTimeBoostId)
            ? this._selectedTimeBoostId
            : (timeCandidates.length > 0 ? timeCandidates[0].boostId : null);
        this._selectedTimeBoostId = selectedBoostId;

        const selectedTierOption = spendableTiers.find((o) => o.tier === selectedTier) || null;
        const tierPickerLabel = selectedTierOption
            ? `Tier ${selectedTierOption.tier} (${selectedTierOption.owned}) +${this.formatDurationFromSeconds(selectedTierOption.extensionRealSeconds, 'short')}`
            : 'No sigils';
        const tierMenuItemsHtml = spendableTiers.length > 0
            ? spendableTiers.map((o) => {
                const selected = o.tier === selectedTier ? ' is-selected' : '';
                return `<button type="button" class="time-tier-menu-item${selected}" data-tier="${o.tier}" onclick="TMC.selectTimeTier(${o.tier})">
                    <span class="time-tier-menu-name">Tier ${o.tier}</span>
                    <span class="time-tier-menu-meta">${o.owned} owned • +${this.formatDurationFromSeconds(o.extensionRealSeconds, 'short')}</span>
                </button>`;
            }).join('')
            : '<div class="time-boost-menu-empty">No sigils</div>';

        const selectedCandidate = timeCandidates.find(c => c.boostId === selectedBoostId) || null;
        const pickerLabel = selectedCandidate
            ? `${selectedCandidate.boostName} (${this.formatBoostSecondsRemaining(selectedCandidate.remainingSeconds)} left)`
            : (timeCandidates.length > 0 ? 'Choose boost' : 'No active boosts eligible');
        const boostMenuItemsHtml = timeCandidates.length > 0
            ? timeCandidates.map((c) => {
                const selected = c.boostId === selectedBoostId ? ' is-selected' : '';
                return `<button type="button" class="time-boost-menu-item${selected}" data-boost-id="${c.boostId}" onclick="TMC.selectTimeBoostCandidate(${c.boostId})">
                    <span class="time-boost-menu-name">${this.escapeHtml(c.boostName)}</span>
                    <span class="time-boost-menu-timer" data-boost-timer-for="${c.boostId}">${this.formatBoostSecondsRemaining(c.remainingSeconds)} left</span>
                </button>`;
            }).join('')
            : '<div class="time-boost-menu-empty">No active boosts eligible</div>';

        const flowControlsHtml = this._timePurchaseFlowOpen
            ? `
                <div class="boost-time-flow-controls">
                    ${this._timePurchaseStep < 2
                        ? `<div class="time-tier-picker ${this._timeTierPickerOpen ? 'open' : ''}">
                               <button type="button" id="time-tier-picker-toggle" class="input-field boost-time-flow-select time-tier-picker-toggle" onclick="TMC.toggleTimeTierPicker()" ${hasTimeSigils ? '' : 'disabled'}>${this.escapeHtml(tierPickerLabel)}</button>
                               <div id="time-tier-picker-menu" class="time-tier-picker-menu ${this._timeTierPickerOpen ? 'open' : ''}">${tierMenuItemsHtml}</div>
                           </div>
                           <button class="btn btn-primary btn-sm" onclick="TMC.advanceTimePurchaseStep()" ${hasTimeSigils ? '' : 'disabled title="No sigils available"'}>Next</button>`
                        : ''}
                    ${this._timePurchaseStep >= 2 && selectedTier ? `
                        <div class="time-boost-picker ${this._timeBoostPickerOpen ? 'open' : ''}">
                            <button type="button" id="time-boost-picker-toggle" class="input-field boost-time-flow-select time-boost-picker-toggle" onclick="TMC.toggleTimeBoostPicker()" ${timeCandidates.length > 0 ? '' : 'disabled'}>${this.escapeHtml(pickerLabel)}</button>
                            <div id="time-boost-picker-menu" class="time-boost-picker-menu ${this._timeBoostPickerOpen ? 'open' : ''}">${boostMenuItemsHtml}</div>
                        </div>
                    ` : ''}
                    ${this._timePurchaseStep >= 2 ? `<button id="time-apply-btn" class="btn btn-primary btn-sm" onclick="TMC.purchaseBoostTimeFlowGated()" ${timeCandidates.length > 0 ? '' : 'disabled title="No active boosts eligible for this +Time action"'}>Apply +Time</button>` : ''}
                    <button class="btn btn-outline btn-sm" onclick="TMC.cancelTimePurchaseFlow()">Cancel</button>
                </div>
            `
            : `<button class="btn btn-primary btn-sm" onclick="TMC.startTimePurchaseFlow()" ${hasTimeSigils ? '' : 'disabled title="No sigils available"'}>+Time</button>`;

        grid.innerHTML = `
            <div class="boost-catalog-header">
                <h4>Available Purchases</h4>
                ${flowControlsHtml}
            </div>
            ${purchaseCards || '<p class="empty-text">No boosts are currently purchasable with your sigils.</p>'}
        `;
    },

    startTimePurchaseFlow() {
        const p = this.state.player;
        const part = p ? p.participation : null;
        const spendable = this.getSpendableTimeSigilTiers(part);
        if (spendable.length === 0) {
            this.toast('No sigils available for time purchases', 'error');
            return;
        }

        this._timePurchaseFlowOpen = true;
        this._timePurchaseStep = 1;
        this._timeTierPickerOpen = false;
        this._timeBoostPickerOpen = false;
        if (!spendable.some(o => o.tier === this._selectedTimeSigilTier)) {
            this._selectedTimeSigilTier = spendable[0].tier;
        }
        this._selectedTimeBoostId = null;
        this.renderBoostCatalog();
    },

    advanceTimePurchaseStep() {
        if (!this._timePurchaseFlowOpen) return;
        if (!this._selectedTimeSigilTier || this._selectedTimeSigilTier < 1 || this._selectedTimeSigilTier > 5) {
            this.toast('Select a sigil tier first', 'error');
            return;
        }
        this._timePurchaseStep = 2;
        this._timeTierPickerOpen = false;
        this._timeBoostPickerOpen = false;
        this._selectedTimeBoostId = null;
        this.renderBoostCatalog();
    },

    cancelTimePurchaseFlow() {
        this._timePurchaseFlowOpen = false;
        this._timePurchaseStep = 1;
        this._timeTierPickerOpen = false;
        this._timeBoostPickerOpen = false;
        this._selectedTimeBoostId = null;
        this.renderBoostCatalog();
    },

    toggleTimeTierPicker() {
        if (!this._timePurchaseFlowOpen || this._timePurchaseStep >= 2) return;
        this._timeTierPickerOpen = !this._timeTierPickerOpen;
        this.renderBoostCatalog();
    },

    selectTimeTier(tier) {
        const parsedTier = parseInt(tier, 10) || null;
        if (!parsedTier || parsedTier < 1 || parsedTier > 5) return;
        this._selectedTimeSigilTier = parsedTier;
        this._timeTierPickerOpen = false;
        this._timeBoostPickerOpen = false;
        this._selectedTimeBoostId = null;
        this.renderBoostCatalog();
    },

    toggleTimeBoostPicker() {
        if (!this._timePurchaseFlowOpen || this._timePurchaseStep < 2) return;
        this._timeTierPickerOpen = false;
        this._timeBoostPickerOpen = !this._timeBoostPickerOpen;
        this.renderBoostCatalog();
    },

    selectTimeBoostCandidate(boostId) {
        this._selectedTimeBoostId = parseInt(boostId, 10) || null;
        this._timeBoostPickerOpen = false;
        this.renderBoostCatalog();
    },

    getBoostDisplayName(name) {
        const raw = String(name || '').trim();
        if (!raw) return '';
        const withoutLowercaseWords = raw
            .split(/\s+/)
            .filter(part => !/^[a-z][a-z0-9_\-]*$/.test(part));

        const normalized = withoutLowercaseWords.length > 0
            ? withoutLowercaseWords.join(' ')
            : raw;

        const parts = normalized.split(/\s+/);
        if (parts.length <= 1) return normalized;
        return parts.slice(1).join(' ');
    },

    getBoostDisplayIcon(icon, fallbackIcon) {
        const raw = String(icon || '').trim();
        if (!raw) return fallbackIcon || '';

        // Hide plain lowercase icon labels so titles don't show duplicated words like "trickle Trickle".
        if (/^[a-z][a-z0-9_\-]*$/.test(raw)) return fallbackIcon || '';

        return raw;
    },

    formatBoostDuration(durationTicks, style = 'short') {
        const ticks = Math.max(0, parseInt(durationTicks, 10) || 0);
        const tickRealSeconds = Math.max(1, parseInt((this.state && this.state.timing && this.state.timing.tick_real_seconds) || 60, 10) || 60);
        const totalSeconds = ticks * tickRealSeconds;
        const minutes = Math.max(1, Math.floor(totalSeconds / 60));

        if (minutes >= 60 && minutes % 60 === 0) {
            const hours = minutes / 60;
            if (style === 'long') return `${hours} ${hours === 1 ? 'hour' : 'hours'}`;
            return `${hours} ${hours === 1 ? 'hr' : 'hrs'}`;
        }

        if (style === 'long') return `${minutes} ${minutes === 1 ? 'minute' : 'minutes'}`;
        return `${minutes} min`;
    },

    formatDurationFromSeconds(totalSeconds, style = 'short') {
        const total = Math.max(0, parseInt(totalSeconds, 10) || 0);
        const hours = Math.floor(total / 3600);
        const minutes = Math.floor((total % 3600) / 60);

        if (hours > 0) {
            if (minutes > 0) {
                return style === 'long'
                    ? `${hours} ${hours === 1 ? 'hour' : 'hours'} ${minutes} ${minutes === 1 ? 'minute' : 'minutes'}`
                    : `${hours}h ${minutes}m`;
            }
            return style === 'long'
                ? `${hours} ${hours === 1 ? 'hour' : 'hours'}`
                : `${hours} ${hours === 1 ? 'hr' : 'hrs'}`;
        }

        const mins = Math.max(1, minutes);
        return style === 'long'
            ? `${mins} ${mins === 1 ? 'minute' : 'minutes'}`
            : `${mins} min`;
    },

    getTimeExtensionMapByTier() {
        const map = {};
        const catalog = Array.isArray(this._boostCatalog) ? this._boostCatalog : [];
        catalog.forEach((b) => {
            const tier = parseInt(b.tier_required, 10);
            if (!tier || map[tier]) return;
            map[tier] = {
                extensionTicks: parseInt(b.time_extension_ticks || 0, 10) || 0,
                extensionRealSeconds: parseInt(b.time_extension_real_seconds || 0, 10) || 0
            };
        });
        return map;
    },

    getAvailableTimeSigilOptions(participation, currentRemainingTicks = 0, timeCapTicks = 2880) {
        const options = [];
        const sigils = participation && Array.isArray(participation.sigils) ? participation.sigils : [];
        const extensionByTier = this.getTimeExtensionMapByTier();
        const tickRealSeconds = Math.max(1, parseInt((this.state && this.state.timing && this.state.timing.tick_real_seconds) || 60, 10) || 60);

        for (let tier = 1; tier <= 5; tier++) {
            const owned = parseInt(sigils[tier - 1] || 0, 10) || 0;
            if (owned <= 0) continue;

            const tierExtension = extensionByTier[tier] || {};
            const extensionTicks = Math.max(1, parseInt(tierExtension.extensionTicks || 0, 10) || 0);
            const extensionRealSeconds = Math.max(1, parseInt(tierExtension.extensionRealSeconds || 0, 10) || (extensionTicks * tickRealSeconds));
            const canApply = (currentRemainingTicks + extensionTicks) <= timeCapTicks;

            options.push({ tier, owned, extensionTicks, extensionRealSeconds, canApply });
        }

        return options;
    },

    getSpendableTimeSigilTiers(participation) {
        const sigils = participation && Array.isArray(participation.sigils) ? participation.sigils : [];
        const extensionByTier = this.getTimeExtensionMapByTier();
        const tiers = [];
        for (let tier = 1; tier <= 5; tier++) {
            const owned = parseInt(sigils[tier - 1] || 0, 10) || 0;
            const ext = extensionByTier[tier] || null;
            if (owned > 0 && ext) {
                tiers.push({
                    tier,
                    owned,
                    extensionTicks: Math.max(1, parseInt(ext.extensionTicks || 0, 10) || 0),
                    extensionRealSeconds: Math.max(1, parseInt(ext.extensionRealSeconds || 0, 10) || 0)
                });
            }
        }
        return tiers;
    },

    getTimePurchaseCandidates(selectedTier) {
        const p = this.state.player;
        const activeSelfBoosts = (p && p.active_boosts && Array.isArray(p.active_boosts.self)) ? p.active_boosts.self : [];
        const nowTick = parseInt((p && p.active_boosts && p.active_boosts.server_now) || 0, 10) || 0;
        const extensionByTier = this.getTimeExtensionMapByTier();
        const tierExt = extensionByTier[selectedTier] || null;
        if (!tierExt) return [];
        const tickRealSeconds = Math.max(1, parseInt((this.state && this.state.timing && this.state.timing.tick_real_seconds) || 60, 10) || 60);

        const extendTicks = Math.max(1, parseInt(tierExt.extensionTicks || 0, 10) || 0);
        const extendRealSeconds = Math.max(1, parseInt(tierExt.extensionRealSeconds || 0, 10) || (extendTicks * tickRealSeconds));

        return activeSelfBoosts
            .map((activeBoost) => {
                const boostId = parseInt(activeBoost.boost_id, 10);
                const catalogBoost = this._boostCatalog ? this._boostCatalog.find(b => parseInt(b.boost_id, 10) === boostId) : null;
                if (!catalogBoost) return null;

                const timeCapTicks = parseInt(catalogBoost.time_cap_ticks || 2880, 10) || 2880;
                const remainingTicks = Math.max(0, (parseInt(activeBoost.expires_tick, 10) || 0) - nowTick);
                const canApply = (remainingTicks + extendTicks) <= timeCapTicks;
                if (!canApply) return null;

                return {
                    boostId,
                    boostName: 'Boost',
                    remainingSeconds: Math.max(0, parseInt(activeBoost.remaining_real_seconds, 10) || this.getLiveBoostRemainingSeconds(activeBoost)),
                    extensionRealSeconds: extendRealSeconds
                };
            })
            .filter(Boolean);
    },

    async purchaseBoostTimeFlowGated() {
        if (!this._timePurchaseFlowOpen) {
            this.startTimePurchaseFlow();
            return;
        }
        if (this._timePurchaseStep < 2) {
            this.toast('Complete Step 1 first', 'error');
            return;
        }

        const selectedTier = this._selectedTimeSigilTier;
        const selectedBoostId = this._selectedTimeBoostId;

        if (!selectedTier || selectedTier < 1 || selectedTier > 5) {
            this.toast('Select a sigil tier for +Time', 'error');
            return;
        }
        if (!selectedBoostId) {
            this.toast('Select a boost to extend', 'error');
            return;
        }

        await this.purchaseBoostTimeGated(selectedBoostId, selectedTier);
        this._timePurchaseFlowOpen = false;
        this._timePurchaseStep = 1;
        this._timeTierPickerOpen = false;
        this._timeBoostPickerOpen = false;
    },

    chooseTimeSigilTier(boostId) {
        const selector = document.getElementById(`boost-time-tier-${boostId}`);
        if (selector && selector.value) {
            const selected = parseInt(selector.value, 10);
            if (selected >= 1 && selected <= 5) {
                return selected;
            }
        }

        const p = this.state.player;
        const part = p ? p.participation : null;
        const activeSelfBoosts = (p && p.active_boosts && Array.isArray(p.active_boosts.self)) ? p.active_boosts.self : [];
        const nowTick = parseInt((p && p.active_boosts && p.active_boosts.server_now) || 0, 10) || 0;
        const boost = this._boostCatalog ? this._boostCatalog.find(b => parseInt(b.boost_id, 10) === parseInt(boostId, 10)) : null;
        const activeBoost = activeSelfBoosts.find((ab) => parseInt(ab.boost_id, 10) === parseInt(boostId, 10)) || null;
        const timeCapTicks = boost ? (parseInt(boost.time_cap_ticks || 2880, 10) || 2880) : 2880;

        if (!activeBoost) {
            this.toast('Activate boost power first before purchasing additional time', 'error');
            return null;
        }

        const currentRemainingTicks = Math.max(0, (parseInt(activeBoost.expires_tick, 10) || 0) - nowTick);
        const options = this.getAvailableTimeSigilOptions(part, currentRemainingTicks, timeCapTicks)
            .filter(o => o.canApply);

        if (options.length === 0) {
            this.toast('No eligible sigils available for this time purchase', 'error');
            return null;
        }

        if (options.length === 1) {
            return options[0].tier;
        }

        return options[0].tier;
    },

    getBoostDescription(boost) {
        if (!boost) return '';
        const name = String(boost.name || '').trim();
        const raw = String(boost.description || '').trim();
        if (!raw) return '';

        const normalizedName = name.toLowerCase();
        if (raw.toLowerCase() === normalizedName) return '';

        const escapedName = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const prefixed = new RegExp(`^${escapedName}\\s*[:\\-]\\s*`, 'i');
        let cleaned = raw.replace(prefixed, '').trim();

        const durationLabel = this.formatBoostDuration(boost.duration_ticks, 'long');
        cleaned = cleaned.replace(/for\s+\d+\s+(?:hour|hours|minute|minutes)(?=\.|,|$)/i, `for ${durationLabel}`);

        if (!cleaned || cleaned.toLowerCase() === normalizedName) return '';
        return cleaned;
    },

    renderActiveBoosts() {
        const container = document.getElementById('active-boosts-display');
        if (!container) return;
        container.innerHTML = '';
    },

    async purchaseBoostPower(boostId) {
        const boost = this._boostCatalog ? this._boostCatalog.find(b => b.boost_id == boostId) : null;
        const name = boost ? this.getBoostDisplayName(boost.name) : `Boost #${boostId}`;
        if (!confirm(`Purchase ${name} power?\n\nThis will consume ${boost ? boost.sigil_cost : 1} Tier ${boost ? boost.tier_required : '?'} Sigil(s).`)) {
            return;
        }

        const result = await this.api('purchase_boost', { boost_id: boostId, purchase_kind: 'power' });
        if (result.error) {
            this.toast(result.error, 'error');
            return;
        }
        this.toast(result.message, 'success', {
            category: 'boost_activate',
            payload: {
                boost_id: Number(boostId) || null,
                season_id: Number(this.state.currentSeason) || null
            }
        });
        await this.refreshGameState();
        this.loadBoostCatalog();
    },

    async purchaseBoostTime(boostId) {
        const boost = this._boostCatalog ? this._boostCatalog.find(b => b.boost_id == boostId) : null;
        const name = boost ? this.getBoostDisplayName(boost.name) : `Boost #${boostId}`;
        if (!confirm(`Purchase ${name} time extension?\n\nThis will consume ${boost ? boost.sigil_cost : 1} Tier ${boost ? boost.tier_required : '?'} Sigil(s).`)) {
            return;
        }

        const result = await this.api('purchase_boost', { boost_id: boostId, purchase_kind: 'time' });
        if (result.error) {
            this.toast(result.error, 'error');
            return;
        }
        this.toast(result.message, 'success', {
            category: 'boost_activate',
            payload: {
                boost_id: Number(boostId) || null,
                season_id: Number(this.state.currentSeason) || null,
                purchase_kind: 'time'
            }
        });
        await this.refreshGameState();
        this.loadBoostCatalog();
    },

    confirmLockIn() {
        const participation = this.state.player && this.state.player.participation;
        const sigils = participation ? (participation.sigils || []) : [];
        const t6Count = Number(sigils[5] || 0);

        // T6 warning must appear FIRST and ONLY if the player owns any T6 sigils.
        if (t6Count > 0) {
            if (!confirm(
                `⚠️ T6 Sigil Destruction Warning\n\n` +
                `You own ${t6Count} Tier 6 Sigil(s). ` +
                `T6 Sigils will be DESTROYED with NO refund upon Lock-In.\n\n` +
                `Do you wish to continue?`
            )) {
                return;
            }
        }

        const stars = participation ? participation.seasonal_stars : 0;
        if (!confirm(
            `Are you sure you want to Lock-In?\n\n` +
            `This will:\n` +
            `- Refund T1–T5 Sigils back to Seasonal Stars\n` +
            `- Convert all Seasonal Stars to Global Stars at 65% (rounded down)\n` +
            `- Destroy ALL your Coins, T6 Sigils, and Boosts\n` +
            `- Remove you from this season\n\n` +
            `Current Seasonal Stars: ${this.formatNumber(stars)} ` +
            `(final Global Stars payout will be floor(total × 0.65))\n\n` +
            `This action is IRREVERSIBLE.`
        )) {
            return;
        }
        this.lockIn();
    },

    async lockIn() {
        const result = await this.api('lock_in');
        if (result.error) {
            this.toast(result.error, 'error');
            return;
        }
        this.toast(result.message, 'success', { category: 'season_lock_in' });
        await this.refreshGameState();
        this.navigate('home');
    },

    // ==================== GLOBAL LEADERBOARD ====================
    getActiveJoinedSeason() {
        if (!this.state.player || !this.state.player.joined_season_id) return null;
        const joinedSeasonId = this.state.player.joined_season_id;
        const season = this.state.seasons.find(s => s.season_id == joinedSeasonId);
        if (!season) return null;

        const status = season.computed_status || season.status;
        if (status === 'Active' || status === 'Blackout') return season;
        return null;
    },

    switchLeaderboardTab(tab) {
        this.state.leaderboardTab = tab === 'seasonal' ? 'seasonal' : 'global';
        this.loadGlobalLeaderboard();
    },

    updateLeaderboardTabUI(showSeasonal) {
        const globalTab = document.getElementById('leaderboard-tab-global');
        const seasonalTab = document.getElementById('leaderboard-tab-seasonal');
        if (!globalTab || !seasonalTab) return;

        seasonalTab.style.display = showSeasonal ? '' : 'none';
        if (!showSeasonal && this.state.leaderboardTab === 'seasonal') {
            this.state.leaderboardTab = 'global';
        }

        globalTab.classList.toggle('active', this.state.leaderboardTab !== 'seasonal');
        seasonalTab.classList.toggle('active', this.state.leaderboardTab === 'seasonal');
    },

    getPlayerStatus(entry) {
        if (!entry.online_current) return 'Offline';
        return entry.activity_state || 'Offline';
    },

    renderPlayerStatusBadge(entry) {
        const status = this.getPlayerStatus(entry);
        const key = status.toLowerCase().replace(/[^a-z]+/g, '-');
        let badge = `<span class="status-dot status-dot-${key}" title="${status}"></span>`;
        if (Number(entry.is_frozen || 0) > 0) {
            badge += ` <span class="status-dot status-dot-frozen" title="Frozen"></span>`;
        }
        if (entry.lock_in_effect_tick != null) {
            badge += ` <span class="status-dot status-dot-locked-in" title="Locked-In"></span>`;
        }
        return badge;
    },

    setLeaderboardMeta(title, subtitle) {
        const titleEl = document.getElementById('global-lb-title');
        const subtitleEl = document.getElementById('global-lb-subtitle');
        if (titleEl) titleEl.textContent = title;
        if (subtitleEl) subtitleEl.textContent = subtitle;
    },

    setLeaderboardHeader(columns) {
        const theadRow = document.querySelector('#global-lb-content thead tr');
        if (!theadRow) return;
        theadRow.innerHTML = columns.map((c) => `<th>${c}</th>`).join('');
    },

    renderSeasonLeaderboardRows(entries, includeActions = false, options = {}) {
        const firstCol = options.firstCol === 'rate' ? 'rate' : 'rank';
        const showRateColumn = options.showRateColumn !== false;
        return entries.map((entry, i) => {
            // Support rank field under any of the common schema aliases
            // (final_rank for ended seasons, position/rank for live data).
            const rawRank = entry.final_rank ?? entry.position ?? entry.rank ?? null;
            const parsedFinalRank = Number(rawRank);
            const hasFinalRank = rawRank != null && !Number.isNaN(parsedFinalRank) && parsedFinalRank > 0;
            const rank = hasFinalRank ? parsedFinalRank : (i + 1);
            const isMe = this.state.player && entry.player_id == this.state.player.player_id;
            let statusBadge = this.renderPlayerStatusBadge(entry);
            if (entry.badge_awarded) {
                const badgeEmoji = { first: '&#129351;', second: '&#129352;', third: '&#129353;' };
                statusBadge += ` <span class="badge badge-${entry.badge_awarded}">${badgeEmoji[entry.badge_awarded] || ''}</span>`;
            } else if (entry.end_membership) {
                statusBadge += ' <span class="badge badge-ended">End-Finisher</span>';
            }
            const ratePerTick = Number(entry.rate_per_tick || 0);
            const firstColValue = firstCol === 'rate'
                ? `${this.formatPercentCompact(ratePerTick)}`
                : `#${rank}`;
            const coinsCell = this.formatNumber(entry.coins || 0);
            const rateCell = `${this.formatPercentCompact(ratePerTick)}`;

            return `
                <tr class="${isMe ? 'my-row' : ''} ${rank <= 3 ? 'top-three' : ''}">
                    <td class="${firstCol === 'rate' ? 'rate-cell' : 'rank-cell'}">${firstColValue}</td>
                    <td class="player-cell">
                        <span class="player-link" onclick="TMC.navigate('profile', ${entry.player_id})">${this.escapeHtml(entry.handle)}</span>
                    </td>
                    <td class="stars-cell">${this.formatNumber(entry.seasonal_stars)}</td>
                    <td class="boost-cell">${entry.boost_pct != null ? entry.boost_pct + '%' : '0%'}</td>
                    <td class="stars-cell">${coinsCell}</td>
                    ${showRateColumn ? `<td class="rate-cell">${rateCell}</td>` : ''}
                    <td class="status-cell">${statusBadge}</td>
                </tr>
            `;
        }).join('');
    },

    async loadGlobalLeaderboard() {
        const activeSeason = this.getActiveJoinedSeason();
        const body = document.getElementById('global-lb-body');
        const empty = document.getElementById('global-lb-empty');
        const globalPagerWrap = document.getElementById('global-lb-pagination-wrap');
        const showSeasonalTab = !!activeSeason;
        this.updateLeaderboardTabUI(showSeasonalTab);

        if (this.state.leaderboardTab === 'seasonal' && activeSeason) {
            if (globalPagerWrap) globalPagerWrap.style.display = 'none';
            this.setLeaderboardMeta(
                `Season #${activeSeason.season_id} Leaderboard`,
                'Ranked by Seasonal Stars in your active season.'
            );
            this.setLeaderboardHeader(['Rank', 'Player', 'Stars', 'Boost', 'Coins', 'Rate', 'Status']);

            const lb = await this.api('leaderboard', { season_id: activeSeason.season_id, limit: this._globalSeasonalLeaderboardExpanded ? 0 : 20 });
            if (!Array.isArray(lb) || lb.length === 0 || lb.error) {
                body.innerHTML = '';
                empty.style.display = '';
                empty.innerHTML = '<p>No ranked players yet in this season.</p>';
                const seasonalToggleWrap = document.getElementById('global-seasonal-lb-toggle-wrap');
                if (seasonalToggleWrap) seasonalToggleWrap.style.display = 'none';
                const seasonalPagerWrap = document.getElementById('global-seasonal-lb-pagination-wrap');
                if (seasonalPagerWrap) seasonalPagerWrap.style.display = 'none';
                return;
            }

            empty.style.display = 'none';
            let visibleRows = lb.slice(0, 20);
            if (this._globalSeasonalLeaderboardExpanded) {
                if (lb.length > 100) {
                    const totalPages = Math.max(1, Math.ceil(lb.length / 100));
                    const currentPage = Math.min(Math.max(1, this._globalSeasonalLeaderboardPage), totalPages);
                    this._globalSeasonalLeaderboardPage = currentPage;
                    const start = (currentPage - 1) * 100;
                    visibleRows = lb.slice(start, start + 100);
                } else {
                    this._globalSeasonalLeaderboardPage = 1;
                    visibleRows = lb;
                }
            } else {
                this._globalSeasonalLeaderboardPage = 1;
            }
            body.innerHTML = this.renderSeasonLeaderboardRows(visibleRows, false, { firstCol: 'rank', showRateColumn: true });

            const seasonalToggleWrap = document.getElementById('global-seasonal-lb-toggle-wrap');
            const seasonalToggleBtn = document.getElementById('global-seasonal-lb-toggle-btn');
            if (seasonalToggleWrap && seasonalToggleBtn) {
                if (lb.length > 20) {
                    seasonalToggleWrap.style.display = '';
                    seasonalToggleBtn.textContent = this._globalSeasonalLeaderboardExpanded
                        ? '▴ Show Top 20'
                        : '▾ Show All';
                } else {
                    seasonalToggleWrap.style.display = 'none';
                }
            }

            const seasonalPagerWrap = document.getElementById('global-seasonal-lb-pagination-wrap');
            const seasonalPagerLabel = document.getElementById('global-seasonal-lb-pagination-label');
            const seasonalPagerPrev = document.getElementById('global-seasonal-lb-page-prev');
            const seasonalPagerNext = document.getElementById('global-seasonal-lb-page-next');
            if (seasonalPagerWrap && seasonalPagerLabel && seasonalPagerPrev && seasonalPagerNext) {
                if (this._globalSeasonalLeaderboardExpanded && lb.length > 100) {
                    const totalPages = Math.max(1, Math.ceil(lb.length / 100));
                    seasonalPagerWrap.style.display = '';
                    seasonalPagerLabel.textContent = `Page ${this._globalSeasonalLeaderboardPage} / ${totalPages}`;
                    seasonalPagerPrev.disabled = this._globalSeasonalLeaderboardPage <= 1;
                    seasonalPagerNext.disabled = this._globalSeasonalLeaderboardPage >= totalPages;
                } else {
                    seasonalPagerWrap.style.display = 'none';
                }
            }
            return;
        }

        const seasonalToggleWrap = document.getElementById('global-seasonal-lb-toggle-wrap');
        if (seasonalToggleWrap) seasonalToggleWrap.style.display = 'none';
        const seasonalPagerWrap = document.getElementById('global-seasonal-lb-pagination-wrap');
        if (seasonalPagerWrap) seasonalPagerWrap.style.display = 'none';

        this.setLeaderboardMeta(
            'Global Leaderboard',
            'Ranked by Global Stars earned this yearly cycle.'
        );
        this.setLeaderboardHeader(['Rank', 'Player', 'Global Stars', 'Status']);
        const lb = await this.api('global_leaderboard');

        if (!Array.isArray(lb) || lb.length === 0 || lb.error) {
            body.innerHTML = '';
            empty.style.display = '';
            empty.innerHTML = '<p>No players on the leaderboard yet. Earn Global Stars through season outcomes and Lock-In!</p>';
            if (globalPagerWrap) globalPagerWrap.style.display = 'none';
            return;
        }
        empty.style.display = 'none';

        const totalPages = Math.max(1, Math.ceil(lb.length / 100));
        const currentPage = Math.min(Math.max(1, this._globalLeaderboardPage), totalPages);
        this._globalLeaderboardPage = currentPage;
        const start = (currentPage - 1) * 100;
        const visibleRows = lb.length > 100 ? lb.slice(start, start + 100) : lb;

        body.innerHTML = visibleRows.map((entry, i) => {
            const rank = start + i + 1;
            const isMe = this.state.player && entry.player_id == this.state.player.player_id;
            return `
                <tr class="${isMe ? 'my-row' : ''} ${rank <= 3 ? 'top-three' : ''}">
                    <td class="rank-cell">${rank <= 3 ? ['&#129351;', '&#129352;', '&#129353;'][rank-1] : rank}</td>
                    <td class="player-cell">
                        <span class="player-link" onclick="TMC.navigate('profile', ${entry.player_id})">${this.escapeHtml(entry.handle)}</span>
                    </td>
                    <td class="stars-cell">${this.formatNumber(entry.global_stars)}</td>
                    <td class="status-cell">${this.renderPlayerStatusBadge(entry)}</td>
                </tr>
            `;
        }).join('');

        const globalPagerLabel = document.getElementById('global-lb-pagination-label');
        const globalPagerPrev = document.getElementById('global-lb-page-prev');
        const globalPagerNext = document.getElementById('global-lb-page-next');
        if (globalPagerWrap && globalPagerLabel && globalPagerPrev && globalPagerNext) {
            if (lb.length > 100) {
                globalPagerWrap.style.display = '';
                globalPagerLabel.textContent = `Page ${currentPage} / ${totalPages}`;
                globalPagerPrev.disabled = currentPage <= 1;
                globalPagerNext.disabled = currentPage >= totalPages;
            } else {
                globalPagerWrap.style.display = 'none';
            }
        }
    },

    previousGlobalLeaderboardPage() {
        this._globalLeaderboardPage = Math.max(1, this._globalLeaderboardPage - 1);
        this.loadGlobalLeaderboard();
    },

    nextGlobalLeaderboardPage() {
        this._globalLeaderboardPage += 1;
        this.loadGlobalLeaderboard();
    },

    toggleGlobalSeasonalLeaderboard() {
        this._globalSeasonalLeaderboardExpanded = !this._globalSeasonalLeaderboardExpanded;
        if (!this._globalSeasonalLeaderboardExpanded) {
            this._globalSeasonalLeaderboardPage = 1;
        }
        this.loadGlobalLeaderboard();
    },

    previousGlobalSeasonalLeaderboardPage() {
        this._globalSeasonalLeaderboardPage = Math.max(1, this._globalSeasonalLeaderboardPage - 1);
        this.loadGlobalLeaderboard();
    },

    nextGlobalSeasonalLeaderboardPage() {
        this._globalSeasonalLeaderboardPage += 1;
        this.loadGlobalLeaderboard();
    },

    // ==================== SHOP ====================
    async loadShop() {
        const catalog = await this.api('cosmetic_catalog');
        if (catalog.error) return;
        this.state.cosmetics = catalog;

        if (this.state.player) {
            const mine = await this.api('my_cosmetics');
            if (!mine.error) this.state.myCosmetics = mine;
        }

        this.renderShop();
    },

    filterShop(category) {
        this.state.shopFilter = category;
        document.querySelectorAll('.shop-tab').forEach(t => t.classList.remove('active'));
        event.target.classList.add('active');
        this.renderShop();
    },

    renderShop() {
        const grid = document.getElementById('shop-grid');
        let items = this.state.cosmetics;
        if (this.state.shopFilter !== 'all') {
            items = items.filter(c => c.category === this.state.shopFilter);
        }

        const ownedIds = new Set(this.state.myCosmetics.map(c => c.cosmetic_id));

        grid.innerHTML = items.map(c => {
            const owned = ownedIds.has(c.cosmetic_id);
            const canAfford = this.state.player && this.state.player.global_stars >= c.price_global_stars;
            const categoryLabel = c.category.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase());

            return `
                <div class="shop-item ${owned ? 'owned' : ''} ${c.css_class || ''}">
                    <div class="shop-item-header">
                        <span class="shop-item-name">${this.escapeHtml(c.name)}</span>
                        <span class="shop-item-category">${categoryLabel}</span>
                    </div>
                    <p class="shop-item-desc">${this.escapeHtml(c.description || '')}</p>
                    <div class="shop-item-footer">
                        <span class="shop-item-price">&#11088; ${c.price_global_stars}</span>
                        ${owned ? 
                            '<span class="badge badge-owned">Owned</span>' :
                            (this.state.player ? 
                                `<button class="btn btn-sm btn-primary" onclick="TMC.buyCosmetic(${c.cosmetic_id})" ${!canAfford ? 'disabled title="Not enough Global Stars"' : ''}>Buy</button>` :
                                '<span class="shop-item-login">Login to purchase</span>'
                            )
                        }
                    </div>
                </div>
            `;
        }).join('');
    },

    async buyCosmetic(cosmeticId) {
        const cosmetic = this.state.cosmetics.find(c => c.cosmetic_id == cosmeticId);
        if (!confirm(`Purchase "${cosmetic.name}" for ${cosmetic.price_global_stars} Global Stars?`)) return;

        const result = await this.api('purchase_cosmetic', { cosmetic_id: cosmeticId });
        if (result.error) {
            this.toast(result.error, 'error');
            return;
        }
        this.toast('Cosmetic purchased!', 'success', {
            category: 'purchase_cosmetic',
            payload: { cosmetic_id: Number(cosmeticId) || null }
        });
        await this.refreshGameState();
        this.loadShop();
    },

    // ==================== SIGIL THEFT ====================
    async renderTheftScreen(seasonContext) {
        const content = document.getElementById('theft-content');
        if (!content) return;

        if (!this.state.player || !this.state.player.joined_season_id || !this.state.player.participation) {
            content.innerHTML = '<div class="error-state"><p>You must be in an active season to attempt sigil theft.</p></div>';
            return;
        }

        const seasonId = typeof seasonContext === 'object' && seasonContext !== null
            ? (seasonContext.seasonId || this.state.player.joined_season_id)
            : (seasonContext || this.state.player.joined_season_id);
        const targetPlayerId = typeof seasonContext === 'object' && seasonContext !== null
            ? (seasonContext.targetPlayerId || null)
            : (this.state.pendingTheftTargetId || null);

        if (!targetPlayerId) {
            content.innerHTML = '<div class="error-state"><p>Select a target profile to open the sigil theft screen.</p></div>';
            return;
        }

        const profile = await this.api('profile', { player_id: targetPlayerId });
        if (!profile || profile.error || !profile.active_participation) {
            content.innerHTML = '<div class="error-state"><p>This player is no longer available for sigil theft.</p></div>';
            return;
        }

        const viewerPart = this.state.player.participation;
        const targetPart = profile.active_participation;
        const targetTheft = targetPart.theft || {};
        const viewerTheft = viewerPart.theft || {};
        const spendTiers = [4, 5];
        const lootTiers = [1, 2, 3, 4, 5, 6];
        const targetActivityState = targetPart.activity_state || profile.activity_state || 'Active';
        const canSubmit = !viewerTheft.is_on_cooldown && !targetTheft.is_protected;
        const buttonLabel = viewerTheft.is_on_cooldown
            ? 'Theft Cooldown Active'
            : (targetTheft.is_protected ? 'Target Protected' : 'Attempt Theft');

        content.innerHTML = `
            <h2>Sigil Theft</h2>
            <button class="btn btn-outline btn-sm" onclick="TMC.navigate('profile', ${targetPlayerId})">Back to Profile</button>

            <div class="theft-form-container">
                <h3>Target: ${this.escapeHtml(profile.handle)}</h3>
                <p class="panel-info">Spend Tier 4 and Tier 5 sigils for a chance-based theft. Spent sigils are always lost, even on failure. Idle targets are valid.</p>
                <p class="panel-info">Target status: <strong>${this.escapeHtml(String(targetActivityState))}</strong></p>
                ${viewerTheft.is_on_cooldown ? `<p class="panel-warning">Your theft cooldown: ${this.formatDurationFromSeconds(Number(viewerTheft.cooldown_remaining_real_seconds || 0), 'short')}</p>` : ''}
                ${targetTheft.is_protected ? `<p class="panel-warning">Target protection: ${this.formatDurationFromSeconds(Number(targetTheft.protection_remaining_real_seconds || 0), 'short')}</p>` : ''}
                <div class="theft-form">
                    <div class="theft-side">
                        <h4>You Spend</h4>
                        ${spendTiers.map((tier) => {
                            const count = Number((viewerPart.sigils || [])[tier - 1] || 0);
                            return `
                                <div class="form-group">
                                    <label>Tier ${tier} Sigils (you have ${count})</label>
                                    <input type="number" id="theft-spend-sigil-${tier - 1}" min="0" max="${count}" value="0" class="input-field input-sm">
                                </div>
                            `;
                        }).join('')}
                    </div>
                    <div class="theft-arrow">&#8594;</div>
                    <div class="theft-side">
                        <h4>You Attempt to Steal</h4>
                        ${lootTiers.map((tier) => {
                            const count = Number((targetPart.sigils || [])[tier - 1] || 0);
                            return `
                                <div class="form-group">
                                    <label>Tier ${tier} Sigils (target has ${count})</label>
                                    <input type="number" id="theft-request-sigil-${tier - 1}" min="0" max="${count}" value="0" class="input-field input-sm" ${count <= 0 ? 'disabled' : ''}>
                                </div>
                            `;
                        }).join('')}
                    </div>
                </div>
                <button class="btn btn-primary btn-lg" onclick="TMC.submitSigilTheftGated(${Number(targetPlayerId) || 0})" ${canSubmit ? '' : 'disabled'}>${buttonLabel}</button>
            </div>
        `;

        this.state.pendingTheftTargetId = null;
    },

    // ==================== CHAT ====================
    initChat() {
        // Show season tab if in a season
        const seasonTab = document.getElementById('chat-season-tab');
        if (this.state.player && this.state.player.joined_season_id) {
            seasonTab.style.display = '';
        } else {
            seasonTab.style.display = 'none';
        }

        // Show input if logged in
        const inputArea = document.getElementById('chat-input-area');
        inputArea.style.display = this.state.player ? '' : 'none';

        this.loadChat();

        // Start chat polling
        if (this.state.chatPollInterval) clearInterval(this.state.chatPollInterval);
        this.state.chatPollInterval = setInterval(() => {
            if (this.state.currentScreen === 'chat') this.loadChat();
        }, 5000);
    },

    switchChat(channel) {
        this.state.currentChat = channel;
        document.querySelectorAll('.chat-tab').forEach(t => t.classList.remove('active'));
        event.target.classList.add('active');
        this.loadChat();
    },

    async loadChat() {
        if (this._isBackoffActive('chat')) return;

        const params = { channel: this.state.currentChat };
        if (this.state.currentChat === 'SEASON' && this.state.player) {
            params.season_id = this.state.player.joined_season_id;
        }
        const messages = await this.api('chat_messages', params);

        if (messages && (messages.status === 429 || messages.status >= 500 || messages.status === 0)) {
            this._applyBackoff('chat', 5000);
            return;
        }

        this._resetBackoff('chat');

        const container = document.getElementById('chat-messages');

        if (!messages || messages.error || messages.length === 0) {
            container.innerHTML = '<div class="chat-empty">No messages yet. Be the first to say something!</div>';
            return;
        }

        // Reverse to show oldest first
        const sorted = [...messages].reverse();
        container.innerHTML = sorted.map(m => {
            const isMe = this.state.player && m.sender_id == this.state.player.player_id;
            const isAdmin = m.is_admin_post;
            return `
                <div class="chat-msg ${isMe ? 'chat-msg-mine' : ''} ${isAdmin ? 'chat-msg-admin' : ''}">
                    <span class="chat-handle ${isAdmin ? 'admin-handle' : ''}" onclick="TMC.navigate('profile', ${m.sender_id})">
                        ${isAdmin ? '[ADMIN] ' : ''}${this.escapeHtml(m.handle_snapshot)}
                    </span>
                    <span class="chat-text">${this.escapeHtml(m.content)}</span>
                    <span class="chat-time">${this.formatChatTime(m.created_at)}</span>
                </div>
            `;
        }).join('');

        container.scrollTop = container.scrollHeight;
    },

    async sendChat() {
        const input = document.getElementById('chat-input');
        const content = input.value.trim();
        if (!content) return;

        const params = { channel: this.state.currentChat, content };
        if (this.state.currentChat === 'SEASON') {
            params.season_id = this.state.player.joined_season_id;
        }

        const result = await this.api('chat_send', params);
        if (result.error) {
            this.toast(result.error, 'error');
            return;
        }
        input.value = '';
        this.loadChat();
    },

    // ==================== PROFILE ====================
    async loadProfile(playerId) {
        const profile = await this.api('profile', { player_id: playerId });
        const content = document.getElementById('profile-content');

        if (profile.error) {
            this.toast(profile.error, 'error', { category: 'error_action' });
            content.innerHTML = `<div class="error-state"><p>Profile is temporarily unavailable.</p></div>`;
            return;
        }

        if (profile.deleted) {
            content.innerHTML = `<div class="profile-card"><h2>[Removed]</h2><p>This account has been deleted.</p></div>`;
            return;
        }

        const badges = (profile.badges || []).map(b => {
            const icons = {
                seasonal_first: '&#129351;', seasonal_second: '&#129352;', seasonal_third: '&#129353;',
                yearly_top10: '&#127942;'
            };
            return `<span class="profile-badge" title="${b.badge_type}">${icons[b.badge_type] || '&#127775;'}</span>`;
        }).join('');

        const history = (profile.season_history || []).map(h => `
            <tr>
                <td>Season #${h.season_id}</td>
                <td>${this.formatNumber(h.final_seasonal_stars || h.seasonal_stars || 0)}</td>
                <td>${h.final_rank || '-'}</td>
                <td>${this.formatNumber(h.global_stars_earned || 0)}</td>
                <td>${h.lock_in_effect_tick ? 'Lock-In' : (h.end_membership ? 'End-Finisher' : '-')}</td>
            </tr>
        `).join('');

        const activeParticipation = profile.active_participation;
        const activeSigils = Array.isArray(activeParticipation?.sigils) ? activeParticipation.sigils : [];
        const visibleProfileSigils = activeSigils
            .map((count, idx) => ({ tier: idx + 1, count: Number(count || 0) }))
            .filter((row) => row.tier <= 5 || row.count > 0);
        const profileActiveBoost = activeParticipation?.active_boost || null;
        const profileFreeze = activeParticipation?.freeze || null;
        const profileIndicatorHtml = this._renderUbiTimerIndicator({
            hasBoost: !!(profileActiveBoost && profileActiveBoost.is_active),
            boostPercent: Number(profileActiveBoost?.total_modifier_percent || 0),
            boostExpiresAtReal: this._getCountdownExpiryUnix(
                profileActiveBoost ? profileActiveBoost.expires_at_real : 0,
                profileActiveBoost ? profileActiveBoost.remaining_real_seconds : 0
            ),
            boostId: profileActiveBoost ? profileActiveBoost.boost_id : null,
            freeze: profileFreeze
        });
        const isOwnProfile = !!(this.state.player && this.state.player.player_id == profile.player_id);
        const viewerParticipation = this.state.player ? this.state.player.participation : null;
        const viewerTheftState = viewerParticipation?.theft || null;
        const targetTheftState = activeParticipation?.theft || null;
        const viewerT4Count = Array.isArray(viewerParticipation?.sigils)
            ? Number(viewerParticipation.sigils[3] || 0)
            : 0;
        const viewerT5Count = Array.isArray(viewerParticipation?.sigils)
            ? Number(viewerParticipation.sigils[4] || 0)
            : 0;
        const viewerT6Count = Array.isArray(viewerParticipation?.sigils)
            ? Number(viewerParticipation.sigils[5] || 0)
            : 0;
        const viewerCanFreeze = !!(viewerParticipation && (viewerParticipation.can_freeze || viewerT6Count > 0));
        const viewerCanSteal = !!(
            viewerParticipation && (
                viewerParticipation.can_steal
                || ((!viewerTheftState?.is_on_cooldown) && (viewerT4Count > 0 || viewerT5Count > 0))
            )
        );
        const ownFreeze = isOwnProfile
            ? (viewerParticipation && viewerParticipation.freeze ? viewerParticipation.freeze : profileFreeze)
            : profileFreeze;
        const ownT5Count = isOwnProfile && Array.isArray(viewerParticipation?.sigils)
            ? Number(viewerParticipation.sigils[4] || 0)
            : Number((activeSigils[4] || 0));
        const ownT6Count = isOwnProfile && Array.isArray(viewerParticipation?.sigils)
            ? Number(viewerParticipation.sigils[5] || 0)
            : Number((activeSigils[5] || 0));
        const canOpenTheft = !!(
            this.state.player &&
            !isOwnProfile &&
            this.state.player.joined_season_id &&
            activeParticipation &&
            this.state.player.joined_season_id == activeParticipation.season_id
        );
        const canFreezeFromProfile = !!(
            this.state.player &&
            !isOwnProfile &&
            viewerCanFreeze &&
            activeParticipation &&
            this.state.player.joined_season_id &&
            this.state.player.joined_season_id == activeParticipation.season_id
        );
        const canMeltOwnFreeze = !!(
            isOwnProfile &&
            ownFreeze &&
            ownFreeze.is_frozen &&
            Number(ownFreeze.remaining_real_seconds || 0) > 0 &&
            (ownT5Count > 0 || ownT6Count > 0)
        );
        const meltTierOptions = [
            ownT5Count > 0 ? { tier: 5, count: ownT5Count, label: 'Tier 5 (-15m max)' } : null,
            ownT6Count > 0 ? { tier: 6, count: ownT6Count, label: 'Tier 6 (-30m max)' } : null,
        ].filter(Boolean);
        const theftButtonLabel = targetTheftState?.is_protected
            ? 'Theft Protected'
            : (viewerTheftState?.is_on_cooldown
                ? 'Theft Cooldown Active'
                : ((viewerT4Count > 0 || viewerT5Count > 0) ? 'Attempt Theft' : 'Need T4/T5 Sigils'));
        const actionButtons = [
            canOpenTheft ? `<button class="btn btn-primary" onclick="TMC.openTheftRequest(${profile.player_id}, ${activeParticipation.season_id})" ${(viewerCanSteal && !targetTheftState?.is_protected) ? '' : 'disabled'}>${theftButtonLabel}</button>` : '',
            canFreezeFromProfile ? `<button class="btn btn-danger" onclick="TMC.freezeByPlayerId(${profile.player_id}, ${profile.player_id})">Freeze Player</button>` : '',
            canMeltOwnFreeze ? `
                <div class="profile-inline-action">
                    <select id="profile-melt-tier" class="input-field input-sm">
                        ${meltTierOptions.map((option) => `<option value="${option.tier}">${option.label} (${option.count} owned)</option>`).join('')}
                    </select>
                    <button class="btn btn-warning" onclick="TMC.selfMeltFreeze()">Melt Freeze</button>
                </div>
            ` : ''
        ].filter(Boolean).join('');

        const inventoryHtml = activeParticipation ? `
            <div class="profile-inventory">
                <h3>Current Season Inventory</h3>
                <div class="profile-stats profile-stats-season">
                    <div class="profile-stat">
                        <span class="stat-label">Coins</span>
                        <span class="stat-value">${this.formatNumber(activeParticipation.coins)}</span>
                    </div>
                    <div class="profile-stat">
                        <span class="stat-label">Season</span>
                        <span class="stat-value">#${activeParticipation.season_id}</span>
                    </div>
                </div>
                <div class="sigil-display profile-sigil-display">
                    ${visibleProfileSigils.map((row) => `
                        <div class="sigil-item tier-${row.tier}">
                            <span class="sigil-tier">T${row.tier}</span>
                            <span class="sigil-count">${row.count}</span>
                        </div>
                    `).join('')}
                </div>
                ${profileIndicatorHtml}
            </div>
        ` : `
            <div class="profile-inventory">
                <h3>Current Season Inventory</h3>
                <p class="panel-info">This player is not currently in an active season.</p>
            </div>
        `;

        content.innerHTML = `
            <div class="profile-card">
                <div class="profile-header">
                    <h2>${this.escapeHtml(profile.handle)}</h2>
                    ${profile.role !== 'Player' ? `<span class="badge badge-staff">${profile.role}</span>` : ''}
                </div>
                <div class="profile-stats">
                    <div class="profile-stat">
                        <span class="stat-label">Global Stars</span>
                        <span class="stat-value">&#11088; ${this.formatNumber(profile.global_stars)}</span>
                    </div>
                    <div class="profile-stat">
                        <span class="stat-label">Member Since</span>
                        <span class="stat-value">${new Date(profile.created_at).toLocaleDateString()}</span>
                    </div>
                </div>
                ${inventoryHtml}
                ${actionButtons ? `<div class="profile-actions">${actionButtons}</div>` : ''}
                ${badges ? `<div class="profile-badges"><h3>Badges</h3><div class="badges-row">${badges}</div></div>` : ''}
                ${history ? `
                    <div class="profile-history">
                        <h3>Season History</h3>
                        <table class="leaderboard-table">
                            <thead><tr><th>Season</th><th>Stars</th><th>Rank</th><th>Global Earned</th><th>Exit</th></tr></thead>
                            <tbody>${history}</tbody>
                        </table>
                    </div>
                ` : ''}
            </div>
        `;
    },

    openTheftRequest(targetPlayerId, seasonId) {
        if (!this.state.player || !this.state.player.joined_season_id) {
            this.toast('You must be in an active season to attempt sigil theft.', 'error');
            return;
        }
        this.state.pendingTheftTargetId = targetPlayerId;
        this.navigate('theft', { seasonId, targetPlayerId });
    },

    // ==================== NOTIFICATIONS ====================
    setupNotificationCenter() {
        if (this._notificationOutsideHandler) return;
        this._notificationOutsideHandler = (event) => {
            const center = document.getElementById('notification-center');
            if (!center) return;
            if (!center.contains(event.target)) {
                this.closeNotifications();
            }
        };
        document.addEventListener('click', this._notificationOutsideHandler);
        this.updateNotificationUI();
    },

    syncNotificationsFromPlayer(player) {
        if (!player) {
            this.state.notifications = [];
            this.state.notificationsUnread = 0;
            return;
        }

        const incoming = Array.isArray(player.notifications) ? player.notifications : [];
        this.state.notifications = incoming.map((n) => ({
            notification_id: Number(n.notification_id),
            category: n.category || 'system',
            title: n.title || 'Notification',
            body: n.body || '',
            payload: n.payload || null,
            is_read: !!n.is_read,
            created_at: n.created_at,
            read_at: n.read_at || null
        }));

        const fallbackUnread = this.state.notifications.filter((n) => !n.is_read).length;
        this.state.notificationsUnread = Number(player.notifications_unread_count ?? fallbackUnread) || 0;
    },

    updateNotificationUI() {
        this.renderNotificationList();
        this.updateNotificationIndicator();

        const panel = document.getElementById('notification-panel');
        const toggle = document.getElementById('notification-toggle');
        if (panel) panel.style.display = this.state.notificationsOpen ? 'flex' : 'none';
        if (toggle) toggle.setAttribute('aria-expanded', this.state.notificationsOpen ? 'true' : 'false');
    },

    updateNotificationIndicator() {
        const dot = document.getElementById('notification-dot');
        const toggle = document.getElementById('notification-toggle');
        const hasUnread = (Number(this.state.notificationsUnread) || 0) > 0;
        if (dot) dot.style.display = hasUnread ? 'block' : 'none';
        if (toggle) toggle.classList.toggle('has-unread', hasUnread);
    },

    async toggleNotifications() {
        this.state.notificationsOpen = !this.state.notificationsOpen;
        this.updateNotificationUI();
        if (this.state.notificationsOpen) {
            await this.loadNotifications();
            await this.markLoadedNotificationsRead();
        }
    },

    closeNotifications() {
        if (!this.state.notificationsOpen) return;
        this.state.notificationsOpen = false;
        this.updateNotificationUI();
    },

    async loadNotifications() {
        if (!this.state.player) {
            this.state.notifications = [];
            this.state.notificationsUnread = 0;
            this.updateNotificationUI();
            return;
        }

        const result = await this.api('notifications_list', { limit: 50 });
        if (result.error) return;

        this.state.notifications = Array.isArray(result.notifications) ? result.notifications.map((n) => ({
            ...n,
            notification_id: Number(n.notification_id),
            is_read: !!n.is_read
        })) : [];
        const fallbackUnread = this.state.notifications.filter((n) => !n.is_read).length;
        this.state.notificationsUnread = Number(result.unread_count ?? fallbackUnread) || 0;
        this.updateNotificationUI();
    },

    async markLoadedNotificationsRead() {
        if (!this.state.player) return;

        const unreadIds = this.state.notifications
            .filter((n) => !n.is_read)
            .map((n) => Number(n.notification_id))
            .filter((id) => id > 0);

        if (!unreadIds.length) return;

        this.state.notifications = this.state.notifications.map((n) => ({ ...n, is_read: true }));
        this.state.notificationsUnread = 0;
        this.updateNotificationUI();

        const result = await this.api('notifications_mark_all_read');
        if (result.error) {
            await this.loadNotifications();
            this.toast(result.error, 'error');
            return;
        }

        if (typeof result.unread_count !== 'undefined') {
            this.state.notificationsUnread = Number(result.unread_count) || 0;
            this.updateNotificationIndicator();
        }
    },

    renderNotificationList() {
        const list = document.getElementById('notification-list');
        if (!list) return;

        if (!this.state.player) {
            list.innerHTML = '<div class="notification-empty">Login to view notifications.</div>';
            return;
        }

        if (!this.state.notifications.length) {
            list.innerHTML = '<div class="notification-empty">No notifications yet.</div>';
            return;
        }

        list.innerHTML = this.state.notifications.map((n) => {
            const categoryView = this.getNotificationCategoryView(n.category);
            const itemClass = n.is_read
                ? `notification-item notification-${categoryView.tone}`
                : `notification-item unread notification-${categoryView.tone}`;
            const body = n.body ? `<p>${this.escapeHtml(n.body)}</p>` : '';
            return `
                <div class="${itemClass}" data-notification-id="${n.notification_id}" onclick="TMC.handleNotificationClick(event, ${n.notification_id})">
                    <div class="notification-item-head">
                        <span class="notification-category">${this.escapeHtml(categoryView.icon + ' ' + categoryView.label)}</span>
                        <span class="notification-time">${this.formatNotificationTime(n.created_at)}</span>
                    </div>
                    <h4>${this.escapeHtml(n.title || 'Notification')}</h4>
                    ${body}
                </div>
            `;
        }).join('');

        this.bindNotificationSwipeHandlers();
    },

    bindNotificationSwipeHandlers() {
        const items = document.querySelectorAll('.notification-item');
        items.forEach((item) => {
            if (item.dataset.swipeBound === '1') return;
            item.dataset.swipeBound = '1';

            let startX = 0;
            let currentX = 0;
            let dragging = false;
            let pointerId = null;

            const resetVisual = () => {
                item.style.transform = '';
                item.classList.remove('swipe-left', 'swipe-right', 'is-dragging');
                item.dataset.suppressClick = '0';
            };

            const begin = (x) => {
                startX = x;
                currentX = 0;
                dragging = true;
                item.classList.add('is-dragging');
                item.dataset.suppressClick = '0';
            };

            const move = (x) => {
                if (!dragging) return;
                currentX = x - startX;
                if (Math.abs(currentX) > 8) item.dataset.suppressClick = '1';
                item.style.transform = `translateX(${currentX}px)`;
                item.classList.toggle('swipe-left', currentX < -20);
                item.classList.toggle('swipe-right', currentX > 20);
            };

            const finish = () => {
                if (!dragging) return;
                dragging = false;
                item.classList.remove('is-dragging');

                const threshold = Math.max(80, item.offsetWidth * 0.24);
                const id = Number(item.dataset.notificationId || 0);
                if (Math.abs(currentX) >= threshold && id > 0) {
                    const direction = currentX < 0 ? -1 : 1;
                    item.style.transform = `translateX(${direction * (item.offsetWidth + 24)}px)`;
                    item.classList.add('dismissed');
                    item.dataset.suppressClick = '1';
                    setTimeout(() => this.removeNotificationById(id), 120);
                    return;
                }

                resetVisual();
            };

            item.addEventListener('pointerdown', (event) => {
                if (event.pointerType === 'mouse' && event.button !== 0) return;
                pointerId = event.pointerId;
                if (item.setPointerCapture) item.setPointerCapture(pointerId);
                begin(event.clientX);
            });

            item.addEventListener('pointermove', (event) => {
                if (pointerId !== null && event.pointerId !== pointerId) return;
                move(event.clientX);
            });

            item.addEventListener('pointerup', (event) => {
                if (pointerId !== null && event.pointerId !== pointerId) return;
                pointerId = null;
                finish();
            });

            item.addEventListener('pointercancel', () => {
                pointerId = null;
                dragging = false;
                resetVisual();
            });
        });
    },

    async handleNotificationClick(event, notificationId) {
        event.stopPropagation();
        const item = event.currentTarget;
        if (item && item.dataset.suppressClick === '1') return;

        const id = Number(notificationId);
        if (!id) return;

        const found = this.state.notifications.find((n) => Number(n.notification_id) === id);
        if (!found || found.is_read) return;

        found.is_read = true;
        this.state.notificationsUnread = Math.max(0, (Number(this.state.notificationsUnread) || 0) - 1);
        this.updateNotificationUI();

        const result = await this.api('notifications_mark_read', { notification_id: id });
        if (result.error) {
            await this.loadNotifications();
            this.toast(result.error, 'error');
            return;
        }

        if (typeof result.unread_count !== 'undefined') {
            this.state.notificationsUnread = Number(result.unread_count) || 0;
            this.updateNotificationIndicator();
        }
    },

    async removeNotificationById(notificationId) {
        const id = Number(notificationId);
        if (!id) return;

        const before = this.state.notifications.find((n) => Number(n.notification_id) === id);
        const removedUnread = before && !before.is_read;
        this.state.notifications = this.state.notifications.filter((n) => Number(n.notification_id) !== id);
        if (removedUnread) {
            this.state.notificationsUnread = Math.max(0, (Number(this.state.notificationsUnread) || 0) - 1);
        }
        this.updateNotificationUI();

        const result = await this.api('notifications_remove', { notification_id: id });
        if (result.error) {
            await this.loadNotifications();
            this.toast(result.error, 'error');
            return;
        }

        if (typeof result.unread_count !== 'undefined') {
            this.state.notificationsUnread = Number(result.unread_count) || 0;
            this.updateNotificationIndicator();
        }
    },

    formatNotificationTime(dateStr) {
        const d = new Date(dateStr);
        if (Number.isNaN(d.getTime())) return '';
        const now = new Date();
        const sameDay = d.toDateString() === now.toDateString();
        if (sameDay) {
            return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        }
        return d.toLocaleDateString([], { month: 'short', day: 'numeric' });
    },

    getNotificationCategoryView(category) {
        const key = String(category || '').trim().toLowerCase();
        const map = {
            auth_login: { label: 'Account', icon: 'Key', tone: 'auth' },
            auth_register: { label: 'Account', icon: 'Key', tone: 'auth' },
            season_join: { label: 'Season', icon: 'Flag', tone: 'progress' },
            season_lock_in: { label: 'Lock-In', icon: 'Star', tone: 'progress' },
            purchase_star: { label: 'Stars', icon: 'Star', tone: 'purchase' },
            purchase_sigil: { label: 'Sigils', icon: 'Diamond', tone: 'purchase' },
            purchase_cosmetic: { label: 'Cosmetic', icon: 'Palette', tone: 'purchase' },
            boost_activate: { label: 'Boost', icon: 'Bolt', tone: 'boost' },
            sigil_theft_success: { label: 'Theft', icon: 'Zap', tone: 'warning' },
            sigil_theft_failed: { label: 'Theft', icon: 'X', tone: 'warning' },
            sigil_theft_taken: { label: 'Theft', icon: 'Alert', tone: 'warning' },
            sigil_theft_defended: { label: 'Theft', icon: 'Shield', tone: 'status' },
            sigil_drop: { label: 'Sigil Drop', icon: 'Gift', tone: 'drop' },
            sigil_combine: { label: 'Sigil Forge', icon: 'Anvil', tone: 'progress' },
            freeze_apply: { label: 'Freeze', icon: 'Snow', tone: 'warning' },
            freeze_melt: { label: 'Melt', icon: 'Thermometer', tone: 'warning' },
            error_auth: { label: 'Auth Error', icon: 'Alert', tone: 'error' },
            error_action: { label: 'Action Failed', icon: 'X', tone: 'error' },
            error_validation: { label: 'Validation', icon: 'Info', tone: 'error' },
            warning_general: { label: 'Warning', icon: 'Alert', tone: 'warning' },
            info_general: { label: 'Info', icon: 'Info', tone: 'default' },
            idle: { label: 'Idle', icon: 'Pause', tone: 'status' },
            active: { label: 'Active', icon: 'Play', tone: 'status' }
        };

        if (map[key]) return map[key];

        const fallbackLabel = key
            ? key.replace(/_/g, ' ').replace(/\b\w/g, (ch) => ch.toUpperCase())
            : 'Notification';

        return { label: fallbackLabel, icon: 'Info', tone: 'default' };
    },

    // ==================== UTILITIES ====================
    formatNumber(n) {
        if (n === null || n === undefined) return '0';
        return Number(n).toLocaleString();
    },

    formatSigilVectorSummary(counts) {
        if (!Array.isArray(counts)) return 'None';
        const parts = counts
            .map((count, idx) => ({ tier: idx + 1, count: Number(count || 0) }))
            .filter((entry) => entry.count > 0)
            .map((entry) => `${this.formatNumber(entry.count)}xT${entry.tier}`);
        return parts.length ? parts.join(', ') : 'None';
    },

    formatPercentCompact(value) {
        const num = Number(value);
        if (!Number.isFinite(num)) return '0';
        if (num === 0) return '0';

        if (num >= 1) {
            return num.toFixed(2).replace(/\.00$/, '').replace(/(\.\d*[1-9])0+$/, '$1');
        }

        return num.toFixed(4).replace(/\.0+$/, '').replace(/(\.\d*[1-9])0+$/, '$1');
    },

    // Truncate (not round) a percentage to 0.01 precision (two decimal places).
    // Returns a string without a '%' suffix, e.g. 12.349 -> "12.34", 0.009 -> "0.00".
    truncatePercent(value) {
        const num = Number(value);
        if (!Number.isFinite(num)) return '0.00';
        return (Math.floor(num * 100) / 100).toFixed(2);
    },

    escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    },

    formatChatTime(dateStr) {
        const d = new Date(dateStr);
        return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    },

    async pushSuccessNotification(message, options = {}) {
        const text = String(message || '').trim();
        if (!text) return;

        const token = localStorage.getItem('tmc_token');
        if (!token) return;

        const categoryRaw = (options && options.category) ? String(options.category).trim() : '';
        const category = categoryRaw || 'gameplay_success';
        const payload = options && options.payload && typeof options.payload === 'object'
            ? options.payload
            : null;
        const eventKey = options && options.eventKey ? String(options.eventKey) : null;
        const body = options && options.body ? String(options.body) : null;

        const result = await this.api('notifications_create', {
            category,
            title: text,
            body,
            payload,
            event_key: eventKey
        });
        if (result.error) return;

        if (result.notification) {
            const normalized = {
                ...result.notification,
                notification_id: Number(result.notification.notification_id),
                is_read: !!result.notification.is_read
            };
            const existingIdx = this.state.notifications.findIndex(
                (n) => Number(n.notification_id) === normalized.notification_id
            );
            if (existingIdx >= 0) {
                this.state.notifications[existingIdx] = normalized;
            } else {
                this.state.notifications.unshift(normalized);
            }
        } else {
            await this.loadNotifications();
            return;
        }

        if (typeof result.unread_count !== 'undefined') {
            this.state.notificationsUnread = Number(result.unread_count) || 0;
        } else {
            this.state.notificationsUnread = this.state.notifications.filter((n) => !n.is_read).length;
        }
        this.updateNotificationUI();
    },

    toast(message, type = 'info', options = {}) {
        const categoryMap = {
            success: 'gameplay_success',
            error: 'error_action',
            warning: 'warning_general',
            info: 'info_general'
        };
        const category = (options && options.category) ? options.category : (categoryMap[type] || 'info_general');
        this.pushSuccessNotification(message, { ...options, category });
    },

    // ==================== ECONOMIC CONSEQUENCE PREVIEW / CONFIRM / RECEIPT ====================

    // Pending confirmation callback — set before opening the modal.
    _econPendingAction: null,

    /**
     * Render a preview payload into the impact-detail panel.
     */
    _renderEconImpact(preview, title) {
        const risk = preview.risk || { severity: 'low', flags: [], explain: '' };
        const sev = risk.severity || 'low';
        const sevEmoji = sev === 'high' ? '🔴' : sev === 'medium' ? '🟡' : '🟢';

        const titleEl = document.getElementById('econ-confirm-title');
        if (titleEl) titleEl.textContent = title || 'Confirm Action';

        const iconEl = document.getElementById('econ-risk-icon');
        if (iconEl) iconEl.textContent = sev === 'high' ? '⚠️' : sev === 'medium' ? '⚡' : 'ℹ️';

        const detailsEl = document.getElementById('econ-impact-details');
        if (!detailsEl) return;

        const fmt = (n) => this.formatNumber(n);
        const balType = preview.balance_type || 'coins';
        const isSigil = balType.startsWith('sigils_t');
        const balLabel = isSigil ? `Tier ${balType.replace('sigils_t','')} Sigils` : 'Coins';

        let rows = '';
        if (preview.preview_type === 'sigil_theft') {
            rows += `<div class="econ-impact-row"><span class="econ-impact-label">Target</span><span class="econ-impact-value">${this.escapeHtml(preview.target_handle || 'Unknown')}</span></div>`;
            rows += `<div class="econ-impact-row"><span class="econ-impact-label">Spend</span><span class="econ-impact-value">${this.formatSigilVectorSummary(preview.spent_sigils)}</span></div>`;
            rows += `<div class="econ-impact-row"><span class="econ-impact-label">Attempted loot</span><span class="econ-impact-value">${this.formatSigilVectorSummary(preview.requested_sigils)}</span></div>`;
            rows += `<div class="econ-impact-row"><span class="econ-impact-label">Success chance</span><span class="econ-impact-value">${Number(preview.success_chance_pct || 0).toFixed(2)}%</span></div>`;
            rows += `<div class="econ-impact-row"><span class="econ-impact-label">Spend value</span><span class="econ-impact-value">${fmt(preview.spend_value || 0)}</span></div>`;
            rows += `<div class="econ-impact-row"><span class="econ-impact-label">Loot value</span><span class="econ-impact-value">${fmt(preview.requested_value || 0)}</span></div>`;
            rows += `<div class="econ-impact-row"><span class="econ-impact-label">Remaining T4/T5 after spend</span><span class="econ-impact-value ${sev === 'high' ? 'danger' : sev === 'medium' ? 'warn' : ''}">${fmt(preview.post_balance_estimate || 0)}</span></div>`;
        } else if (!isSigil) {
            rows += `<div class="econ-impact-row"><span class="econ-impact-label">Total cost</span><span class="econ-impact-value">${fmt(preview.estimated_total_cost)} coins</span></div>`;
            if (preview.estimated_fee > 0) {
                rows += `<div class="econ-impact-row"><span class="econ-impact-label">Fee included</span><span class="econ-impact-value">${fmt(preview.estimated_fee)} coins</span></div>`;
            }
            if (preview.estimated_price_impact_pct != null) {
                const impactClass = preview.estimated_price_impact_pct > 0.5 ? 'warn' : '';
                rows += `<div class="econ-impact-row"><span class="econ-impact-label">Supply impact</span><span class="econ-impact-value ${impactClass}">${preview.estimated_price_impact_pct.toFixed(2)}% (${fmt(preview.estimated_price_impact_bp)} bp)</span></div>`;
            }
            rows += `<div class="econ-impact-row"><span class="econ-impact-label">Balance after</span><span class="econ-impact-value ${sev === 'high' ? 'danger' : sev === 'medium' ? 'warn' : ''}">${fmt(preview.post_balance_estimate)} ${balLabel}</span></div>`;
        } else {
            rows += `<div class="econ-impact-row"><span class="econ-impact-label">Sigil cost</span><span class="econ-impact-value">${fmt(preview.estimated_total_cost)} ${balLabel}</span></div>`;
            rows += `<div class="econ-impact-row"><span class="econ-impact-label">Remaining after</span><span class="econ-impact-value ${sev === 'high' ? 'danger' : sev === 'medium' ? 'warn' : ''}">${fmt(preview.post_balance_estimate)} ${balLabel}</span></div>`;
        }

        detailsEl.innerHTML = `
            <div style="margin-bottom:0.6rem;">
                <span class="econ-risk-badge ${sev}">${sevEmoji} ${sev.toUpperCase()} RISK</span>
            </div>
            ${risk.explain ? `<div class="econ-risk-explain">${this.escapeHtml(risk.explain)}</div>` : ''}
            ${rows}
        `;
    },

    /**
     * Show the economic confirmation modal for a medium/high-risk action.
     * @param {object} preview  Preview payload from the server.
     * @param {string} title    Modal heading.
     * @param {Function} onConfirm  Async callback to execute when confirmed.
     */
    showEconConfirm(preview, title, onConfirm) {
        this._renderEconImpact(preview, title);
        this._econPendingAction = onConfirm;

        const checkbox = document.getElementById('econ-confirm-checkbox');
        const confirmBtn = document.getElementById('econ-confirm-btn');
        if (checkbox) {
            checkbox.checked = false;
            checkbox.onchange = () => { if (confirmBtn) confirmBtn.disabled = !checkbox.checked; };
        }
        if (confirmBtn) confirmBtn.disabled = true;

        const modal = document.getElementById('econ-confirm-modal');
        if (modal) modal.style.display = 'flex';
    },

    closeEconConfirm(skipCancelResolve = false) {
        const modal = document.getElementById('econ-confirm-modal');
        if (modal) modal.style.display = 'none';
        if (!skipCancelResolve && typeof this._econCancelResolver === 'function') {
            const resolver = this._econCancelResolver;
            this._econCancelResolver = null;
            resolver(null);
        }
        this._econPendingAction = null;
    },

    async executeEconConfirmed() {
        const cb = this._econPendingAction;
        this.closeEconConfirm(true);
        if (cb) await cb();
    },

    /**
     * Show a post-action receipt modal.
     */
    showEconReceipt(receipt, label) {
        const detailsEl = document.getElementById('econ-receipt-details');
        if (!detailsEl) return;

        const fmt = (n) => this.formatNumber(n);
        let rows = '';
        if (receipt.preview_type === 'sigil_theft') {
            rows += `<div class="econ-impact-row"><span class="econ-impact-label">Target</span><span class="econ-impact-value">${this.escapeHtml(receipt.target_handle || 'Unknown')}</span></div>`;
            rows += `<div class="econ-impact-row"><span class="econ-impact-label">Spent sigils</span><span class="econ-impact-value">${this.formatSigilVectorSummary(receipt.spent_sigils)}</span></div>`;
            rows += `<div class="econ-impact-row"><span class="econ-impact-label">Attempted loot</span><span class="econ-impact-value">${this.formatSigilVectorSummary(receipt.requested_sigils)}</span></div>`;
            rows += `<div class="econ-impact-row"><span class="econ-impact-label">Success chance</span><span class="econ-impact-value">${Number(receipt.success_chance_pct || 0).toFixed(2)}%</span></div>`;
            rows += `<div class="econ-impact-row"><span class="econ-impact-label">Outcome</span><span class="econ-impact-value">${receipt.theft_success ? 'Success' : 'Failed'}</span></div>`;
            if (receipt.theft_success) {
                rows += `<div class="econ-impact-row"><span class="econ-impact-label">Transferred</span><span class="econ-impact-value">${this.formatSigilVectorSummary(receipt.transferred_sigils)}</span></div>`;
            }
            rows += `<div class="econ-impact-row"><span class="econ-impact-label">Spend value</span><span class="econ-impact-value">${fmt(receipt.spend_value || 0)}</span></div>`;
            rows += `<div class="econ-impact-row"><span class="econ-impact-label">Loot value</span><span class="econ-impact-value">${fmt(receipt.requested_value || 0)}</span></div>`;
            rows += `<div class="econ-impact-row"><span class="econ-impact-label">Remaining T4/T5 after spend</span><span class="econ-impact-value">${fmt(receipt.post_balance_estimate || 0)}</span></div>`;
        } else if (receipt.stars_purchased != null) {
            rows += `<div class="econ-impact-row"><span class="econ-impact-label">Stars purchased</span><span class="econ-impact-value">${fmt(receipt.stars_purchased)}</span></div>`;
        }
        if (receipt.preview_type !== 'sigil_theft' && receipt.executed_total_cost != null) {
            rows += `<div class="econ-impact-row"><span class="econ-impact-label">Total spent</span><span class="econ-impact-value">${fmt(receipt.executed_total_cost)}</span></div>`;
        }
        if (receipt.preview_type !== 'sigil_theft' && receipt.executed_fee > 0) {
            rows += `<div class="econ-impact-row"><span class="econ-impact-label">Fee burned</span><span class="econ-impact-value">${fmt(receipt.executed_fee)}</span></div>`;
        }
        if (receipt.preview_type !== 'sigil_theft' && receipt.declared_value != null) {
            rows += `<div class="econ-impact-row"><span class="econ-impact-label">Declared value</span><span class="econ-impact-value">${fmt(receipt.declared_value)}</span></div>`;
        }
        if (receipt.preview_type !== 'sigil_theft' && receipt.sigils_consumed != null) {
            rows += `<div class="econ-impact-row"><span class="econ-impact-label">Sigils consumed</span><span class="econ-impact-value">${fmt(receipt.sigils_consumed)} T${receipt.tier_consumed || '?'}</span></div>`;
        }
        if (receipt.preview_type !== 'sigil_theft') {
            rows += `<div class="econ-impact-row"><span class="econ-impact-label">Balance after</span><span class="econ-impact-value">${fmt(receipt.post_balance_estimate)}</span></div>`;
        }

        detailsEl.innerHTML = `<div class="econ-impact-details">${rows}</div>`;

        const modal = document.getElementById('econ-receipt-modal');
        if (modal) modal.style.display = 'flex';
    },

    /**
     * High-level: preview → if high/medium impact show confirm modal, else execute directly.
     * The executeFn must accept confirm_economic_impact as a boolean argument.
     */
    async runWithEconGate(previewFn, executeFn, title) {
        const openConfirmFlow = async (previewPayload, resolve) => {
            const executeConfirmedAction = async () => {
                try {
                    const confirmedResult = await executeFn(true);
                    if (confirmedResult && (confirmedResult.reason_code === 'balance_changed' || confirmedResult.error === 'balance_changed')) {
                        const refreshedPreview = confirmedResult.preview && !confirmedResult.preview.error ? confirmedResult.preview : null;
                        if (refreshedPreview) {
                            this.toast(confirmedResult.message || 'Balance changed. Please review and confirm again.', 'info');
                            openConfirmFlow(refreshedPreview, resolve);
                            return;
                        }
                    }
                    resolve(confirmedResult);
                } catch (error) {
                    const actionLabel = title || 'this action';
                    console.error(`Error executing confirmed economic action (${actionLabel}):`, error);
                    this.toast(`Failed to complete ${actionLabel}. Please try again.`, 'error');
                    resolve(null);
                }
            };

            const modal = document.getElementById('econ-confirm-modal');
            if (!modal) {
                const ok = window.confirm('This action has economic impact and requires confirmation. Proceed?');
                if (!ok) {
                    resolve(null);
                    return;
                }
                await executeConfirmedAction();
                return;
            }

            // Guard: if a prior confirmation is still pending (e.g. double-click /
            // rapid re-entry), cancel it so its Promise resolves cleanly rather than
            // leaking and causing a dead-end for that caller.
            if (typeof this._econCancelResolver === 'function') {
                const stale = this._econCancelResolver;
                this._econCancelResolver = null;
                stale(null);
            }

            this._econCancelResolver = resolve;
            this.showEconConfirm(previewPayload, title, async () => {
                this._econCancelResolver = null;
                await executeConfirmedAction();
            });
        };

        const preview = await previewFn();
        if (!preview || preview.error) {
            this.toast(preview ? preview.error : 'Preview failed', 'error');
            return null;
        }

        if (preview.requires_explicit_confirm) {
            return new Promise((resolve) => {
                openConfirmFlow(preview, resolve);
            });
        }

        const directResult = await executeFn(false);
        if (directResult && directResult.error === 'confirmation_required') {
            return new Promise((resolve) => {
                const previewUnavailableMessage = 'Confirmation required but preview is unavailable. Please try again.';
                const serverPreview = directResult.preview && !directResult.preview.error ? directResult.preview : null;
                if (serverPreview) {
                    openConfirmFlow(serverPreview, resolve);
                    return;
                }

                previewFn().then((retriedPreview) => {
                    if (retriedPreview && !retriedPreview.error) {
                        openConfirmFlow(retriedPreview, resolve);
                        return;
                    }
                    // Preview unavailable — resolve null so callers get a clean
                    // "no result" rather than a dead-end confirmation_required object.
                    this.toast(previewUnavailableMessage, 'error');
                    resolve(null);
                }).catch(() => {
                    this.toast(previewUnavailableMessage, 'error');
                    resolve(null);
                });
            });
        }

        if (directResult && (directResult.reason_code === 'balance_changed' || directResult.error === 'balance_changed')) {
            return new Promise((resolve) => {
                const serverPreview = directResult.preview && !directResult.preview.error ? directResult.preview : null;
                if (!serverPreview) {
                    this.toast(directResult.message || 'Balance changed. Please try again.', 'error');
                    resolve(directResult);
                    return;
                }
                this.toast(directResult.message || 'Balance changed. Please review and confirm again.', 'info');
                openConfirmFlow(serverPreview, resolve);
            });
        }

        return directResult;
    },

    /**
     * Wrap purchaseStars to use the preview/confirm/receipt flow.
     */
    async purchaseStarsGated() {
        const input = document.getElementById('purchase-stars');
        const starsRequested = parseInt(input ? input.value : '0');
        if (!starsRequested || starsRequested <= 0) {
            this.toast('Enter a valid star quantity.', 'error');
            return;
        }

        const result = await this.runWithEconGate(
            () => this.api('star_purchase_preview', { stars_requested: starsRequested }),
            (confirm) => this.api('purchase_stars', { stars_requested: starsRequested, confirm_economic_impact: confirm ? 1 : 0 }),
            'Confirm Star Purchase'
        );

        if (!result) return;
        if (result.error) {
            this.toast(result.error, 'error');
            return;
        }
        if (result.success) {
            this.toast(`Purchased ${this.formatNumber(result.stars_purchased)} stars for ${this.formatNumber(result.coins_spent)} coins!`, 'success', {
                category: 'purchase_star',
                payload: {
                    stars_purchased: Number(result.stars_purchased) || 0,
                    coins_spent: Number(result.coins_spent) || 0,
                    season_id: Number(this.state.currentSeason) || null
                }
            });
            if (result.receipt) this.showEconReceipt(result.receipt, 'Star Purchase Complete');
            if (input) input.value = '';
            this.updatePurchaseEstimate();
            await this.refreshGameState();
        }
    },

    /**
     * Wrap sigil theft to use the preview/confirm/receipt flow.
     */
    async submitSigilTheftGated(targetPlayerId) {
        const targetId = parseInt(targetPlayerId, 10) || 0;
        if (!targetId) {
            this.toast('Choose a target profile before attempting sigil theft.', 'error');
            return;
        }

        const spentSigils = [0, 1, 2, 3, 4, 5].map((index) => {
            const el = document.getElementById(`theft-spend-sigil-${index}`);
            return el ? (parseInt(el.value, 10) || 0) : 0;
        });
        const requestedSigils = [0, 1, 2, 3, 4, 5].map((index) => {
            const el = document.getElementById(`theft-request-sigil-${index}`);
            return el ? (parseInt(el.value, 10) || 0) : 0;
        });

        const theftParams = {
            target_player_id: targetId,
            spent_sigils: spentSigils,
            requested_sigils: requestedSigils,
        };

        const result = await this.runWithEconGate(
            () => this.api('sigil_theft_preview', theftParams),
            (confirm) => this.api('sigil_theft_attempt', { ...theftParams, confirm_economic_impact: confirm ? 1 : 0 }),
            'Confirm Sigil Theft'
        );

        if (!result) return;
        if (result.error) {
            this.toast(result.error, 'error');
            return;
        }
        if (result.success) {
            this.toast(result.message || 'Theft resolved.', result.theft_success ? 'success' : 'warning', {
                category: result.theft_success ? 'sigil_theft_success' : 'sigil_theft_failed',
                payload: {
                    target_player_id: Number(result.target_player_id) || targetId,
                    theft_success: !!result.theft_success,
                    theft_id: Number(result.theft_id) || null,
                }
            });
            if (result.receipt) this.showEconReceipt(result.receipt, 'Sigil Theft Resolved');
            await this.refreshGameState();
            this.renderTheftScreen({ seasonId: Number(this.state.player?.joined_season_id || 0), targetPlayerId: targetId });
        }
    },

    /**
     * Wrap purchaseBoostPower to use the preview/confirm/receipt flow.
     */
    async purchaseBoostPowerGated(boostId) {
        const boost = this._boostCatalog ? this._boostCatalog.find(b => b.boost_id == boostId) : null;
        const tier = boost ? (parseInt(boost.tier_required, 10) || 0) : 0;
        if (tier < 1 || tier > 5) {
            this.toast('Unable to resolve sigil tier for +Power action.', 'error');
            return;
        }
        await this.spendSigilBoostGated(tier, 'power', boostId);
    },

    /**
     * Wrap purchaseBoostTime to use the preview/confirm/receipt flow.
     */
    async purchaseBoostTimeGated(boostId, selectedTier = null) {
        const tierToUse = (selectedTier && selectedTier >= 1 && selectedTier <= 5)
            ? selectedTier
            : this.chooseTimeSigilTier(boostId);
        if (!tierToUse) return;
        await this.spendSigilBoostGated(tierToUse, 'time', boostId);
    },

    async spendSigilBoostGated(sigilTier, purchaseKind, legacyBoostId = null) {
        const tier = parseInt(sigilTier, 10) || 0;
        const kind = String(purchaseKind || '').toLowerCase();
        if (tier < 1 || tier > 5) {
            this.toast('Invalid sigil tier', 'error');
            return;
        }
        if (kind !== 'power' && kind !== 'time') {
            this.toast('Invalid boost action', 'error');
            return;
        }

        const title = `Confirm: Tier ${tier} ${kind === 'power' ? '+Power' : '+Time'}`;
        const payload = {
            sigil_tier: tier,
            purchase_kind: kind,
            boost_id: legacyBoostId ? Number(legacyBoostId) : undefined,
        };

        const result = await this.runWithEconGate(
            () => this.api('boost_activate_preview', payload),
            (confirm) => this.api('purchase_boost', { ...payload, confirm_economic_impact: confirm ? 1 : 0 }),
            title
        );

        if (!result) return;
        if (result.error) {
            this.toast(result.error, 'error');
            return;
        }
        if (result.success) {
            this.toast(result.message, 'success', {
                category: 'boost_activate',
                payload: {
                    sigil_tier: tier,
                    purchase_kind: kind,
                    season_id: Number(this.state.currentSeason) || null
                }
            });
            if (result.receipt) this.showEconReceipt(result.receipt, 'Boost Updated');
            await this.refreshGameState();
            if (this.state.currentSeason) {
                this.loadSeasonDetail(this.state.currentSeason);
            }
        }
    },
};

// Initialize on load
document.addEventListener('DOMContentLoaded', () => TMC.init());
