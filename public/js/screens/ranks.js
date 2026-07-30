/**
 * screens/ranks.js
 *
 * The leaderboard is the one screen where surgical rendering is load-bearing
 * for a reason other than input: rows move. When a player overtakes another,
 * a positional render would rewrite both rows' contents in place and the
 * change would read as two players' names flickering. Keyed by player_id, the
 * two rows swap instead, and only the rank numbers change.
 *
 * No pager: global_leaderboard returns the full ranked list and takes no
 * arguments. The old pager sent page/per_page params the server ignored, so
 * every "page" re-rendered the same list.
 */

import { pending, emptyState, panel } from './ui.js';

const fmt = new Intl.NumberFormat('en-US');

function row(ctx, entry, index, mePlayerId) {
    const { h } = ctx;
    const rank = index + 1;
    const isMe = mePlayerId !== null && Number(entry.player_id) === Number(mePlayerId);

    return h('tr', {
        key: entry.player_id,
        class: 'lb-row' + (isMe ? ' is-me' : ''),
    },
        h('td', { class: 'lb-rank tabular' }, String(rank)),
        h('td', { class: 'lb-player' },
            h('button', {
                class: 'link-handle',
                onClick: () => ctx.openProfile(entry.player_id),
            }, entry.handle || '—'),
            isMe ? h('span', { class: 'badge badge-you' }, 'you') : null,
        ),
        h('td', { class: 'lb-stars tabular' },
            fmt.format(Math.round(Number(entry.global_stars_lifetime ?? entry.global_stars) || 0))),
        h('td', { class: 'lb-state' },
            h('span', {
                class: 'dot dot-' + String(entry.activity_state || 'Active').toLowerCase(),
                title: entry.activity_state || 'Active',
            }),
            Number(entry.online_current) ? 'online' : 'offline',
        ),
    );
}

export default {
    id: 'ranks',

    async enter(ctx) {
        await ctx.loadLeaderboard();
    },

    view(ctx) {
        const { h, store } = ctx;
        const data = store.get('screens.ranks');
        const player = store.get('player');
        const mePlayerId = player ? (player.player_id ?? null) : null;

        if (!data) return pending(h, 'Loading leaderboard…');

        const entries = data.entries || [];

        if (!entries.length) {
            return emptyState(h, {
                title: 'Nobody on the board yet',
                body: 'Global stars are earned through season outcomes and lock-in.',
            });
        }

        return panel(h, 'Leaderboard',
            h('p', { class: 'panel-sub' },
                'Ranked by total global stars earned. Spending on cosmetics does not lower your rank.'),

            h('div', { class: 'table-scroll' },
                h('table', { class: 'lb-table' },
                    h('thead', null,
                        h('tr', null,
                            h('th', { class: 'lb-rank' }, 'Rank'),
                            h('th', null, 'Player'),
                            h('th', { class: 'lb-stars' }, 'Stars'),
                            h('th', null, 'Status'),
                        ),
                    ),
                    h('tbody', null,
                        entries.map((e, i) => row(ctx, e, i, mePlayerId))),
                ),
            ),
        );
    },
};
