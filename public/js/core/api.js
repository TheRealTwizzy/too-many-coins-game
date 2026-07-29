/**
 * core/api.js — API client
 *
 * Ported from TMC.api. Two behaviours here were bug fixes earned the hard way
 * and are preserved deliberately rather than rewritten; each is marked below.
 *
 * The difference from the original is that this module has no opinion about
 * the DOM. The legacy client called handleLoggedOut() and showed a toast from
 * inside the fetch wrapper, which made the transport impossible to reason
 * about or exercise on its own. Here those become callbacks the caller wires.
 */

const DEFAULT_TIMEOUT_MS = 15000;
const BACKOFF_CEILING_MS = 30000;
const BACKOFF_FLOOR_MS = 3000;
const BACKOFF_JITTER_MS = 400;

export function createApi(options = {}) {
    const {
        base = '/api/index.php',
        tokenKey = 'tmc_token',
        timeoutMs = DEFAULT_TIMEOUT_MS,
        onUnauthorized = () => {},
        onRateLimit = () => {},
        fetchImpl = (...args) => fetch(...args),
        now = () => Date.now(),
        random = () => Math.random(),
    } = options;

    // Backoff is tracked per channel so a rate-limited chat poll does not
    // also stall the game-state poll, and vice versa.
    const channels = new Map(); // name -> {delayMs, untilMs}
    const inFlight = new Map(); // dedupe key -> Promise

    function channel(name) {
        let c = channels.get(name);
        if (!c) {
            c = { delayMs: 0, untilMs: 0 };
            channels.set(name, c);
        }
        return c;
    }

    function isBackingOff(name) {
        return now() < channel(name).untilMs;
    }

    function applyBackoff(name, baseMs = BACKOFF_FLOOR_MS) {
        const c = channel(name);
        const next = c.delayMs > 0
            ? Math.min(c.delayMs * 2, BACKOFF_CEILING_MS)
            : Math.max(baseMs, BACKOFF_FLOOR_MS);
        c.delayMs = next;
        c.untilMs = now() + next + Math.floor(random() * BACKOFF_JITTER_MS);
        onRateLimit({ channel: name, retryInMs: c.untilMs - now() });
    }

    function resetBackoff(name) {
        const c = channel(name);
        c.delayMs = 0;
        c.untilMs = 0;
    }

    function readToken() {
        try {
            return localStorage.getItem(tokenKey) || '';
        } catch {
            // Private browsing modes can throw on localStorage access. The
            // session cookie is HttpOnly and same-origin, so the header is a
            // fallback rather than the only credential — losing it is survivable.
            return '';
        }
    }

    async function send(action, data, signal) {
        const response = await fetchImpl(base + '?action=' + encodeURIComponent(action), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Session-Token': readToken(),
            },
            body: JSON.stringify({ action, ...data }),
            signal,
        });

        let json = {};
        try {
            json = await response.json();
        } catch {
            json = {};
        }

        if (response.status === 429) {
            return { ...json, status: 429, error: json.error || 'Rate limit exceeded. Please slow down.' };
        }

        // PRESERVED FIX: only 401 means "no valid session". 403 is
        // permission-denied — Permissions::requireStaff/requireAdmin and the
        // tick secret all return it — so treating 403 as a session failure
        // logged players out for merely touching a staff-gated endpoint.
        if (response.status === 401 || (json.error && json.error.includes('Authentication required'))) {
            onUnauthorized({ status: response.status, error: json.error });
        }

        if (!response.ok) {
            return { ...json, status: response.status, error: json.error || `Request failed (${response.status})` };
        }

        return json;
    }

    /**
     * Call an API action.
     *
     * Resolves rather than rejects: every failure arrives as `{error, status}`
     * so callers handle one shape. status 0 means the request never landed.
     *
     * @param {object} [opts]
     * @param {string} [opts.channel]  backoff channel; 'poll' and 'chat' are the live ones
     * @param {boolean} [opts.dedupe]  collapse into an identical in-flight request
     * @param {boolean} [opts.respectBackoff] skip entirely while the channel is backing off
     */
    async function request(action, data = {}, opts = {}) {
        const {
            channel: channelName = 'default',
            dedupe = false,
            respectBackoff = false,
        } = opts;

        if (respectBackoff && isBackingOff(channelName)) {
            return { error: 'Backing off', status: 0, skipped: true };
        }

        // Only opt-in, and only for reads. Two deliberate presses of a buy
        // button are two purchases and must never collapse into one.
        const dedupeKey = dedupe ? `${channelName}:${action}:${JSON.stringify(data)}` : null;
        if (dedupeKey && inFlight.has(dedupeKey)) {
            return inFlight.get(dedupeKey);
        }

        // The legacy client had no timeout, so one hung request stalled its
        // channel until the browser gave up — which on mobile can be minutes.
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), timeoutMs);

        const work = (async () => {
            try {
                const result = await send(action, data, controller.signal);

                // Retry-worthy: rate limited, server-side, or never landed.
                if (result && (result.status === 429 || result.status >= 500 || result.status === 0)) {
                    applyBackoff(channelName);
                } else {
                    resetBackoff(channelName);
                }
                return result;
            } catch (err) {
                const aborted = err && err.name === 'AbortError';
                applyBackoff(channelName);
                return {
                    error: aborted ? 'Request timed out. Retrying shortly.' : 'Network error. Please try again.',
                    status: 0,
                    timedOut: aborted,
                };
            } finally {
                clearTimeout(timer);
                if (dedupeKey) inFlight.delete(dedupeKey);
            }
        })();

        if (dedupeKey) inFlight.set(dedupeKey, work);
        return work;
    }

    return {
        request,
        isBackingOff,
        resetBackoff,
        /** Milliseconds until `channel` is callable again; 0 when it already is. */
        backoffRemaining(name = 'default') {
            return Math.max(0, channel(name).untilMs - now());
        },
    };
}
