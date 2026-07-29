/**
 * screens/ranks.js
 *
 * The leaderboard is the one screen where surgical rendering is load-bearing
 * for a reason other than input: rows move. When a player overtakes another,
 * a positional render would rewrite both rows' contents in place and the
 * change would read as two players' names flickering. Keyed by player_id, the
 * two rows swap instead, and only the rank numbers change.
 */

import { pending, emptyState, panel } from './ui.js';

const PAGE_SIZE = 25;

const fmt = new Intl.NumberFormat('en-US');

function row(ctx, entry, index, page, mePlayerId) {
    const { h } = ctx;
    const rank = (page - 1) * PAGE_SIZE + index + 1;
    const isMe = mePlayerId !== null && Number(entry.player_id) === Number(mePlayerId);

    return h('tr', {
        key: entry.player_id,
        class: 'lb-row' + (isMe ? ' is-me' : ''),
    },
        h('td', { class: 'lb-rank tabular' }, String(rank)),
        h('td', { class: 'lb-player' },
            h('span', { class: 'lb-handle' }, entry.handle || '—'),
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
        await ctx.loadLeaderboard(1);
    },

    view(ctx) {
        const { h, store } = ctx;
        const data = store.get('screens.ranks');
        const player = store.get('player');
        const mePlayerId = player ? (player.player_id ?? null) : null;

        if (!data) return pending(h, 'Loading leaderboard…');

        const entries = data.entries || [];
        const page = data.page || 1;
        const hasMore = entries.length === PAGE_SIZE;

        if (!entries.length && page === 1) {
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
                        entries.map((e, i) => row(ctx, e, i, page, mePlayerId))),
                ),
            ),

            h('div', { class: 'pager' },
                h('button', {
                    class: 'btn btn-ghost',
                    disabled: page <= 1,
                    onClick: () => ctx.loadLeaderboard(page - 1),
                }, 'Previous'),
                h('span', { class: 'pager-label tabular' }, `Page ${page}`),
                h('button', {
                    class: 'btn btn-ghost',
                    disabled: !hasMore,
                    onClick: () => ctx.loadLeaderboard(page + 1),
                }, 'Next'),
            ),
        );
    },
};

export { PAGE_SIZE };
