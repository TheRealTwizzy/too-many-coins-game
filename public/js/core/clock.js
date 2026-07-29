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
 * So phase is opt-in. Until `setTickPhase` is given a real `lastTickAt`,
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

    // Tick phase, unknown until told otherwise.
    let periodMs = null;
    let lastTickAtMs = null;
    let lastTickIndex = null;

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
         * Teach the clock the server's tick rhythm.
         *
         * @param {number|null} lastTickAt      epoch ms of the most recent tick, or null if unpublished
         * @param {number|null} periodSeconds   tick_real_seconds
         */
        setTickPhase({ lastTickAt = null, periodSeconds = null } = {}) {
            periodMs = periodSeconds ? Number(periodSeconds) * 1000 : null;
            lastTickAtMs = lastTickAt === null || lastTickAt === undefined ? null : Number(lastTickAt);
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
