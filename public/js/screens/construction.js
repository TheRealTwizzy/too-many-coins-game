/**
 * construction.js — the full-page maintenance gate.
 *
 * Not a registered screen on purpose: shell() short-circuits to this view
 * when game_state reports MAINTENANCE_LOCKDOWN, so the player's current
 * screen id is untouched and they land back exactly where they were when the
 * gate lifts. Staff bypass the gate entirely (server- and client-side); the
 * one escape hatch here is the staff sign-in button, which routes to the
 * normal auth screen.
 */

import { CURRENT_VERSION, CURRENT_WORK, PROJECTED, PATCH_NOTES } from '../patch-notes.js';

export function constructionShell(h, { onStaffSignIn } = {}) {
    return h('div', { id: 'shell', class: 'construction-shell' },
        h('main', { class: 'construction' },
            h('div', { class: 'construction-badge' }, '⚠'),
            h('h1', null, 'Under construction'),
            h('p', { class: 'construction-lede' },
                'Too Many Coins is being rebuilt while you read this. The world keeps ticking; play is paused until the work lands.'),
            h('p', { class: 'construction-version' }, `Current version ${CURRENT_VERSION}`),

            h('section', { class: 'construction-block' },
                h('h2', null, 'Being built right now'),
                h('ul', null, ...CURRENT_WORK.map((item) => h('li', { key: item }, item))),
            ),

            h('section', { class: 'construction-block' },
                h('h2', null, 'Projected'),
                h('ul', null, ...PROJECTED.map((row) =>
                    h('li', { key: row.when },
                        h('strong', null, row.when + ' — '),
                        row.what,
                    ))),
            ),

            h('section', { class: 'construction-block' },
                h('h2', null, 'Patch history'),
                ...PATCH_NOTES.map((entry) =>
                    h('article', { class: 'construction-patch', key: entry.version },
                        h('h3', null, `${entry.version} — ${entry.title}`),
                        h('ul', null, ...entry.notes.map((note, i) => h('li', { key: String(i) }, note))),
                    )),
            ),

            h('footer', { class: 'construction-foot' },
                h('button', {
                    class: 'btn btn-ghost btn-sm',
                    onClick: () => { if (onStaffSignIn) onStaffSignIn(); },
                }, 'Staff sign-in'),
            ),
        ),
    );
}
