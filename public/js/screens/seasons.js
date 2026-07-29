/**
 * screens/seasons.js
 *
 * The season list comes from the game_state poll, so this screen needs no
 * fetch of its own — it is a view over data the store already holds, and
 * updates by itself every 3s.
 */

import { emptyState, panel } from './ui.js';

const STATUS_ORDER = { Active: 0, Blackout: 1, Scheduled: 2, Ended: 3 };

function formatRemaining(seconds) {
    const s = Math.max(0, Math.floor(Number(seconds) || 0));
    const d = Math.floor(s / 86400);
    const h = Math.floor((s % 86400) / 3600);
    const m = Math.floor((s % 3600) / 60);
    if (d > 0) return `${d}d ${h}h`;
    if (h > 0) return `${h}h ${m}m`;
    return `${m}m ${s % 60}s`;
}

function seasonCard(ctx, season, joinedId) {
    const { h, store } = ctx;
    const id = Number(season.season_id);
    const status = season.computed_status || season.status;
    const isJoined = joinedId !== null && Number(joinedId) === id;
    const busy = store.get(`ui.joining`) === id;

    // Blackout is the window where a season still runs but no longer accepts
    // joiners, so it needs its own affordance rather than a disabled Join that
    // looks like a bug.
    const canJoin = status === 'Active' && !isJoined && joinedId === null;

    return h('article', {
        key: id,
        class: 'season-card' + (isJoined ? ' is-joined' : ''),
        'data-status': status,
    },
        h('header', { class: 'season-head' },
            // The name opens the season rather than the whole card being
            // clickable: the card also carries a Join button, and a card-wide
            // click target makes it far too easy to open a season when you
            // meant to join one.
            h('button', {
                class: 'season-name season-open',
                onClick: () => ctx.openSeason(id),
            }, season.name || `Season ${id}`),
            h('span', { class: 'season-status', 'data-status': status }, status),
        ),

        h('dl', { class: 'season-facts' },
            fact(h, 'Ends in', formatRemaining(season.seconds_remaining ?? season.end_remaining)),
            fact(h, 'Star price', Math.round(Number(season.current_star_price) || 0)),
            fact(h, 'Players', Number(season.participant_count) || 0),
        ),

        isJoined
            ? h('div', { class: 'season-actions' },
                h('span', { class: 'badge badge-joined' }, 'Joined'))
            : canJoin
                ? h('div', { class: 'season-actions' },
                    h('button', {
                        class: 'btn btn-primary',
                        disabled: busy,
                        onClick: () => ctx.joinSeason(id),
                    }, busy ? 'Joining…' : 'Join'))
                : h('div', { class: 'season-actions' },
                    h('span', { class: 'muted small' },
                        joinedId !== null ? 'Already in another season'
                            : status === 'Blackout' ? 'Closed to new players'
                                : status === 'Scheduled' ? 'Not started yet'
                                    : 'Finished')),
    );
}

function fact(h, label, value) {
    return h('div', { class: 'fact' },
        h('dt', null, label),
        h('dd', { class: 'tabular' }, String(value)),
    );
}

export default {
    id: 'seasons',

    view(ctx) {
        const { h, store } = ctx;
        const seasons = (store.get('seasons') || []).slice();
        const player = store.get('player');
        const joinedId = player ? (player.joined_season_id ?? null) : null;

        if (!seasons.length) {
            return emptyState(h, {
                title: 'No seasons yet',
                body: 'Nothing is scheduled. Check back shortly.',
            });
        }

        seasons.sort((a, b) => {
            const sa = STATUS_ORDER[a.computed_status || a.status] ?? 9;
            const sb = STATUS_ORDER[b.computed_status || b.status] ?? 9;
            if (sa !== sb) return sa - sb;
            return Number(a.season_id) - Number(b.season_id);
        });

        return panel(h, 'Seasons',
            h('p', { class: 'panel-sub' },
                'Fourteen-day competitive seasons. A new one starts every seven days.'),
            h('div', { class: 'season-grid' },
                seasons.map(s => seasonCard(ctx, s, joinedId))),
        );
    },
};
