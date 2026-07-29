/**
 * core/motion.js — motion primitives
 *
 * Everything animated reads its duration and curve from the token layer
 * (public/css/tokens.css) rather than hard-coding numbers, so the feel of the
 * game is tuned in one file instead of forty call sites.
 *
 * Reduced motion is handled here, once, rather than per component. The rule is
 * that reduced motion removes the *interpolation*, never the *outcome*: a
 * counter still reaches its new value, a panel still opens, a gain still
 * registers. Nothing is skipped, only the travel between states.
 */

const DURATION_TOKENS = { micro: '--t-micro', move: '--t-move', moment: '--t-moment', ceremony: '--t-ceremony' };
const EASING_TOKENS = { gain: '--e-gain', move: '--e-move', loss: '--e-loss' };

const FALLBACK_DURATION = { micro: 150, move: 300, moment: 600, ceremony: 2400 };
const FALLBACK_EASING = {
    gain: 'cubic-bezier(.2, 1.4, .4, 1)',
    move: 'cubic-bezier(.2, 1, .3, 1)',
    loss: 'cubic-bezier(.4, 0, 1, 1)',
};

function parseMs(value, fallback) {
    if (!value) return fallback;
    const text = String(value).trim();
    const n = parseFloat(text);
    if (!Number.isFinite(n)) return fallback;
    return text.endsWith('ms') ? n : (text.endsWith('s') ? n * 1000 : n);
}

export function createMotion(options = {}) {
    const {
        win = typeof window !== 'undefined' ? window : null,
        doc = typeof document !== 'undefined' ? document : null,
        now = () => (win && win.performance ? win.performance.now() : Date.now()),
        raf = (cb) => (win ? win.requestAnimationFrame(cb) : setTimeout(() => cb(now()), 16)),
    } = options;

    const query = win && win.matchMedia ? win.matchMedia('(prefers-reduced-motion: reduce)') : null;
    let reduced = query ? query.matches : false;

    if (query) {
        const onChange = (e) => { reduced = e.matches; };
        // Safari < 14 only has the deprecated form.
        if (typeof query.addEventListener === 'function') query.addEventListener('change', onChange);
        else if (typeof query.addListener === 'function') query.addListener(onChange);
    }

    // Token lookups hit getComputedStyle, which forces style resolution, so
    // they are cached. Cleared on theme change, since a theme may retune motion.
    let tokenCache = new Map();

    function readToken(name, fallback) {
        if (tokenCache.has(name)) return tokenCache.get(name);
        let value = fallback;
        if (doc && win && win.getComputedStyle) {
            const raw = win.getComputedStyle(doc.documentElement).getPropertyValue(name);
            if (raw && raw.trim()) value = raw.trim();
        }
        tokenCache.set(name, value);
        return value;
    }

    function durationMs(token = 'move') {
        const fallback = FALLBACK_DURATION[token] ?? FALLBACK_DURATION.move;
        if (reduced) return 0;
        const cssName = DURATION_TOKENS[token];
        if (!cssName) return fallback;
        return parseMs(readToken(cssName, `${fallback}ms`), fallback);
    }

    function easing(token = 'move') {
        if (reduced) return 'linear';
        const cssName = EASING_TOKENS[token];
        if (!cssName) return FALLBACK_EASING.move;
        return readToken(cssName, FALLBACK_EASING[token] ?? FALLBACK_EASING.move);
    }

    /**
     * Run a Web Animations keyframe set.
     *
     * Under reduced motion the final keyframe is committed immediately and the
     * returned promise resolves on the next frame — so `await` still works and
     * callers need no branch of their own.
     */
    function animate(el, keyframes, opts = {}) {
        const { duration = 'move', easing: easingToken = 'move', fill = 'none', delay = 0 } = opts;
        const ms = typeof duration === 'number' ? (reduced ? 0 : duration) : durationMs(duration);

        if (!el || typeof el.animate !== 'function') {
            return { finished: Promise.resolve(), cancel() {} };
        }

        const animation = el.animate(keyframes, {
            duration: Math.max(0, ms),
            easing: typeof easingToken === 'string' && easingToken.includes('(') ? easingToken : easing(easingToken),
            fill,
            delay: reduced ? 0 : delay,
        });

        return {
            finished: animation.finished ? animation.finished.catch(() => {}) : Promise.resolve(),
            cancel: () => animation.cancel(),
        };
    }

    /**
     * Add a class, then remove it once its animation ends.
     *
     * The class is removed on the next frame first, so re-pulsing an element
     * that is already pulsing restarts the animation instead of being ignored
     * — which is what happens if you add a class that is already present.
     */
    function pulse(el, className, opts = {}) {
        if (!el || !el.classList) return Promise.resolve();
        const { duration = 'moment' } = opts;

        el.classList.remove(className);

        if (reduced) {
            // The state change still needs to register; it just does not travel.
            return Promise.resolve();
        }

        return new Promise((resolve) => {
            raf(() => {
                el.classList.add(className);
                const ms = typeof duration === 'number' ? duration : durationMs(duration);
                const done = () => {
                    el.classList.remove(className);
                    el.removeEventListener('animationend', done);
                    resolve();
                };
                el.addEventListener('animationend', done);
                // Belt and braces: if the class carries no animation,
                // animationend never fires and the class would stick forever.
                setTimeout(done, ms + 50);
            });
        });
    }

    /**
     * Count a number from `from` to `to`, writing through `onValue`.
     *
     * This is the one piece of motion the game genuinely depends on: coins
     * arriving should read as arriving. Under reduced motion the final value
     * is written once, immediately.
     *
     * Returns a cancel function. Cancelling leaves the value wherever it got
     * to, so a cancelled count followed by a new one continues from there
     * rather than snapping backwards.
     */
    function countTo(from, to, onValue, opts = {}) {
        const { duration = 'moment', easing: easingToken = 'move' } = opts;
        const start = Number(from) || 0;
        const end = Number(to) || 0;

        if (reduced || start === end) {
            onValue(end, true);
            return () => {};
        }

        const ms = typeof duration === 'number' ? duration : durationMs(duration);
        if (ms <= 0) {
            onValue(end, true);
            return () => {};
        }

        const ease = easingToken === 'gain'
            ? (t) => 1 - Math.pow(1 - t, 3)   // decelerate; arrivals should land softly
            : (t) => t < 0.5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2;

        const began = now();
        let cancelled = false;

        function step() {
            if (cancelled) return;
            const elapsed = now() - began;
            const t = Math.min(1, elapsed / ms);
            const value = start + (end - start) * ease(t);
            if (t >= 1) {
                onValue(end, true);
                return;
            }
            onValue(value, false);
            raf(step);
        }
        raf(step);

        return () => { cancelled = true; };
    }

    return {
        /** True when the viewer has asked for reduced motion. Live. */
        get reduced() { return reduced; },
        durationMs,
        easing,
        animate,
        pulse,
        countTo,
        /** Call after changing data-theme; a theme may retune durations. */
        invalidateTokens() { tokenCache = new Map(); },
    };
}
