/**
 * screens/ui.js — view helpers shared across screens
 *
 * Separate from index.js on purpose. If these lived in the registry, every
 * screen would import the registry and the registry would import every screen,
 * and the cycle only resolves because function declarations hoist. That works
 * until someone adds a `const` helper, at which point screens start failing at
 * load with a temporal-dead-zone error that reads like nonsense.
 *
 * These exist so the empty state on the shop reads the same as the one on the
 * leaderboard. Screens should differ in what they show, not in how they say
 * "nothing here yet".
 */

export function emptyState(h, { title, body, action = null }) {
    return h('div', { class: 'empty-state' },
        h('p', { class: 'empty-title' }, title),
        body ? h('p', { class: 'empty-body' }, body) : null,
        action || null,
    );
}

export function panel(h, title, ...children) {
    return h('section', { class: 'panel' },
        title ? h('h2', { class: 'panel-title' }, title) : null,
        ...children,
    );
}

/** A load-bearing distinction: "no data yet" is not the same as "loaded, empty". */
export function pending(h, label = 'Loading…') {
    return h('div', { class: 'pending', role: 'status' }, label);
}
