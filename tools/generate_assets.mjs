#!/usr/bin/env node
/**
 * generate_assets.mjs — deterministic vector art for every registry slot.
 *
 *   node tools/generate_assets.mjs
 *
 * Writes SVG files into public/assets/. The output is a function of this
 * file alone (no randomness, no timestamps), so the art is reviewable in a
 * diff and regenerating is always safe.
 *
 * Style system:
 *  - UI icons: 24-box line glyphs, 2px round strokes, one light neutral ink
 *    that reads on all four dark themes, one warm accent.
 *  - Currencies: filled marks with their own identity colours; the two star
 *    currencies are deliberately different silhouettes (4-point hollow vs
 *    8-point solid) so they cannot be confused at 20px.
 *  - Families: solid WHITE silhouettes on transparency. They ship as masks —
 *    core/assets.js tints them with the per-family CSS token, so shape, not
 *    colour, carries the identity.
 *  - Moments: horizontal sprite strips, N frames of identical width, drawn
 *    frame-by-frame from easing math. They play once via steps() and never
 *    loop.
 */

import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const OUT = join(HERE, '..', 'public', 'assets');
mkdirSync(OUT, { recursive: true });

const INK = '#dfe3f0';     // light neutral, legible on every theme surface
const INK_SOFT = '#9aa0b4';
const GOLD = '#e8b64c';
const VIOLET = '#b5abfc';
const WARM = '#e0b455';
const WHITE = '#ffffff';

const files = [];

function svg(name, w, h, body) {
    const doc = `<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}" viewBox="0 0 ${w} ${h}">\n${body}\n</svg>\n`;
    writeFileSync(join(OUT, name), doc);
    files.push(name);
}

/** Regular polygon / star path around (cx,cy). */
function starPath(cx, cy, points, outer, inner, rotate = -90) {
    const step = Math.PI / points;
    const start = (rotate * Math.PI) / 180;
    let d = '';
    for (let i = 0; i < points * 2; i++) {
        const r = i % 2 === 0 ? outer : inner;
        const a = start + i * step;
        const x = (cx + r * Math.cos(a)).toFixed(2);
        const y = (cy + r * Math.sin(a)).toFixed(2);
        d += (i === 0 ? 'M' : 'L') + x + ' ' + y;
    }
    return d + 'Z';
}

/**
 * Standard line-glyph stroke. Takes the colour and width as values rather
 * than letting callers append raw attributes — appending produced a second
 * `stroke=` on the same element, which browsers resolve last-wins but which
 * is not well-formed XML.
 */
const line = (color = INK, width = 2) =>
    `stroke="${color}" stroke-width="${width}" stroke-linecap="round" stroke-linejoin="round" fill="none"`;

/* ------------------------------------------------------------------ *
 * navigation — 24-box line glyphs
 * ------------------------------------------------------------------ */

svg('nav-home.svg', 24, 24, `
  <path d="M4 11.5 12 4.5l8 7" ${line()}/>
  <path d="M6.5 10.5V19a1 1 0 0 0 1 1h9a1 1 0 0 0 1-1v-8.5" ${line()}/>
  <path d="M10 20v-5h4v5" ${line(GOLD)}/>
`);

svg('nav-seasons.svg', 24, 24, `
  <path d="M8 4h8v5a4 4 0 0 1-8 0Z" ${line()}/>
  <path d="M8 5H5.5a0 0 0 0 0 0 0c0 2.6 1 4.2 2.7 4.7M16 5h2.5c0 2.6-1 4.2-2.7 4.7" ${line()}/>
  <path d="M12 13v3.5M9 20h6M12 16.5c-1.2 0-2 .9-2 3.5h4c0-2.6-.8-3.5-2-3.5Z" ${line(GOLD)}/>
`);

svg('nav-ranks.svg', 24, 24, `
  <path d="M4 20v-6h4.5v6" ${line()}/>
  <path d="M9.75 20V6h4.5v14" ${line(GOLD)}/>
  <path d="M15.5 20v-9H20v9" ${line()}/>
  <path d="M3 20h18" ${line()}/>
`);

svg('nav-chat.svg', 24, 24, `
  <path d="M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5v7a2.5 2.5 0 0 1-2.5 2.5H10l-4.4 3.6c-.5.4-1.1 0-1.1-.6V16A2.5 2.5 0 0 1 4 13.5Z" ${line()}/>
  <circle cx="9" cy="10" r="1.1" fill="${GOLD}"/>
  <circle cx="12.5" cy="10" r="1.1" fill="${GOLD}"/>
  <circle cx="16" cy="10" r="1.1" fill="${GOLD}"/>
`);

svg('nav-shop.svg', 24, 24, `
  <path d="M7 4h10l4 5-9 11L3 9Z" ${line()}/>
  <path d="M3 9h18M9.5 9 12 19.5 14.5 9M7 4l2.5 5L12 4l2.5 5L17 4" ${line(INK_SOFT, 1.5)}/>
`);

svg('nav-staff.svg', 24, 24, `
  <path d="M12 3.5c2.6 1.4 5 2 7.5 2.2 0 6.6-2.4 11.7-7.5 14.8C6.9 17.4 4.5 12.3 4.5 5.7 7 5.5 9.4 4.9 12 3.5Z" ${line()}/>
  <circle cx="12" cy="10.2" r="1.9" ${line(GOLD)}/>
  <path d="M12 12.1v3.2" ${line(GOLD)}/>
`);

svg('nav-family.svg', 24, 24, `
  <circle cx="12" cy="12" r="2" fill="${GOLD}"/>
  ${[0, 1, 2, 3, 4, 5, 6].map(i => {
        const a = -Math.PI / 2 + (i * 2 * Math.PI) / 7;
        const x = (12 + 7.5 * Math.cos(a)).toFixed(2);
        const y = (12 + 7.5 * Math.sin(a)).toFixed(2);
        return `<circle cx="${x}" cy="${y}" r="1.6" fill="${INK}"/>`
            + `<path d="M12 12 ${x} ${y}" stroke="${INK_SOFT}" stroke-width="1" opacity="0.5"/>`;
    }).join('\n  ')}
`);

svg('bell.svg', 24, 24, `
  <path d="M12 4a5.5 5.5 0 0 1 5.5 5.5c0 3.2.8 4.7 1.8 5.9.4.5.1 1.1-.5 1.1H5.2c-.6 0-.9-.6-.5-1.1 1-1.2 1.8-2.7 1.8-5.9A5.5 5.5 0 0 1 12 4Z" ${line()}/>
  <path d="M10 19.5a2 2 0 0 0 4 0" ${line(GOLD)}/>
  <path d="M12 2.5V4" ${line()}/>
`);

/* ------------------------------------------------------------------ *
 * currencies — filled marks
 * ------------------------------------------------------------------ */

svg('coin.svg', 20, 20, `
  <circle cx="10" cy="10" r="8.4" fill="${GOLD}"/>
  <circle cx="10" cy="10" r="8.4" fill="none" stroke="#8a6420" stroke-width="1.2"/>
  <circle cx="10" cy="10" r="5.9" fill="none" stroke="#8a6420" stroke-width="0.9" opacity="0.8"/>
  <path d="${starPath(10, 10, 5, 3.4, 1.55)}" fill="#7c581b"/>
  <path d="M4.4 6.5a8.4 8.4 0 0 1 4-3.5" stroke="#ffe9b0" stroke-width="1.6" stroke-linecap="round" fill="none"/>
`);

svg('star-season.svg', 20, 20, `
  <path d="${starPath(10, 10, 4, 8.6, 2.9)}" fill="${VIOLET}"/>
  <path d="${starPath(10, 10, 4, 8.6, 2.9)}" fill="none" stroke="#6f63c4" stroke-width="1"/>
  <circle cx="10" cy="10" r="2" fill="#2a2640"/>
`);

svg('star-global.svg', 20, 20, `
  <path d="${starPath(10, 10, 8, 8.8, 4.4)}" fill="${WARM}"/>
  <path d="${starPath(10, 10, 8, 8.8, 4.4)}" fill="none" stroke="#9c7626" stroke-width="0.9"/>
  <circle cx="10" cy="10" r="2.6" fill="#fff1cf"/>
`);

svg('sigil.svg', 20, 20, `
  <path d="M10 1.8 17.6 10 10 18.2 2.4 10Z" fill="${VIOLET}"/>
  <path d="M10 1.8 17.6 10 10 18.2 2.4 10Z" fill="none" stroke="#6f63c4" stroke-width="1.1"/>
  <path d="M10 5.6 13.9 10 10 14.4 6.1 10Z" fill="#39325c"/>
  <path d="M10 7.6v4.8M7.9 10h4.2" stroke="${VIOLET}" stroke-width="1.1" stroke-linecap="round"/>
`);

/* ------------------------------------------------------------------ *
 * sigil families — solid white silhouettes, 28-box, shipped as masks
 * ------------------------------------------------------------------ */

// Goliath (yield): a colossal horned helm.
svg('family-yield.svg', 28, 28, `
  <path fill="${WHITE}" d="M6 12c0-4.5 2.6-7.5 8-7.5S22 7.5 22 12v6.5c0 1-.7 2-1.8 2.2l-2.6.6-.8 2.7c-.2.8-1 .8-1.6.8h-2.4c-.6 0-1.4 0-1.6-.8l-.8-2.7-2.6-.6C6.7 20.5 6 19.5 6 18.5Zm5 1.6a1.8 1.8 0 1 0 0 3.6 1.8 1.8 0 0 0 0-3.6Zm6 0a1.8 1.8 0 1 0 0 3.6 1.8 1.8 0 0 0 0-3.6Z"/>
  <path fill="${WHITE}" d="M6.4 10.5C4 9.6 2.6 7.6 2.5 4.4c2.9.2 5 1.3 6.2 3.4Zm15.2 0c2.4-.9 3.8-2.9 3.9-6.1-2.9.2-5 1.3-6.2 3.4Z"/>
`);

// Anak (time): a tall long-lived hourglass.
svg('family-time.svg', 28, 28, `
  <path fill="${WHITE}" d="M7 3h14v2.2c0 3.4-2.2 5.9-4.6 8.8 2.4 2.9 4.6 5.4 4.6 8.8V25H7v-2.2c0-3.4 2.2-5.9 4.6-8.8C9.2 11.1 7 8.6 7 5.2Zm2.6 2.4c.3 2.5 2 4.5 4.4 7.2 2.4-2.7 4.1-4.7 4.4-7.2Zm4.4 10.4c-2.2 2.5-3.9 4.4-4.3 6.6h8.6c-.4-2.2-2.1-4.1-4.3-6.6Z"/>
`);

// Michael (ward): a winged kite shield.
svg('family-ward.svg', 28, 28, `
  <path fill="${WHITE}" d="M14 3.2c2.8 1.5 5.4 2.2 8.1 2.4 0 7.2-2.6 12.7-8.1 16-5.5-3.3-8.1-8.8-8.1-16 2.7-.2 5.3-.9 8.1-2.4Zm0 3.1c-2 1-3.9 1.6-5.7 1.9.2 5.2 2 9.2 5.7 11.9 3.7-2.7 5.5-6.7 5.7-11.9-1.8-.3-3.7-.9-5.7-1.9Z"/>
  <path fill="${WHITE}" d="M4.4 6.8C2.9 8.5 2 10.6 2 13.2c1.6-.7 2.9-1.9 3.8-3.5-.6-1-1-1.9-1.4-2.9Zm19.2 0c1.5 1.7 2.4 3.8 2.4 6.4-1.6-.7-2.9-1.9-3.8-3.5.6-1 1-1.9 1.4-2.9ZM14 9.1l3 3.4-3 6.2-3-6.2Z"/>
`);

// Valefor (larceny): a hooked dagger over a snatching claw-hand.
// A mask has no "cut out" operator available, so the silhouette is built
// from solid paths only — the negative space is left, never subtracted.
svg('family-larceny.svg', 28, 28, `
  <path fill="${WHITE}" d="M16.4 2.6c3.7.4 6.6 1.7 8.7 3.8-1.3 1.4-2.8 2.3-4.6 2.7l-6.9 6.9-3.9-3.9 6.9-6.9c.4-1.8 1.3-3.3 2.7-4.6Z"/>
  <path fill="${WHITE}" d="m8.6 13.7 3.9 3.9-1.7 1.7-3.9-3.9z"/>
  <path fill="${WHITE}" d="M4.2 18.2a2.6 2.6 0 0 1 3.7 0l3.4 3.4a2.6 2.6 0 0 1-3.7 3.7l-3.4-3.4a2.6 2.6 0 0 1 0-3.7Zm1.8 1.8a.9.9 0 0 0 0 1.3l2.8 2.8a.9.9 0 0 0 1.3-1.3l-2.8-2.8a.9.9 0 0 0-1.3 0Z"/>
  <path fill="${WHITE}" d="M2.4 12.3c1.9-1 3.9-.9 6 .3l-1.3 2.2c-1.4-.8-2.6-.9-3.7-.3Z"/>
`);

// Mammon (market): a balance scale tipped by a coin.
svg('family-market.svg', 28, 28, `
  <path fill="${WHITE}" d="M13 4h2v3.1l6.3 1.4.4-1 1.9.8-3 7.2c1.9 4-1 5.5-3.2 5.5s-5.1-1.5-3.2-5.5l1.9-4.6L15 9.2V22h4v2H9v-2h4V9.2l-2.6 1.9 2.1 5.1c1.9 4-1 5.5-3.2 5.5S4.2 20.2 6.1 16.2L3.4 9.9l1.9-.8.6 1.4L13 7.1Zm-5.7 9.5-1.7 4.1h3.4Zm11.4 1.6-1.7 4.1h3.4Z"/>
`);

// Azazel (sight): a lidless watching eye.
svg('family-sight.svg', 28, 28, `
  <path fill="${WHITE}" d="M14 7c5.9 0 10.3 3.1 12.5 7-2.2 3.9-6.6 7-12.5 7S3.7 17.9 1.5 14C3.7 10.1 8.1 7 14 7Zm0 2.4c-4.5 0-8 2.1-9.9 4.6 1.9 2.5 5.4 4.6 9.9 4.6s8-2.1 9.9-4.6c-1.9-2.5-5.4-4.6-9.9-4.6Z"/>
  <path fill="${WHITE}" d="M14 10.4a3.6 3.6 0 1 1 0 7.2 3.6 3.6 0 0 1 0-7.2Zm1.2 1.6a1.1 1.1 0 1 0 0 2.2 1.1 1.1 0 0 0 0-2.2Z"/>
`);

// Legion (wild): five shards converging — "for we are many".
svg('family-wild.svg', 28, 28, `
  ${[0, 1, 2, 3, 4].map(i => {
        const a = -Math.PI / 2 + (i * 2 * Math.PI) / 5;
        const x1 = 14 + 11 * Math.cos(a);
        const y1 = 14 + 11 * Math.sin(a);
        const x2 = 14 + 4.2 * Math.cos(a);
        const y2 = 14 + 4.2 * Math.sin(a);
        const p = Math.PI / 14;
        const xa = 14 + 8.6 * Math.cos(a - p);
        const ya = 14 + 8.6 * Math.sin(a - p);
        const xb = 14 + 8.6 * Math.cos(a + p);
        const yb = 14 + 8.6 * Math.sin(a + p);
        return `<path fill="${WHITE}" d="M${x1.toFixed(2)} ${y1.toFixed(2)} L${xa.toFixed(2)} ${ya.toFixed(2)} L${x2.toFixed(2)} ${y2.toFixed(2)} L${xb.toFixed(2)} ${yb.toFixed(2)}Z"/>`;
    }).join('\n  ')}
  <circle cx="14" cy="14" r="2.4" fill="${WHITE}"/>
`);

/* ------------------------------------------------------------------ *
 * states — 24-box line glyphs
 * ------------------------------------------------------------------ */

svg('state-idle.svg', 24, 24, `
  <path d="M7 3.5h10M7 20.5h10M8 3.5v2.3c0 2.6 1.7 4.4 4 6.2 2.3-1.8 4-3.6 4-6.2V3.5M8 20.5v-2.3c0-2.6 1.7-4.4 4-6.2 2.3 1.8 4 3.6 4 6.2v2.3" ${line()}/>
  <path d="M12 15.5v3" ${line(GOLD)}/>
`);

svg('state-blackout.svg', 24, 24, `
  <circle cx="12" cy="12" r="7.5" ${line()}/>
  <path d="M9.5 5.2a7.5 7.5 0 0 0 8.8 11.9A7.5 7.5 0 0 1 9.5 5.2Z" fill="${INK}"/>
`);

svg('state-frozen.svg', 24, 24, `
  ${[0, 1, 2].map(i => {
        const a = (i * Math.PI) / 3;
        const x = (8.5 * Math.cos(a)).toFixed(2);
        const y = (8.5 * Math.sin(a)).toFixed(2);
        return `<path d="M${(12 - +x).toFixed(2)} ${(12 - +y).toFixed(2)} L${(12 + +x).toFixed(2)} ${(12 + +y).toFixed(2)}" ${line()}/>`;
    }).join('\n  ')}
  <circle cx="12" cy="12" r="2.2" ${line(GOLD)}/>
`);

/* ------------------------------------------------------------------ *
 * moments — sprite strips (N frames, identical width, play once)
 * ------------------------------------------------------------------ */

const easeOut = (t) => 1 - Math.pow(1 - t, 3);
const easeIn = (t) => t * t * t;

function strip(name, frameW, frameH, frames, draw) {
    let body = '';
    for (let f = 0; f < frames; f++) {
        const t = frames === 1 ? 1 : f / (frames - 1);
        body += `<g transform="translate(${f * frameW} 0)">\n${draw(t, f)}\n</g>\n`;
    }
    svg(name, frameW * frames, frameH, body);
}

// payout-burst: a gold ring and coin sparks expanding and fading.
strip('payout-burst.svg', 96, 96, 12, (t) => {
    const r = 8 + easeOut(t) * 34;
    const fade = (1 - easeIn(t)).toFixed(3);
    const sparks = [0, 1, 2, 3, 4, 5, 6, 7].map(i => {
        const a = (i * Math.PI) / 4 + 0.4;
        const d = 10 + easeOut(t) * 36;
        const x = (48 + d * Math.cos(a)).toFixed(1);
        const y = (48 + d * Math.sin(a)).toFixed(1);
        const sr = (2.6 * (1 - t * 0.6)).toFixed(2);
        return `<circle cx="${x}" cy="${y}" r="${sr}" fill="${GOLD}" opacity="${fade}"/>`;
    }).join('');
    return `<circle cx="48" cy="48" r="${r.toFixed(1)}" fill="none" stroke="${GOLD}" stroke-width="${(3 * (1 - t) + 0.6).toFixed(2)}" opacity="${fade}"/>${sparks}`;
});

// sigil-drop: a violet rhombus falling with a sparkle trail, landing flash.
strip('sigil-drop.svg', 128, 128, 16, (t) => {
    const y = 12 + easeIn(Math.min(1, t * 1.15)) * 74;
    const landed = t > 0.82;
    const flash = landed ? ((t - 0.82) / 0.18) : 0;
    const trail = [1, 2, 3].map(i => {
        const ty = y - i * 14;
        if (ty < 6) return '';
        return `<path d="M64 ${(ty - 6).toFixed(1)} L70 ${ty.toFixed(1)} 64 ${(ty + 6).toFixed(1)} 58 ${ty.toFixed(1)}Z" fill="${VIOLET}" opacity="${(0.35 / i).toFixed(2)}"/>`;
    }).join('');
    const ringO = landed ? (1 - flash).toFixed(2) : 0;
    const ringR = (10 + flash * 26).toFixed(1);
    return `${trail}
<path d="M64 ${(y - 11).toFixed(1)} L75 ${y.toFixed(1)} 64 ${(y + 11).toFixed(1)} 53 ${y.toFixed(1)}Z" fill="${VIOLET}"/>
<path d="M64 ${(y - 5).toFixed(1)} L69.5 ${y.toFixed(1)} 64 ${(y + 5).toFixed(1)} 58.5 ${y.toFixed(1)}Z" fill="#39325c"/>
<circle cx="64" cy="98" r="${ringR}" fill="none" stroke="${VIOLET}" stroke-width="2" opacity="${ringO}"/>`;
});

// theft-strike: a dagger slash arc sweeping through, shards scattering.
strip('theft-strike.svg', 128, 128, 14, (t) => {
    const sweep = easeOut(Math.min(1, t * 1.25));
    const a0 = -2.4 + sweep * 2.6;
    const x1 = 64 + 52 * Math.cos(a0);
    const y1 = 64 + 52 * Math.sin(a0);
    const x2 = 64 + 52 * Math.cos(a0 - 1.1);
    const y2 = 64 + 52 * Math.sin(a0 - 1.1);
    const fade = (1 - easeIn(t)).toFixed(2);
    const shards = t > 0.4 ? [0, 1, 2, 3].map(i => {
        const st = (t - 0.4) / 0.6;
        const a = -1.2 + i * 0.55;
        const d = 14 + easeOut(st) * 40;
        const x = (64 + d * Math.cos(a)).toFixed(1);
        const y = (64 + d * Math.sin(a)).toFixed(1);
        return `<path d="M${x} ${y} l5 -2 -2 5Z" fill="#d98d8d" opacity="${(1 - st).toFixed(2)}"/>`;
    }).join('') : '';
    return `<path d="M${x2.toFixed(1)} ${y2.toFixed(1)} A52 52 0 0 1 ${x1.toFixed(1)} ${y1.toFixed(1)}" fill="none" stroke="#d98d8d" stroke-width="${(5 * (1 - t) + 1).toFixed(1)}" stroke-linecap="round" opacity="${fade}"/>
<path d="M${x1.toFixed(1)} ${y1.toFixed(1)} l-7 -14 14 7Z" fill="#d98d8d" opacity="${fade}"/>${shards}`;
});

// freeze-lock: ice crystals growing over the centre, frost ring setting.
strip('freeze-lock.svg', 96, 96, 10, (t) => {
    const grow = easeOut(t);
    const spikes = [0, 1, 2, 3, 4, 5].map(i => {
        const a = (i * Math.PI) / 3;
        const len = 8 + grow * 26;
        const x = (48 + len * Math.cos(a)).toFixed(1);
        const y = (48 + len * Math.sin(a)).toFixed(1);
        const bx = (48 + 6 * Math.cos(a + Math.PI / 2)).toFixed(1);
        const by = (48 + 6 * Math.sin(a + Math.PI / 2)).toFixed(1);
        const cx2 = (48 + 6 * Math.cos(a - Math.PI / 2)).toFixed(1);
        const cy2 = (48 + 6 * Math.sin(a - Math.PI / 2)).toFixed(1);
        return `<path d="M${bx} ${by} L${x} ${y} L${cx2} ${cy2}Z" fill="#8fc7e8" opacity="0.9"/>`;
    }).join('');
    return `${spikes}
<circle cx="48" cy="48" r="${(10 + grow * 20).toFixed(1)}" fill="none" stroke="#8fc7e8" stroke-width="1.4" opacity="${(0.7 * (1 - t) + 0.3).toFixed(2)}"/>
<circle cx="48" cy="48" r="7" fill="#eaf6ff" opacity="${(0.4 + grow * 0.6).toFixed(2)}"/>`;
});

// lockin-seal: the ceremony — a seal stamps down, a shockwave ring settles.
// The one moment that marks an irreversible act, so it is the largest strip
// and the only one that fills most of its frame.
strip('lockin-seal.svg', 192, 192, 24, (t) => {
    const drop = Math.min(1, t * 2);
    const y = 46 + easeIn(drop) * 74;          // handle base travels to the anvil
    const impact = t > 0.5;
    const it = impact ? (t - 0.5) / 0.5 : 0;
    const ring = impact
        ? `<circle cx="96" cy="128" r="${(26 + easeOut(it) * 62).toFixed(1)}" fill="none" stroke="${WARM}" stroke-width="${(7 * (1 - it) + 1).toFixed(1)}" opacity="${(1 - it).toFixed(2)}"/>`
        : '';
    const star = impact
        ? `<path d="${starPath(96, 128, 8, 34 * (0.65 + 0.35 * (1 - it)), 15 * (0.65 + 0.35 * (1 - it)))}" fill="${WARM}" opacity="${(0.95 - it * 0.45).toFixed(2)}"/>`
        : '';
    return `<rect x="64" y="${(y - 58).toFixed(1)}" width="64" height="44" rx="7" fill="${WARM}"/>
<rect x="80" y="${(y - 92).toFixed(1)}" width="32" height="38" rx="6" fill="${WARM}" opacity="0.85"/>
<ellipse cx="96" cy="${y.toFixed(1)}" rx="${(38 + it * 8).toFixed(1)}" ry="12" fill="#9c7626"/>
${ring}${star}`;
});

console.log(`wrote ${files.length} assets to public/assets/:`);
for (const f of files) console.log('  ' + f);
