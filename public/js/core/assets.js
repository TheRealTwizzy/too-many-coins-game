/**
 * core/assets.js — art registry and sprite player
 *
 * Every icon and animation in the client is referenced by a *semantic name*
 * ('coin', 'family-ward', 'payout-burst') rather than by a glyph or a file
 * path. Today those names resolve to emoji and CSS placeholders. When real art
 * arrives, only `registry` below changes — no component, screen or stylesheet
 * has to be touched, because none of them ever knew what a 'coin' looked like.
 *
 * That indirection is the entire point of this file. It is cheap now and
 * expensive to retrofit later, which is why it exists before the art does.
 *
 * ---- what a slot looks like ----
 *
 *   'coin': {
 *       placeholder: '\u{1FA99}',     // shown until art lands
 *       art: null,                    // fill this in and the swap is done
 *   }
 *
 * A finished slot looks like one of:
 *
 *   art: { kind: 'image', src: '/assets/coin.png', src2x: '/assets/coin@2x.png', w: 24, h: 24 }
 *   art: { kind: 'sprite', src: '/assets/coin-burst.png', w: 48, h: 48, frames: 12, fps: 24, loop: false }
 *
 * ---- sprite sheets ----
 *
 * Sprites are horizontal strips: N frames of identical width laid left to
 * right in one image. Playback is a CSS steps() animation over
 * background-position, so no JavaScript runs per frame and the compositor
 * owns it. See css/assets.css.
 *
 * ---- content security policy ----
 *
 * The app serves `img-src 'self' data:`. Art must therefore be committed into
 * this repo and served from our own origin, or inlined as a data: URI. A CDN
 * or an image host URL will be blocked by the browser, silently, with an
 * empty box where the icon should be.
 */

const SPRITE_CLASS = 'sprite';

/* ------------------------------------------------------------------ *
 * registry
 *
 * Every art slot the client needs. Kept in one list deliberately: it doubles
 * as the specification handed to whoever is producing the art, and a slot
 * with `art: null` is a visible to-do rather than a thing someone has to go
 * hunting for.
 * ------------------------------------------------------------------ */

export const registry = {
    // --- navigation ---
    'nav-home':     { placeholder: '★',      art: null },
    'nav-seasons':  { placeholder: '\u{1F3C6}',   art: null },
    'nav-ranks':    { placeholder: '\u{1F3C5}',   art: null },
    'nav-chat':     { placeholder: '\u{1F4AC}',   art: null },
    'nav-shop':     { placeholder: '\u{1F48E}',   art: null },
    'nav-staff':    { placeholder: '\u{1F6E1}',   art: null },   // staff/admin rail entry (staff viewers only)

    // --- currencies ---
    'coin':         { placeholder: '\u{1FA99}',   art: null },
    'star-season':  { placeholder: '✦',      art: null },
    'star-global':  { placeholder: '✴',      art: null },
    'sigil':        { placeholder: '◇',      art: null },

    // --- sigil families ---
    // These carry identity a player learns once, so they are the slots where
    // custom art pays off most and where the placeholders read worst.
    'family-yield':   { placeholder: '●', tint: 'var(--family-yield)',   art: null },
    'family-time':    { placeholder: '◔', tint: 'var(--family-time)',    art: null },
    'family-ward':    { placeholder: '◈', tint: 'var(--family-ward)',    art: null },
    'family-larceny': { placeholder: '◆', tint: 'var(--family-larceny)', art: null },
    'family-market':  { placeholder: '▲', tint: 'var(--family-market)',  art: null },
    'family-sight':   { placeholder: '◎', tint: 'var(--family-sight)',   art: null },
    'family-wild':    { placeholder: '✳', tint: 'var(--family-wild)',    art: null },

    // --- moments ---
    // Sprite slots. Each plays once in response to something happening; none
    // of them loop, because a looping animation in a game about watching
    // numbers becomes noise within a minute.
    'payout-burst': { placeholder: null, art: null, wants: 'sprite' },
    'sigil-drop':   { placeholder: null, art: null, wants: 'sprite' },
    'theft-strike': { placeholder: null, art: null, wants: 'sprite' },
    'freeze-lock':  { placeholder: null, art: null, wants: 'sprite' },
    'lockin-seal':  { placeholder: null, art: null, wants: 'sprite' },

    // --- ui chrome ---
    'bell':           { placeholder: '\u{1F514}', art: null },

    // --- states ---
    'state-idle':     { placeholder: '⏳', art: null },
    'state-blackout': { placeholder: '⚠', art: null },
    'state-frozen':   { placeholder: '❄', art: null },
};

/* ------------------------------------------------------------------ *
 * factory
 * ------------------------------------------------------------------ */

export function createAssets(options = {}) {
    const {
        h,
        motion = null,
        doc = typeof document !== 'undefined' ? document : null,
        slots = registry,
    } = options;

    if (typeof h !== 'function') {
        throw new TypeError('createAssets requires the h() from core/render.js');
    }

    const preloaded = new Map(); // src -> Promise

    function slot(name) {
        const found = slots[name];
        if (!found && typeof console !== 'undefined') {
            console.warn(`[assets] unknown slot "${name}"`);
        }
        return found || null;
    }

    /** True once a slot has real art behind it. Useful for progressive rollout. */
    function hasArt(name) {
        const s = slot(name);
        return Boolean(s && s.art);
    }

    /**
     * Build the background-image value for a slot, preferring 2x where given.
     *
     * image-set lets the browser pick per display rather than us guessing from
     * devicePixelRatio, which is wrong the moment a window moves between a
     * laptop screen and an external monitor.
     */
    function backgroundFor(art) {
        if (!art.src2x) return `url("${art.src}")`;
        return `image-set(url("${art.src}") 1x, url("${art.src2x}") 2x)`;
    }

    /**
     * Render an icon.
     *
     * Returns a vnode either way, so callers are identical before and after
     * the art swap:
     *
     *   assets.icon('coin')
     *   assets.icon('family-ward', { size: 20 })
     */
    function icon(name, opts = {}) {
        const { size = null, class: extraClass = '', label = null } = opts;
        const s = slot(name);

        const aria = label
            ? { role: 'img', 'aria-label': label }
            : { 'aria-hidden': 'true' };

        if (!s || !s.art) {
            // Placeholder. Tinted where the slot carries meaning through
            // colour, so families stay distinguishable before art exists.
            const style = {};
            if (size) style.fontSize = `${size}px`;
            if (s && s.tint) style.color = s.tint;
            // null rather than '' so an absent placeholder — a sprite-only
            // slot, or an unknown name — emits no text node at all instead of
            // an empty one the reconciler would then carry around forever.
            return h('span', {
                class: ('icon icon-placeholder ' + extraClass).trim(),
                style,
                ...aria,
            }, s ? (s.placeholder || null) : null);
        }

        const art = s.art;
        const w = size || art.w;
        const scale = art.w ? w / art.w : 1;
        const sizing = art.kind === 'sprite'
            ? `${art.w * art.frames * scale}px 100%`
            : '100% 100%';

        const style = {
            width: `${w}px`,
            height: `${Math.round((art.h || art.w) * scale)}px`,
            ...(art.pixelated ? { imageRendering: 'pixelated' } : {}),
        };

        if (art.tintable) {
            // Masked rather than drawn, so one flat silhouette serves every
            // family and picks up its colour token. Supplying seven separately
            // coloured PNGs would work too, but then the art and the palette
            // can drift apart, and a theme change cannot reach them.
            style.backgroundColor = opts.tint || (s.tint ?? 'currentColor');
            style.maskImage = backgroundFor(art);
            style.webkitMaskImage = backgroundFor(art);
            style.maskSize = sizing;
            style.webkitMaskSize = sizing;
            style.maskRepeat = 'no-repeat';
            style.webkitMaskRepeat = 'no-repeat';
        } else {
            style.backgroundImage = backgroundFor(art);
            style.backgroundSize = sizing;
        }

        return h('span', {
            class: ('icon ' + extraClass).trim(),
            style,
            ...aria,
        });
    }

    /**
     * Preload art so the first play does not flash an empty frame.
     *
     * Resolves even on failure: a missing sprite should degrade to no
     * animation, never to a rejected promise that takes a screen down with it.
     */
    function preload(names) {
        if (!doc) return Promise.resolve();

        const list = (Array.isArray(names) ? names : [names])
            .map(slot)
            .filter(s => s && s.art && s.art.src)
            .map(s => s.art.src);

        return Promise.all(list.map(src => {
            if (preloaded.has(src)) return preloaded.get(src);
            const p = new Promise(resolve => {
                const img = new Image();
                img.onload = () => resolve(true);
                img.onerror = () => {
                    console.warn(`[assets] failed to preload ${src}`);
                    resolve(false);
                };
                img.src = src;
            });
            preloaded.set(src, p);
            return p;
        })).then(() => undefined);
    }

    /**
     * Play a sprite once on `el`.
     *
     * Resolves when the animation finishes. Resolves immediately, having done
     * nothing visible, when the slot has no art yet or the viewer has asked
     * for reduced motion — so callers can `await` it unconditionally and
     * screens work identically before and after art lands.
     */
    function playSprite(el, name, opts = {}) {
        const s = slot(name);
        if (!el || !s || !s.art || s.art.kind !== 'sprite') return Promise.resolve();

        // Reduced motion gets the rest frame, not a flicker. The outcome the
        // sprite was announcing is still conveyed by the state change around it.
        if (motion && motion.reduced) {
            el.style.backgroundPosition = '0 0';
            return Promise.resolve();
        }

        const art = s.art;
        const { scale = 1, onDone = null } = opts;
        const w = art.w * scale;
        const h_ = (art.h || art.w) * scale;
        const durationMs = Math.round((art.frames / (art.fps || 24)) * 1000);

        el.style.setProperty('--sprite-w', `${w}px`);
        el.style.setProperty('--sprite-h', `${h_}px`);
        el.style.setProperty('--sprite-frames', String(art.frames));
        el.style.setProperty('--sprite-duration', `${durationMs}ms`);
        el.style.setProperty('--sprite-image', backgroundFor(art));
        if (art.pixelated) el.style.imageRendering = 'pixelated';

        // Remove then re-add on the next frame, so replaying a sprite that is
        // already playing restarts it. Adding a class that is already present
        // does nothing at all, which reads as a dropped animation.
        el.classList.remove(SPRITE_CLASS);

        return new Promise(resolve => {
            requestAnimationFrame(() => {
                el.classList.add(SPRITE_CLASS);
                const finish = () => {
                    el.classList.remove(SPRITE_CLASS);
                    el.removeEventListener('animationend', finish);
                    if (onDone) onDone();
                    resolve();
                };
                el.addEventListener('animationend', finish);
                // If the stylesheet is missing the animation, animationend
                // never fires and the class would stick forever.
                setTimeout(finish, durationMs + 80);
            });
        });
    }

    return { icon, playSprite, preload, hasArt, registry: slots };
}
