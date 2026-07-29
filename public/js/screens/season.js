/**
 * screens/season.js — the gameplay screen
 *
 * Everything a player actually does happens here: watching the rate, buying
 * stars, forging sigils, casting the hostile verbs, and eventually locking in.
 *
 * Two things shape how this is written.
 *
 * First, almost all of its data already arrives on the 3s game_state poll —
 * coins, rate, tier counts, the can_* gates. So this screen mostly renders
 * what the store already holds, and only fetches season_detail for the things
 * the poll does not carry (the standings, the drop table).
 *
 * Second, this is the screen with irreversible actions on it. Lock-in ends a
 * player's season and converts their seasonal stars to global ones; there is
 * no undo. So the destructive verbs go through ctx.confirm() and say what
 * they will do in numbers, not adjectives.
 */

import { pending, emptyState, panel } from './ui.js';

const fmt = new Intl.NumberFormat('en-US');
const ROMAN = { 1: 'I', 2: 'II', 3: 'III', 4: 'IV', 5: 'V', 6: 'VI' };

function num(v) { return Math.round(Number(v) || 0); }

function formatRemaining(seconds) {
    const s = Math.max(0, Math.floor(Number(seconds) || 0));
    const d = Math.floor(s / 86400);
    const h = Math.floor((s % 86400) / 3600);
    const m = Math.floor((s % 3600) / 60);
    if (d > 0) return `${d}d ${h}h`;
    if (h > 0) return `${h}h ${m}m`;
    return `${m}m ${String(s % 60).padStart(2, '0')}s`;
}

/* ------------------------------------------------------------------ *
 * header
 * ------------------------------------------------------------------ */

function header(ctx, season) {
    const { h } = ctx;
    const status = season.computed_status || season.status;

    return h('header', { class: 'season-header' },
        h('div', { class: 'season-header-main' },
            h('button', {
                class: 'btn btn-ghost btn-back',
                onClick: () => ctx.navigate('seasons'),
            }, '← Seasons'),
            h('h1', { class: 'season-title' }, season.name || `Season ${season.season_id}`),
            h('span', { class: 'season-status', 'data-status': status }, status),
        ),
        h('div', { class: 'season-header-facts' },
            headFact(h, 'Ends in', formatRemaining(season.seconds_remaining ?? season.end_remaining)),
            headFact(h, 'Star price', fmt.format(num(season.current_star_price))),
            headFact(h, 'Players', fmt.format(num(season.participant_count ?? (season.leaderboard || []).length))),
        ),
    );
}

function headFact(h, label, value) {
    return h('div', { class: 'head-fact' },
        h('span', { class: 'head-fact-label' }, label),
        h('span', { class: 'head-fact-value tabular' }, String(value)),
    );
}

/* ------------------------------------------------------------------ *
 * income
 * ------------------------------------------------------------------ */

function income(ctx, player) {
    const { h } = ctx;
    const gross = Number(player.gross_rate_per_tick ?? player.rate_per_tick) || 0;
    const net = Number(player.net_rate_per_tick ?? player.rate_per_tick) || 0;
    const sinkActive = Boolean(player.hoarding_sink_active);
    const sink = Number(player.hoarding_sink_per_tick) || 0;

    return panel(h, 'Income',
        h('div', { class: 'rate-row' },
            h('div', { class: 'rate-primary' },
                h('span', { class: 'rate-value tabular' }, net.toFixed(1)),
                h('span', { class: 'rate-unit' }, 'coins / tick'),
            ),
            // The sink is the one deduction a player can act on, so it is shown
            // as a line item rather than folded silently into the net rate.
            sinkActive
                ? h('div', { class: 'rate-detail' },
                    h('span', { class: 'muted small' }, 'Hoarding sink'),
                    h('span', { class: 'tabular down' }, `−${sink.toFixed(1)}`),
                    h('p', { class: 'muted small' },
                        'You are holding a large coin balance. The sink scales with it — spending reduces it.'),
                )
                : h('p', { class: 'muted small' },
                    `Gross ${gross.toFixed(1)} / tick. No hoarding sink at your balance.`),
        ),
    );
}

/* ------------------------------------------------------------------ *
 * stars
 * ------------------------------------------------------------------ */

function stars(ctx, season, player) {
    const { h, store } = ctx;
    const price = num(season.current_star_price);
    const coins = num(player.coins);
    const affordable = price > 0 ? Math.floor(coins / price) : 0;
    const qty = Math.max(1, num(store.get('ui.starQty') || 1));
    const cost = qty * price;
    const canBuy = Boolean(player.can_purchase_stars) && cost <= coins && qty > 0;
    const busy = Boolean(store.get('ui.buyingStars'));

    return panel(h, 'Stars',
        h('p', { class: 'panel-sub' },
            'Stars are the score. Coins are not — they buy stars and nothing else carries over.'),

        h('div', { class: 'stat-row' },
            h('div', { class: 'stat' },
                h('span', { class: 'stat-label' }, 'Seasonal stars'),
                h('span', { class: 'stat-value tabular' },
                    fmt.format(num(player.effective_seasonal_stars ?? player.seasonal_stars)))),
            h('div', { class: 'stat' },
                h('span', { class: 'stat-label' }, 'Price each'),
                h('span', { class: 'stat-value tabular' }, fmt.format(price))),
            h('div', { class: 'stat' },
                h('span', { class: 'stat-label' }, 'You can afford'),
                h('span', { class: 'stat-value tabular' }, fmt.format(affordable))),
        ),

        h('div', { class: 'buy-row' },
            h('label', { class: 'sr-only', for: 'star-qty' }, 'Quantity'),
            h('input', {
                key: 'star-qty',
                id: 'star-qty',
                class: 'input input-qty',
                type: 'number',
                min: 1,
                step: 1,
                value: String(qty),
                onInput: (e) => store.set('ui.starQty', Math.max(1, num(e.target.value))),
            }),
            h('button', {
                class: 'btn btn-primary',
                disabled: !canBuy || busy,
                onClick: () => ctx.buyStars(qty, cost),
            }, busy ? 'Buying…' : `Buy for ${fmt.format(cost)}`),
        ),

        !player.can_purchase_stars
            ? h('p', { class: 'muted small' }, 'Star purchases are closed in this phase.')
            : null,
    );
}

/* ------------------------------------------------------------------ *
 * sigil forge
 * ------------------------------------------------------------------ */

function forge(ctx, season, player) {
    const { h, store } = ctx;
    const participation = player.participation || {};
    const tier6Visible = Boolean(player.tier6_visible ?? season.player_tier6_visible);
    const maxTier = tier6Visible ? 6 : 5;
    const recipes = player.combine_recipes || season.player_combine_recipes || [];
    const busy = store.get('ui.forgeBusy');

    const counts = [];
    for (let t = 1; t <= maxTier; t++) {
        counts.push({ tier: t, count: num(participation[`sigils_t${t}`]) });
    }
    const anyCombinable = recipes.some(r => r.can_combine);

    return panel(h, 'Sigils',
        h('div', { class: 'tier-row' },
            counts.map(({ tier, count }) => h('div', {
                key: tier,
                class: 'tier' + (count > 0 ? ' has-some' : ''),
            },
                h('span', { class: 'tier-label' }, ROMAN[tier]),
                h('span', { class: 'tier-count tabular' }, fmt.format(count)),
            )),
        ),

        recipes.length
            ? h('ul', { class: 'recipe-list' },
                recipes.filter(r => Number(r.from_tier) <= maxTier).map(r => h('li', {
                    key: r.from_tier,
                    class: 'recipe' + (r.can_combine ? ' is-ready' : ''),
                },
                    h('span', { class: 'recipe-text' },
                        `${r.required}× ${ROMAN[r.from_tier]} → 1× ${ROMAN[r.to_tier]}`),
                    h('span', { class: 'recipe-owned muted small tabular' },
                        `${fmt.format(num(r.owned))} held`),
                    h('button', {
                        class: 'btn btn-ghost btn-sm',
                        disabled: !r.can_combine || busy === r.from_tier,
                        onClick: () => ctx.combineSigil(r.from_tier),
                    }, busy === r.from_tier ? 'Forging…' : 'Combine'),
                )))
            : h('p', { class: 'muted small' }, 'No recipes available yet.'),

        anyCombinable
            ? h('button', {
                class: 'btn btn-primary',
                disabled: busy === 'all',
                onClick: () => ctx.combineAll(),
            }, busy === 'all' ? 'Forging…' : 'Combine all')
            : null,
    );
}

/* ------------------------------------------------------------------ *
 * hostile verbs
 * ------------------------------------------------------------------ */

function verbs(ctx, season, player) {
    const { h } = ctx;
    const freeze = player.freeze || season.player_freeze || {};
    const theft = player.theft || season.player_theft || {};
    const frozen = Boolean(freeze.is_frozen);

    const canFreeze = Boolean(player.can_freeze ?? season.player_can_freeze);
    const canMelt = Boolean(player.can_melt ?? season.player_can_melt);
    const canSteal = Boolean(player.can_steal ?? season.player_can_steal);

    return panel(h, 'Actions',
        frozen
            ? h('div', { class: 'notice notice-frozen' },
                h('strong', null, 'Your income is frozen.'),
                freeze.remaining_seconds
                    ? h('span', null, ` ${formatRemaining(freeze.remaining_seconds)} remaining.`)
                    : null,
            )
            : null,

        h('div', { class: 'verb-row' },
            h('button', {
                class: 'btn',
                disabled: !canSteal,
                title: canSteal ? null : 'Needs a spendable sigil, and no active cooldown',
                onClick: () => ctx.navigate('seasons'),
            }, 'Steal'),
            h('button', {
                class: 'btn',
                disabled: !canFreeze,
                title: canFreeze ? null : 'Needs a T4+ sigil',
                onClick: () => ctx.freezeTarget(),
            }, 'Freeze'),
            h('button', {
                class: 'btn',
                disabled: !canMelt,
                title: canMelt ? null : 'Only while frozen, and needs a T5+ sigil',
                onClick: () => ctx.selfMelt(),
            }, 'Melt'),
        ),

        theft.is_on_cooldown && theft.cooldown_remaining_seconds
            ? h('p', { class: 'muted small' },
                `Theft cooldown: ${formatRemaining(theft.cooldown_remaining_seconds)}`)
            : null,
    );
}

/* ------------------------------------------------------------------ *
 * lock-in
 * ------------------------------------------------------------------ */

function lockIn(ctx, season, player) {
    const { h, store } = ctx;
    const canLock = Boolean(player.can_lock_in);
    const payout = num(player.lock_in_stars);
    const busy = Boolean(store.get('ui.lockingIn'));

    return panel(h, 'Lock in',
        h('p', { class: 'panel-sub' },
            'Ends your season and converts what you hold into global stars. There is no undo.'),

        h('div', { class: 'lockin-row' },
            h('div', { class: 'stat' },
                h('span', { class: 'stat-label' }, 'Would pay out'),
                h('span', { class: 'stat-value tabular' }, `${fmt.format(payout)} ★`)),
            h('button', {
                class: 'btn btn-danger',
                disabled: !canLock || busy,
                onClick: () => ctx.lockIn(payout),
            }, busy ? 'Locking in…' : 'Lock in'),
        ),

        !canLock
            ? h('p', { class: 'muted small' }, 'Lock-in is not available right now.')
            : null,
    );
}

/* ------------------------------------------------------------------ *
 * standings
 * ------------------------------------------------------------------ */

function standings(ctx, season, player) {
    const { h } = ctx;
    const rows = season.leaderboard || [];
    const meId = player ? player.player_id : null;

    if (!rows.length) {
        return panel(h, 'Standings', h('p', { class: 'muted small' }, 'No standings yet.'));
    }

    return panel(h, 'Standings',
        h('div', { class: 'table-scroll' },
            h('table', { class: 'lb-table' },
                h('thead', null, h('tr', null,
                    h('th', { class: 'lb-rank' }, '#'),
                    h('th', null, 'Player'),
                    h('th', { class: 'lb-stars' }, 'Stars'),
                )),
                h('tbody', null,
                    rows.slice(0, 50).map((r, i) => h('tr', {
                        key: r.player_id,
                        class: 'lb-row' + (meId !== null && Number(r.player_id) === Number(meId) ? ' is-me' : ''),
                    },
                        h('td', { class: 'lb-rank tabular' }, String(i + 1)),
                        h('td', null,
                            h('span', { class: 'lb-handle' }, r.handle || '—'),
                            r.lock_in_effect_tick ? h('span', { class: 'badge' }, 'locked in') : null,
                        ),
                        h('td', { class: 'lb-stars tabular' },
                            fmt.format(num(r.effective_seasonal_stars ?? r.seasonal_stars))),
                    )),
                ),
            ),
        ),
    );
}

/* ------------------------------------------------------------------ *
 * screen
 * ------------------------------------------------------------------ */

export default {
    id: 'season',

    async enter(ctx) {
        await ctx.loadSeasonDetail();
    },

    view(ctx) {
        const { h, store } = ctx;
        const seasonId = store.get('ui.seasonId');
        const detail = store.get('screens.season');
        const player = store.get('player');

        if (seasonId === null || seasonId === undefined) {
            return emptyState(h, {
                title: 'No season selected',
                body: 'Pick one from the seasons list.',
                action: h('button', { class: 'btn btn-primary', onClick: () => ctx.navigate('seasons') }, 'Browse seasons'),
            });
        }

        if (!detail) return pending(h, 'Loading season…');
        if (detail.error) {
            return emptyState(h, {
                title: 'Season unavailable',
                body: String(detail.error),
                action: h('button', { class: 'btn btn-primary', onClick: () => ctx.navigate('seasons') }, 'Back to seasons'),
            });
        }

        // Joined is decided by the player record rather than by the presence of
        // detail: season_detail returns public information for a season you are
        // only spectating, and rendering the forge against an absent
        // participation would show a player six zeroes and a dead Combine button.
        const joined = Boolean(player)
            && Number(player.joined_season_id) === Number(seasonId)
            && Boolean(player.participation_enabled);

        return h('div', { class: 'season-detail' },
            header(ctx, detail),

            joined
                ? [
                    income(ctx, player),
                    stars(ctx, detail, player),
                    forge(ctx, detail, player),
                    verbs(ctx, detail, player),
                    lockIn(ctx, detail, player),
                ]
                : h('div', { class: 'spectating' },
                    emptyState(h, {
                        title: player ? 'You are not in this season' : 'Spectating',
                        body: player
                            ? 'You can watch the standings, but the economy is closed to you here.'
                            : 'Log in to take part.',
                    })),

            standings(ctx, detail, player),
        );
    },
};
