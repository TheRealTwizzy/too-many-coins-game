/**
 * screens/home.js
 *
 * Two different screens wearing one name: a pitch for someone logged out, and
 * a status board for someone mid-season. The logged-out version is the only
 * page in the client that has to explain what the game is.
 */

import { panel, emptyState } from './ui.js';

function loggedOut(ctx) {
    const { h, navigate } = ctx;

    return h('div', { class: 'home home-pitch' },
        h('div', { class: 'hero' },
            h('h1', { class: 'hero-title' }, 'Too Many Coins'),
            h('p', { class: 'hero-sub' },
                'A deterministic economic competition. Every coin is a decision about ',
                'when to spend, when to hold, and who to take from.'),
            h('div', { class: 'hero-actions' },
                h('button', {
                    class: 'btn btn-primary btn-lg',
                    onClick: () => navigate('auth'),
                }, 'Play'),
                h('a', { class: 'btn btn-ghost btn-lg', href: '/wiki/' }, 'Read the wiki'),
            ),
        ),
        h('div', { class: 'pitch-grid' },
            pitchCard(h, 'Seasons end', 'Fourteen days. Then everything you did not lock in is gone.'),
            pitchCard(h, 'Stars are the score', 'Coins buy stars. Stars carry across seasons; coins do not.'),
            pitchCard(h, 'Others are the risk', 'Theft, wards and freezes mean your position is never only yours.'),
        ),
    );
}

function pitchCard(h, title, body) {
    return h('div', { class: 'pitch-card' },
        h('h3', null, title),
        h('p', null, body),
    );
}

function loggedIn(ctx) {
    const { h, store, navigate } = ctx;
    const player = store.get('player');
    const part = player.participation || {};
    const seasons = store.get('seasons') || [];

    const joinedId = player.joined_season_id ?? null;
    const joined = joinedId !== null
        ? seasons.find(s => Number(s.season_id) === Number(joinedId)) || null
        : null;

    return h('div', { class: 'home' },
        h('div', { class: 'greeting' },
            h('h1', null, player.handle || 'Player'),
            h('p', { class: 'greeting-sub' },
                joined
                    ? `In ${joined.name || 'Season ' + joined.season_id}.`
                    : 'Not in a season right now.'),
        ),

        joined
            ? panel(ctx.h, 'Your season',
                h('div', { class: 'stat-row' },
                    stat(h, 'Coins', part.coins),
                    stat(h, 'Seasonal stars', part.effective_seasonal_stars ?? part.seasonal_stars),
                    // Hidden until the first sigil is discovered.
                    ctx.unlocked('sigils.ui') ? stat(h, 'Sigils', part.sigils_total) : null,
                ),
                h('button', {
                    class: 'btn btn-primary',
                    onClick: () => navigate('seasons'),
                }, 'Open season'),
            )
            : emptyState(h, {
                title: 'No season joined',
                body: 'Seasons run for fourteen days and a new one starts every seven. Join one to start earning.',
                action: h('button', {
                    class: 'btn btn-primary',
                    onClick: () => navigate('seasons'),
                }, 'Browse seasons'),
            }),

        panel(ctx.h, 'Carried over',
            h('div', { class: 'stat-row' },
                stat(h, 'Global stars', player.global_stars),
            ),
            h('p', { class: 'muted' },
                'Global stars are the only thing a season leaves behind. Spending them ',
                'on cosmetics does not lower your rank.'),
            h('a', { class: 'btn btn-ghost btn-sm', href: '/wiki/' }, 'Read the wiki'),
        ),
    );
}

function stat(h, label, value) {
    return h('div', { class: 'stat' },
        h('span', { class: 'stat-label' }, label),
        h('span', { class: 'stat-value tabular' },
            new Intl.NumberFormat('en-US').format(Math.round(Number(value) || 0))),
    );
}

export default {
    id: 'home',

    view(ctx) {
        return ctx.store.get('player') ? loggedIn(ctx) : loggedOut(ctx);
    },
};
