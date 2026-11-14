/**
 * core/clock.js — one scheduler for everything time-driven
 *
 * Replaces the legacy client's two setIntervals (a 3s poll and a 1s countdown
 * tick). Three reasons they are worth replacing:
 *
 *   1. setInterval drifts, and it drifts differently per interval, so two
 *      timers started together separate over a session. Deadlines here are
 *      absolute, so a late frame is absorbed rather than accumulated.
 *   2. setInterval keeps firing in a background tab. The old client polled
 *      every 3s forever behind a switched-away tab; this one stops and
 *      resyncs on return, which is both correct and much kinder to the server.
 *   3. Countdowns and the coin-arrival pulse need to agree with each other.
 *      One clock means they cannot disagree.
 *
 * ---- on tick phase ----
 *
 * The server awards coins on a fixed real-time interval. `game_state.timing`
 * publishes the *length* of that interval (tick_real_seconds) but not when the
 * last one fired, so a client-side countdown knows the period and not the
 * phase: it would hit zero at an arbitrary offset from when coins actually
 * land, and teach the player a rhythm that is wrong.
 *
 * So phase is opt-in. Until `setTickPhase` is given a real `nextTickAt` (or
 * the legacy `lastTickAt`),
 * `secondsToNextTick()` returns null and callers are expected to render a
 * period-only affordance rather than a countdown that lies. Stage 2 adds
 * `last_tick_at` to the API and phase becomes available; nothing here changes
 * when it does.
 */

const DEFAULT_HIDDEN_RESYNC = true;

export function createClock(options = {}) {
    const {
        now = () => Date.now(),
        raf = (cb) => requestAnimationFrame(cb),
        cancelRaf = (id) => cancelAnimationFrame(id),
        doc = typeof document !== 'undefined' ? document : null,
        resyncOnVisible = DEFAULT_HIDDEN_RESYNC,
    } = options;

    const frameCallbacks = new Set();
    const intervals = new Set(); // {periodMs, fn, dueAt}
    const tickListeners = new Set();

    let rafId = null;
    let running = false;

    // Tick phase, unknown until told otherwise. lastTickAtMs is in THIS
    // device's clock.
    let periodMs = null;
    let lastTickAtMs = null;
    let lastTickIndex = null;

    // Server clock offset (server ms - local ms). Kept from the sample with
    // the smallest round trip, because that one has the least network error;
    // a sample is replaced once it is old, so slow device-clock drift is
    // still followed.
    let offsetMs = null;
    let offsetRttMs = Infinity;
    let offsetSampledAt = -Infinity;
    const OFFSET_SAMPLE_TTL_MS = 5 * 60 * 1000;
    // Phase changes smaller than this are network noise, not a new rhythm.
    const PHASE_TOLERANCE_MS = 250;

    function schedule() {
        if (running || rafId !== null) return;
        rafId = raf(frame);
    }

    function frame() {
        rafId = null;
        running = true;
        const t = now();

        for (const entry of intervals) {
            if (t < entry.dueAt) continue;

            // Absolute deadlines, but never replay a backlog. Returning from a
            // long pause should produce one catch-up run, not sixty.
            const missed = Math.floor((t - entry.dueAt) / entry.periodMs) + 1;
            entry.dueAt += missed * entry.periodMs;

            try {
                entry.fn(t);
            } catch (err) {
                console.error('[clock] interval callback threw:', err);
            }
        }

        if (periodMs && lastTickAtMs !== null) {
            const index = Math.floor((t - lastTickAtMs) / periodMs);
            if (lastTickIndex === null) {
                lastTickIndex = index;
            } else if (index > lastTickIndex) {
                lastTickIndex = index;
                for (const fn of tickListeners) {
                    try {
                        fn(t);
                    } catch (err) {
                        console.error('[clock] tick listener threw:', err);
                    }
                }
            }
        }

        for (const fn of frameCallbacks) {
            try {
                fn(t);
            } catch (err) {
                console.error('[clock] frame callback threw:', err);
            }
        }

        running = false;
        if (frameCallbacks.size || intervals.size || tickListeners.size) schedule();
    }

    if (doc && resyncOnVisible && typeof doc.addEventListener === 'function') {
        doc.addEventListener('visibilitychange', () => {
            if (doc.visibilityState !== 'visible') return;
            // rAF was parked while hidden, so every deadline is stale. Make
            // them all due now: one catch-up pass, then normal cadence.
            const t = now();
            for (const entry of intervals) entry.dueAt = Math.min(entry.dueAt, t);
            // Phase is meaningless across a long pause; recompute on next frame.
            lastTickIndex = null;
            schedule();
        });
    }

    return {
        /** Run `fn` every `periodMs`. Returns an unsubscribe function. */
        every(periodMs_, fn) {
            const entry = { periodMs: Math.max(16, periodMs_), fn, dueAt: now() + periodMs_ };
            intervals.add(entry);
            schedule();
            return () => intervals.delete(entry);
        },

        /** Run `fn` on every animation frame. Returns an unsubscribe function. */
        onFrame(fn) {
            frameCallbacks.add(fn);
            schedule();
            return () => frameCallbacks.delete(fn);
        },

        /** Run `fn` when a server tick boundary is crossed. Silent until phase is known. */
        onTick(fn) {
            tickListeners.add(fn);
            schedule();
            return () => tickListeners.delete(fn);
        },

        /**
         * Record one server-time sample.
         *
         * @param {number} serverTimeMs  the server's epoch ms when it built the response
         * @param {number} sentAt        local ms when the request left
         * @param {number} receivedAt    local ms when the response arrived
         */
        syncServerTime(serverTimeMs, sentAt, receivedAt) {
            const server = Number(serverTimeMs);
            if (!Number.isFinite(server)) return;
            const rtt = Math.max(0, Number(receivedAt) - Number(sentAt));
            const stale = Number(receivedAt) - offsetSampledAt > OFFSET_SAMPLE_TTL_MS;
            if (offsetMs !== null && !stale && rtt >= offsetRttMs) return;
            offsetMs = server - (Number(sentAt) + Number(receivedAt)) / 2;
            offsetRttMs = rtt;
            offsetSampledAt = Number(receivedAt);
        },

        /** Server epoch ms now, or null before the first sample. */
        serverNow() {
            return offsetMs === null ? null : now() + offsetMs;
        },

        /**
         * Seconds until a server epoch-ms deadline (never negative), or null
         * when the deadline or the server offset is unknown.
         */
        secondsUntil(serverEpochMs) {
            if (offsetMs === null || serverEpochMs === null || serverEpochMs === undefined) return null;
            const target = Number(serverEpochMs);
            if (!Number.isFinite(target)) return null;
            return Math.max(0, (target - (now() + offsetMs)) / 1000);
        },

        /**
         * Teach the clock the server's tick rhythm.
         *
         * Prefer nextTickAt (server epoch ms of the next boundary): it is the
         * same for every client and every poll. lastTickAt is the legacy
         * input, already in this device's clock.
         *
         * Re-teaching the same rhythm is a no-op, so a poll never resets the
         * countdown or swallows the payout pulse at a boundary.
         *
         * @param {number|null} nextTickAt      server epoch ms of the next tick
         * @param {number|null} lastTickAt      local epoch ms of the most recent tick
         * @param {number|null} periodSeconds   tick_real_seconds
         */
        setTickPhase({ nextTickAt = null, lastTickAt = null, periodSeconds = null } = {}) {
            const nextPeriod = periodSeconds ? Number(periodSeconds) * 1000 : null;
            let nextLast = null;
            if (nextPeriod && nextTickAt !== null && nextTickAt !== undefined && offsetMs !== null) {
                nextLast = Number(nextTickAt) - offsetMs - nextPeriod;
            } else if (lastTickAt !== null && lastTickAt !== undefined) {
                nextLast = Number(lastTickAt);
            }

            const samePeriod = nextPeriod === periodMs;
            let samePhase = false;
            if (samePeriod && nextPeriod && nextLast !== null && lastTickAtMs !== null) {
                const drift = Math.abs(nextLast - lastTickAtMs) % nextPeriod;
                samePhase = Math.min(drift, nextPeriod - drift) <= PHASE_TOLERANCE_MS;
            }
            if (samePhase) return;

            periodMs = nextPeriod;
            lastTickAtMs = nextLast;
            lastTickIndex = null;
            schedule();
        },

        /** True once the clock knows both period and phase. */
        hasTickPhase() {
            return Boolean(periodMs && lastTickAtMs !== null);
        },

        /** Seconds until the next server tick, or null while phase is unknown. */
        secondsToNextTick() {
            if (!periodMs || lastTickAtMs === null) return null;
            const elapsed = (now() - lastTickAtMs) % periodMs;
            return (periodMs - elapsed) / 1000;
        },

        /** Progress through the current tick as 0..1, or null while phase is unknown. */
        tickProgress() {
            if (!periodMs || lastTickAtMs === null) return null;
            const elapsed = (now() - lastTickAtMs) % periodMs;
            return elapsed / periodMs;
        },

        /** Stop everything. The clock is reusable afterwards. */
        stop() {
            if (rafId !== null) { cancelRaf(rafId); rafId = null; }
            frameCallbacks.clear();
            intervals.clear();
            tickListeners.clear();
        },
    };
}
