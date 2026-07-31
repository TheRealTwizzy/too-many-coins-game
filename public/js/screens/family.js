/**
 * screens/family.js — the seven sigil families and their verbs.
 *
 * Everything here renders from `screens.family` (the family_state action):
 * the family roster, the player's per-family holdings, their affinity, the
 * live season-wide event, ward and market state, and the forge switches.
 * The verbs — ward_activate, market_prime, sight_reveal, affinity_pick,
 * transmute, distil — run through ctx.familyVerb / ctx.sightReveal so every
 * outcome surfaces the server's own message.
 *
 * The screen only exists while the server says the family layer is live
 * (store.familiesEnabled gates the rail entry), and each family section only
 * offers a verb the player can actually attempt — holdings-gated, not
 * hope-gated.
 */

import { pending, emptyState, errorState, panel } from './ui.js';

const fmt = new Intl.NumberFormat('en-US');
const ROMAN = { 1: 'I', 2: 'II', 3: 'III', 4: 'IV', 5: 'V', 6: 'VI' };

function num(v) { return Math.round(Number(v) || 0); }

/** Family verb copy, keyed by server code. The names come from the server. */
const FAMILY_LORE = {
    yield: 'Feeds the boost forge — power burns brighter.',
    time: 'Feeds the boost forge — power burns longer.',
    ward: 'Raises wards against theft. Tier I primes a silent one-shot deflect.',
    larceny: 'Sharpens theft. Spent sigils favour the taking hand.',
    market: 'Primes a discount on your next star purchase (up to 50%).',
    sight: 'Reveals what others keep hidden. Consumed lowest tier first.',
    wild: 'Stands in for any family when a verb spends sigils.',
};

const EVENT_COPY = {
    swarm: { title: 'The Legion swarms', body: 'Sigils are falling faster for everyone.' },
    frenzy: { title: 'Frenzy', body: 'Hostile cooldowns and protections run at half length.' },
    foresight: { title: 'Foresight', body: 'The Sight trickles threefold.' },
};

function eventBanner(ctx, state) {
    const { h } = ctx;
    const event = state.season_event;
    if (!event) return null;
    const copy = EVENT_COPY[event.kind] || { title: event.kind, body: 'A season-wide event is live.' };

    return h('div', { class: 'family-event', 'data-kind': event.kind },
        h('strong', null, copy.title),
        h('span', null, ` — ${copy.body}`),
        h('span', { class: 'muted small' }, ` Awakened by a Tier ${event.source_tier} mass.`),
    );
}

function affinityPanel(ctx, state) {
    const { h, store } = ctx;
    const families = (state.families || []).filter(f => f.enabled && !['sight', 'wild'].includes(f.code));
    if (!families.length) return null;

    const current = families.find(f => Number(f.family_id) === Number(state.affinity_family_id)) || null;
    const chosen = store.get('ui.familyAffinity') || (current ? current.code : '');
    const busy = store.get('ui.familyBusy') === 'affinity';

    return panel(h, 'Affinity',
        h('p', { class: 'panel-sub' },
            'Attune to one material family: its sigils drop for you more often. '
            + 'One re-pick is allowed, during blackout, and everyone sees it.'),
        h('div', { class: 'affinity-row' },
            h('select', {
                key: 'affinity-select',
                class: 'input',
                onChange: (e) => store.set('ui.familyAffinity', e.target.value),
            },
                !current ? h('option', { value: '', selected: chosen === '' ? 'selected' : false }, 'Choose a family…') : null,
                families.map(f => h('option', {
                    key: f.code,
                    value: f.code,
                    selected: chosen === f.code ? 'selected' : false,
                }, f.name)),
            ),
            h('button', {
                class: 'btn btn-primary btn-sm',
                disabled: busy || !chosen || (current && chosen === current.code),
                onClick: () => ctx.familyVerb('affinity_pick', { family: store.get('ui.familyAffinity') || chosen }, 'affinity'),
            }, busy ? 'Attuning…' : current ? 'Re-attune' : 'Attune'),
        ),
        current
            ? h('p', { class: 'muted small' },
                `Attuned to ${current.name}.`,
                state.affinity_repicked ? ' Your one re-pick is spent.' : '')
            : null,
    );
}

/** tiers: {1: n, ...} from holdings. Renders the tier chips for one family. */
function tierChips(h, tiers) {
    const entries = Object.entries(tiers || {}).filter(([, n]) => num(n) > 0);
    if (!entries.length) return h('span', { class: 'muted small' }, 'none held');
    return h('span', { class: 'family-tiers' },
        entries.map(([tier, count]) => h('span', { key: tier, class: 'family-tier-chip tabular' },
            `${ROMAN[tier]}×${fmt.format(num(count))}`)),
    );
}

function holdingsPanel(ctx, state) {
    const { h, assets } = ctx;
    const families = state.families || [];
    const holdings = new Map((state.holdings || []).map(hh => [Number(hh.family_id), hh]));

    return panel(h, 'The seven families',
        h('ul', { class: 'family-list' },
            families.map(f => {
                const held = holdings.get(Number(f.family_id));
                return h('li', { key: f.code, class: 'family-row' + (f.enabled ? '' : ' is-disabled') },
                    h('span', { class: 'family-glyph' }, assets.icon(`family-${f.code}`)),
                    h('div', { class: 'family-copy' },
                        h('span', { class: 'family-name' }, f.name),
                        h('span', { class: 'muted small' }, FAMILY_LORE[f.code] || ''),
                    ),
                    tierChips(h, held ? held.tiers : null),
                );
            }),
        ),
    );
}

function wardPanel(ctx, state) {
    const { h, store } = ctx;
    const ward = state.ward || {};
    const holdings = (state.holdings || []).find(hh => hh.code === 'ward');
    const tiers = holdings ? Object.keys(holdings.tiers || {}).map(Number).sort((a, b) => a - b) : [];
    const chosen = num(store.get('ui.familyWardTier')) || tiers[0] || 0;
    const busy = store.get('ui.familyBusy') === 'ward';

    return panel(h, 'Ward',
        h('p', { class: 'panel-sub' },
            'Wards do not stack. Tier I primes a silent one-shot deflect that a rival cannot see; '
            + 'higher tiers raise a visible timed window.'),

        ward.active
            ? h('div', { class: 'notice notice-ward' },
                h('strong', null, ward.one_shot ? '🛡 Deflect primed.' : '🛡 Ward raised.'),
                h('span', null, ward.one_shot
                    ? ' It holds until it breaks a theft attempt.'
                    : ' Theft attempts against you fail while it lasts.'))
            : tiers.length
                ? h('div', { class: 'ward-row' },
                    h('select', {
                        key: 'ward-tier',
                        class: 'input',
                        onChange: (e) => store.set('ui.familyWardTier', num(e.target.value)),
                    },
                        tiers.map(t => h('option', {
                            key: t, value: String(t),
                            selected: t === chosen ? 'selected' : false,
                        }, `Michael ${ROMAN[t]}${t === 1 ? ' — one-shot deflect' : ''}`)),
                    ),
                    h('button', {
                        class: 'btn btn-primary btn-sm',
                        disabled: busy || !chosen,
                        onClick: () => ctx.familyVerb('ward_activate', { tier: num(store.get('ui.familyWardTier')) || chosen }, 'ward'),
                    }, busy ? 'Raising…' : 'Raise ward'),
                )
                : h('p', { class: 'muted small' }, 'No Michael sigils held. Wards need the ward family.'),
    );
}

function marketPanel(ctx, state) {
    const { h, store } = ctx;
    const market = state.market || {};
    const holdings = (state.holdings || []).find(hh => hh.code === 'market');
    const tiers = holdings ? Object.keys(holdings.tiers || {}).map(Number).sort((a, b) => a - b) : [];
    const chosen = num(store.get('ui.familyMarketTier')) || tiers[0] || 0;
    const busy = store.get('ui.familyBusy') === 'market';

    return panel(h, 'Market',
        h('p', { class: 'panel-sub' },
            'Prime a Mammon sigil to discount your next star purchase — once per day, up to 50%.'),

        num(market.pending_vp) > 0
            ? h('div', { class: 'notice notice-boost' },
                h('strong', null, 'Discount primed.'),
                h('span', null, ' Your next star purchase spends it.'))
            : tiers.length
                ? h('div', { class: 'ward-row' },
                    h('select', {
                        key: 'market-tier',
                        class: 'input',
                        onChange: (e) => store.set('ui.familyMarketTier', num(e.target.value)),
                    },
                        tiers.map(t => h('option', {
                            key: t, value: String(t),
                            selected: t === chosen ? 'selected' : false,
                        }, `Mammon ${ROMAN[t]}`)),
                    ),
                    h('button', {
                        class: 'btn btn-primary btn-sm',
                        disabled: busy || !chosen,
                        onClick: () => ctx.familyVerb('market_prime', { tier: num(store.get('ui.familyMarketTier')) || chosen }, 'market'),
                    }, busy ? 'Priming…' : 'Prime discount'),
                )
                : h('p', { class: 'muted small' }, 'No Mammon sigils held.'),
    );
}

const SIGHT_KINDS = [
    { kind: 'target_rate', label: 'Read a rival\'s income rate', needsTarget: true },
    { kind: 'ward_status', label: 'Scout a rival\'s ward', needsTarget: true },
    { kind: 'price_step', label: 'Read the star-price mechanism', needsTarget: false },
    { kind: 'pity', label: 'Read your own drop fortune', needsTarget: false },
];

function sightPanel(ctx, state) {
    const { h, store } = ctx;
    const holdings = (state.holdings || []).find(hh => hh.code === 'sight');
    const total = holdings
        ? Object.values(holdings.tiers || {}).reduce((a, b) => a + num(b), 0)
        : 0;
    const reveal = store.get('screens.familyReveal');
    const busy = String(store.get('ui.familyBusy') || '').startsWith('sight:');

    return panel(h, 'Sight',
        h('p', { class: 'panel-sub' },
            'Azazel answers one question per sigil, lowest tier first. '
            + 'Rivals are told when they are scried.'),

        total > 0
            ? h('div', { class: 'sight-grid' },
                SIGHT_KINDS.map(k => h('button', {
                    key: k.kind,
                    class: 'btn btn-ghost btn-sm',
                    disabled: busy,
                    onClick: () => ctx.sightReveal(k.kind),
                }, k.label)))
            : h('p', { class: 'muted small' }, 'No Azazel sigils held.'),

        reveal ? sightRevealView(ctx, reveal) : null,
    );
}

function sightRevealView(ctx, res) {
    const { h } = ctx;
    const r = res.reveal || {};
    let lines = [];

    if (res.kind === 'target_rate') {
        lines = [`${r.target_handle} earns about ${r.gross_rate_per_min} coins a minute.`];
    } else if (res.kind === 'ward_status') {
        lines = [r.ward_up
            ? `${r.target_handle} stands warded. A theft would break on it.`
            : `${r.target_handle} is unwarded — no visible protection.`];
    } else if (res.kind === 'price_step') {
        lines = [`The published star price is ${fmt.format(num(r.published_star_price))}` +
            (num(r.star_price_cap) ? `, capped at ${fmt.format(num(r.star_price_cap))}.` : '.')];
    } else if (res.kind === 'pity') {
        lines = [num(r.ticks_to_pity) <= 0
            ? 'Fortune owes you: your next eligible tick carries a guaranteed drop.'
            : `${fmt.format(num(r.ticks_to_pity))} eligible ticks until fortune must pay out.`];
    }

    return h('div', { class: 'sight-reveal' },
        h('strong', null, 'The Sight shows: '),
        lines.map((line, i) => h('span', { key: i }, line)),
        h('span', { class: 'muted small' }, ` (consumed Azazel ${ROMAN[res.consumed_tier] || '?'})`),
    );
}

const MATERIAL_CODES = ['yield', 'time', 'ward', 'larceny', 'market'];

function forgePanel(ctx, state) {
    const { h, store } = ctx;
    const forge = state.forge || {};
    if (!forge.transmute_enabled && !forge.distil_enabled) return null;

    const holdings = new Map((state.holdings || []).map(hh => [hh.code, hh.tiers || {}]));
    const busy = store.get('ui.familyBusy');

    const transTier = num(store.get('ui.familyTransTier')) || 1;
    const transFams = store.get('ui.familyTransFams') || [];
    const distilTier = num(store.get('ui.familyDistilTier')) || 2;
    const distilFam = store.get('ui.familyDistilFam') || MATERIAL_CODES[0];

    return panel(h, 'Forge',
        forge.transmute_enabled ? h('div', { class: 'forge-block' },
            h('p', { class: 'forge-title' }, 'Transmute — three families, one tier → two Wildcards'),
            h('div', { class: 'forge-row' },
                h('select', {
                    key: 'trans-tier', class: 'input',
                    onChange: (e) => store.set('ui.familyTransTier', num(e.target.value)),
                }, [1, 2, 3, 4, 5, 6].map(t => h('option', {
                    key: t, value: String(t), selected: t === transTier ? 'selected' : false,
                }, `Tier ${ROMAN[t]}`))),
                h('div', { class: 'forge-fams' },
                    MATERIAL_CODES.map(code => {
                        const has = num((holdings.get(code) || {})[transTier]) > 0;
                        const picked = transFams.includes(code);
                        return h('button', {
                            key: code,
                            class: 'chip chip-pick' + (picked ? ' is-picked' : ''),
                            disabled: !has && !picked,
                            title: has ? null : `No ${code} sigil at this tier`,
                            onClick: () => {
                                const next = picked
                                    ? transFams.filter(c => c !== code)
                                    : transFams.length < 3 ? [...transFams, code] : transFams;
                                store.set('ui.familyTransFams', next);
                            },
                        }, code);
                    }),
                ),
                h('button', {
                    class: 'btn btn-primary btn-sm',
                    disabled: busy === 'transmute' || transFams.length !== 3,
                    onClick: () => ctx.familyVerb('transmute_sigils',
                        { tier: transTier, families: transFams }, 'transmute'),
                }, busy === 'transmute' ? 'Forging…' : 'Transmute'),
            ),
        ) : null,

        forge.distil_enabled ? h('div', { class: 'forge-block' },
            h('p', { class: 'forge-title' }, 'Distil — three Sight of a tier → one family sigil, a tier down'),
            h('div', { class: 'forge-row' },
                h('select', {
                    key: 'distil-tier', class: 'input',
                    onChange: (e) => store.set('ui.familyDistilTier', num(e.target.value)),
                }, [2, 3, 4, 5, 6].map(t => h('option', {
                    key: t, value: String(t), selected: t === distilTier ? 'selected' : false,
                }, `Sight ${ROMAN[t]}`))),
                h('select', {
                    key: 'distil-fam', class: 'input',
                    onChange: (e) => store.set('ui.familyDistilFam', e.target.value),
                }, MATERIAL_CODES.map(code => h('option', {
                    key: code, value: code, selected: code === distilFam ? 'selected' : false,
                }, code))),
                h('button', {
                    class: 'btn btn-primary btn-sm',
                    disabled: busy === 'distil',
                    onClick: () => ctx.familyVerb('distil_sigils',
                        { tier: distilTier, target_family: store.get('ui.familyDistilFam') || distilFam }, 'distil'),
                }, busy === 'distil' ? 'Forging…' : 'Distil'),
            ),
        ) : null,
    );
}

function eventsPanel(ctx) {
    const { h, store } = ctx;
    const events = store.get('screens.familyEvents');
    if (!events || !events.length) return null;

    return panel(h, 'Season chronicle',
        h('ul', { class: 'chronicle' },
            events.map(e => h('li', { key: e.event_id ?? `${e.event_tick}-${e.public_text}`, class: 'chronicle-row' },
                h('span', { class: 'muted small tabular' }, `t${e.event_tick}`),
                h('span', null, e.public_text),
            )),
        ),
    );
}

/* ------------------------------------------------------------------ *
 * screen
 * ------------------------------------------------------------------ */

export default {
    id: 'family',

    async enter(ctx) {
        const player = ctx.store.get('player');
        // The Sight target picker draws candidates from the season detail, so
        // make sure it is loaded for a joined player.
        if (player && player.joined_season_id) {
            ctx.store.set('ui.seasonId', Number(player.joined_season_id));
            ctx.loadSeasonDetail();
        }
        await ctx.loadFamily();
    },

    view(ctx) {
        const { h, store } = ctx;
        const player = store.get('player');
        const state = store.get('screens.family');

        if (!player) {
            return emptyState(h, { title: 'Sign in', body: 'The families reveal themselves to players.' });
        }
        if (!player.joined_season_id) {
            return emptyState(h, {
                title: 'No season joined',
                body: 'Family sigils live inside a season. Join one first.',
                action: h('button', { class: 'btn btn-primary', onClick: () => ctx.navigate('seasons') }, 'Browse seasons'),
            });
        }
        if (!state) return pending(h, 'Consulting the families…');
        if (state.error) {
            return errorState(h, {
                title: 'The families did not answer',
                message: state.error,
                onRetry: () => ctx.loadFamily(),
            });
        }
        if (!state.enabled) {
            return emptyState(h, { title: 'The families are quiet', body: 'Nothing answers yet.' });
        }

        return h('div', { class: 'family' },
            eventBanner(ctx, state),
            holdingsPanel(ctx, state),
            affinityPanel(ctx, state),
            wardPanel(ctx, state),
            marketPanel(ctx, state),
            sightPanel(ctx, state),
            forgePanel(ctx, state),
            eventsPanel(ctx),
        );
    },
};
