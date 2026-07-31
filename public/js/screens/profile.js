/**
 * screens/profile.js — a player's public face, and the owner's controls.
 *
 * One screen, two postures. Viewing someone else: identity, badges, season
 * history, the live-season card, and the relationship actions (friend /
 * block) driven by the server's `relationship` block — the client never
 * guesses at social state. Viewing yourself: the same identity surfaces plus
 * the account controls (visibility, status, bio), pending friend requests,
 * and your friends and blocks lists.
 *
 * Everything renders from `screens.profile` (the `profile` action) and, for
 * the owner, `screens.account` (account_get / friends / requests / blocks).
 * The server enforces visibility; `restricted` and `deleted` payloads get
 * their own honest states rather than a broken page.
 */

import { pending, emptyState, panel } from './ui.js';

const fmt = new Intl.NumberFormat('en-US');
const ROMAN = { 1: 'I', 2: 'II', 3: 'III', 4: 'IV', 5: 'V', 6: 'VI' };

function num(v) { return Math.round(Number(v) || 0); }

function formatDate(iso) {
    const t = Date.parse(iso || '');
    if (!Number.isFinite(t)) return '—';
    return new Date(t).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
}

function formatRemaining(seconds) {
    const s = Math.max(0, Math.floor(Number(seconds) || 0));
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    if (h > 0) return `${h}h ${m}m`;
    return `${m}m`;
}

/* ------------------------------------------------------------------ *
 * pieces
 * ------------------------------------------------------------------ */

function identityHeader(ctx, profile, isSelf) {
    const { h } = ctx;
    const cosmetics = profile.equipped_cosmetics || {};
    const nameColor = cosmetics.name_color && cosmetics.name_color.value;
    const title = cosmetics.title && cosmetics.title.name;

    return h('header', { class: 'profile-header' },
        h('div', { class: 'profile-id' },
            h('h1', {
                class: 'profile-handle',
                style: nameColor ? { color: nameColor } : null,
            }, profile.handle || '—'),
            title ? h('span', { class: 'profile-title-cosmetic' }, title) : null,
            profile.role && profile.role !== 'Player'
                ? h('span', { class: 'badge badge-role' }, profile.role)
                : null,
            isSelf ? h('span', { class: 'badge' }, 'You') : null,
        ),
        h('p', { class: 'muted small' },
            profile.online_current ? 'Online now · ' : '',
            `Member since ${formatDate(profile.created_at)}`),
        profile.profile_status
            ? h('p', { class: 'profile-status' }, profile.profile_status)
            : null,
        profile.bio ? h('p', { class: 'profile-bio' }, profile.bio) : null,
    );
}

function relationshipActions(ctx, profile) {
    const { h, store } = ctx;
    const rel = profile.relationship;
    if (!rel) return null;

    const busy = Boolean(store.get('ui.profileBusy'));
    const id = Number(profile.player_id);
    const buttons = [];

    if (rel.is_blocked) {
        buttons.push(h('button', {
            key: 'unblock', class: 'btn btn-ghost btn-sm', disabled: busy,
            onClick: () => ctx.socialAction('block_remove', id),
        }, 'Unblock'));
    } else {
        if (rel.is_friend) {
            buttons.push(h('button', {
                key: 'unfriend', class: 'btn btn-ghost btn-sm', disabled: busy,
                onClick: () => ctx.socialAction('friend_remove', id),
            }, 'Remove friend'));
        } else if (rel.request_pending) {
            buttons.push(h('span', { key: 'pending', class: 'muted small' }, 'Friend request pending'));
        } else {
            buttons.push(h('button', {
                key: 'friend', class: 'btn btn-primary btn-sm', disabled: busy,
                onClick: () => ctx.socialAction('friend_request_send', id),
            }, 'Add friend'));
        }
        buttons.push(h('button', {
            key: 'block', class: 'btn btn-ghost btn-sm btn-danger-ghost', disabled: busy,
            onClick: () => ctx.socialAction('block_add', id),
        }, 'Block'));
    }

    return h('div', { class: 'profile-actions' }, buttons);
}

function starsPanel(ctx, profile) {
    const { h } = ctx;
    const progress = profile.global_stars_progress || {};

    return panel(h, 'Global stars',
        h('div', { class: 'stat-row' },
            h('div', { class: 'stat' },
                h('span', { class: 'stat-label' }, 'Global stars'),
                h('span', { class: 'stat-value tabular' }, fmt.format(num(profile.global_stars)))),
            progress.percent !== undefined
                ? h('div', { class: 'stat' },
                    h('span', { class: 'stat-label' }, 'Toward next'),
                    h('span', { class: 'stat-value tabular' }, `${num(progress.percent)}%`))
                : null,
        ),
    );
}

const BADGE_LABELS = {
    season_first: '🥇 Season winner',
    season_second: '🥈 Season runner-up',
    season_third: '🥉 Season third',
    yearly_top10: '🏆 Yearly top 10',
};

function badgesPanel(ctx, profile) {
    const { h } = ctx;
    const badges = profile.badges || [];
    if (!badges.length) return null;

    return panel(h, 'Badges',
        h('ul', { class: 'badge-list' },
            badges.map(b => h('li', { key: `${b.badge_type}-${b.season_id}-${b.awarded_at}`, class: 'badge-item' },
                h('span', null, BADGE_LABELS[b.badge_type] || b.badge_type),
                h('span', { class: 'muted small' },
                    b.season_id ? ` Season ${b.season_id}` : '', ` · ${formatDate(b.awarded_at)}`),
            )),
        ),
    );
}

function historyPanel(ctx, profile) {
    const { h } = ctx;
    const rows = profile.season_history || [];
    if (!rows.length) return null;

    return panel(h, 'Season history',
        h('div', { class: 'table-scroll' },
            h('table', { class: 'lb-table' },
                h('thead', null, h('tr', null,
                    h('th', null, 'Season'),
                    h('th', { class: 'lb-stars' }, 'Stars'),
                    h('th', null, 'Exit'),
                )),
                h('tbody', null,
                    rows.map(r => h('tr', { key: `${r.season_id}` },
                        h('td', null, `Season ${r.season_id}`),
                        h('td', { class: 'lb-stars tabular' },
                            fmt.format(num(r.payout_seasonal_stars ?? r.effective_seasonal_stars))),
                        h('td', { class: 'muted small' },
                            r.lock_in_effect_tick ? 'Locked in' : 'Season end'),
                    )),
                ),
            ),
        ),
    );
}

function liveSeasonPanel(ctx, profile) {
    const { h } = ctx;
    const live = profile.active_participation;
    if (!live) return null;

    const sigils = Array.isArray(live.sigils) ? live.sigils : [];
    const boost = live.active_boost || {};
    const frozen = Boolean(live.freeze && live.freeze.is_frozen);

    return panel(h, 'This season',
        h('div', { class: 'stat-row' },
            h('div', { class: 'stat' },
                h('span', { class: 'stat-label' }, 'Season'),
                h('span', { class: 'stat-value' }, `#${live.season_id}`)),
            h('div', { class: 'stat' },
                h('span', { class: 'stat-label' }, 'Stars'),
                h('span', { class: 'stat-value tabular' },
                    fmt.format(num(live.effective_seasonal_stars ?? live.seasonal_stars)))),
            h('div', { class: 'stat' },
                h('span', { class: 'stat-label' }, 'Activity'),
                h('span', { class: 'stat-value' }, live.activity_state || '—')),
        ),

        h('div', { class: 'tier-row' },
            sigils.map((count, i) => h('div', {
                key: i + 1,
                class: 'tier' + (num(count) > 0 ? ' has-some' : ''),
            },
                h('span', { class: 'tier-label' }, ROMAN[i + 1]),
                h('span', { class: 'tier-count tabular' }, fmt.format(num(count))),
            )),
        ),

        boost.is_active
            ? h('p', { class: 'muted small' },
                `Boost +${boost.total_modifier_percent}% — ${formatRemaining(boost.remaining_real_seconds)} remaining.`)
            : null,
        frozen ? h('p', { class: 'muted small' }, '❄ Income currently frozen.') : null,
    );
}

/* ------------------------------------------------------------------ *
 * own-profile: account controls + social lists
 * ------------------------------------------------------------------ */

function accountPanel(ctx) {
    const { h, store } = ctx;
    const account = store.get('screens.account');
    if (!account) return panel(h, 'Account', pending(h, 'Loading account…'));

    const busy = Boolean(store.get('ui.accountBusy'));
    const visibility = store.get('ui.accountVisibility') ?? account.profile_visibility ?? 'PUBLIC';

    return panel(h, 'Account',
        h('div', { class: 'account-form' },
            h('label', { class: 'field' },
                h('span', { class: 'field-label' }, 'Profile visibility'),
                h('select', {
                    key: 'acct-visibility',
                    class: 'input',
                    onChange: (e) => store.set('ui.accountVisibility', e.target.value),
                },
                    // selected attributes rather than a select.value prop: the
                    // attribute survives whatever order the reconciler builds
                    // the options in.
                    h('option', { value: 'PUBLIC', selected: visibility === 'PUBLIC' ? 'selected' : false }, 'Public'),
                    h('option', { value: 'FRIENDS_ONLY', selected: visibility === 'FRIENDS_ONLY' ? 'selected' : false }, 'Friends only'),
                    h('option', { value: 'HIDDEN', selected: visibility === 'HIDDEN' ? 'selected' : false }, 'Hidden'),
                ),
            ),
            h('label', { class: 'field' },
                h('span', { class: 'field-label' }, 'Status (80 chars)'),
                h('input', {
                    key: 'acct-status',
                    class: 'input',
                    type: 'text',
                    maxlength: 80,
                    value: store.get('ui.accountStatus') ?? account.profile_status ?? '',
                    onInput: (e) => store.set('ui.accountStatus', e.target.value),
                }),
            ),
            h('label', { class: 'field' },
                h('span', { class: 'field-label' }, 'Bio (280 chars)'),
                h('textarea', {
                    key: 'acct-bio',
                    class: 'input input-multiline',
                    maxlength: 280,
                    rows: 3,
                    value: store.get('ui.accountBio') ?? account.bio ?? '',
                    onInput: (e) => store.set('ui.accountBio', e.target.value),
                }),
            ),
            h('div', { class: 'form-actions' },
                h('button', {
                    class: 'btn btn-primary btn-sm',
                    disabled: busy,
                    onClick: () => ctx.saveAccount(),
                }, busy ? 'Saving…' : 'Save profile'),
            ),
        ),
    );
}

function requestsPanel(ctx) {
    const { h, store } = ctx;
    const me = store.get('player');
    const requests = store.get('screens.friendRequests') || [];
    const incoming = requests.filter(r => Number(r.to_player) === Number(me && me.player_id));
    const outgoing = requests.filter(r => Number(r.from_player) === Number(me && me.player_id));
    if (!incoming.length && !outgoing.length) return null;

    return panel(h, 'Friend requests',
        incoming.length
            ? h('ul', { class: 'social-list' },
                incoming.map(r => h('li', { key: r.id, class: 'social-row' },
                    h('button', { class: 'link-handle', onClick: () => ctx.openProfile(r.from_player) }, r.from_handle),
                    h('div', { class: 'social-actions' },
                        h('button', {
                            class: 'btn btn-primary btn-sm',
                            onClick: () => ctx.respondFriendRequest(r.id, 'ACCEPTED'),
                        }, 'Accept'),
                        h('button', {
                            class: 'btn btn-ghost btn-sm',
                            onClick: () => ctx.respondFriendRequest(r.id, 'DECLINED'),
                        }, 'Decline'),
                    ),
                )))
            : null,
        outgoing.length
            ? h('p', { class: 'muted small' },
                `Waiting on: ${outgoing.map(r => r.to_handle).join(', ')}`)
            : null,
    );
}

function friendsPanel(ctx) {
    const { h, store } = ctx;
    const friends = store.get('screens.friends');
    if (friends === undefined || friends === null) return null;

    return panel(h, 'Friends',
        friends.length
            ? h('ul', { class: 'social-list' },
                friends.map(f => h('li', { key: f.player_id, class: 'social-row' },
                    h('button', { class: 'link-handle', onClick: () => ctx.openProfile(f.player_id) },
                        h('span', { class: 'presence-dot' + (f.online_current ? ' is-online' : '') }),
                        f.handle),
                    f.profile_status ? h('span', { class: 'muted small' }, f.profile_status) : null,
                )))
            : h('p', { class: 'muted small' }, 'No friends yet. Add someone from their profile.'),
    );
}

function blocksPanel(ctx) {
    const { h, store } = ctx;
    const blocks = store.get('screens.blocks') || [];
    if (!blocks.length) return null;

    return panel(h, 'Blocked',
        h('ul', { class: 'social-list' },
            blocks.map(b => h('li', { key: b.player_id, class: 'social-row' },
                h('span', null, b.handle),
                h('button', {
                    class: 'btn btn-ghost btn-sm',
                    onClick: () => ctx.socialAction('block_remove', b.player_id),
                }, 'Unblock'),
            )),
        ),
    );
}

/* ------------------------------------------------------------------ *
 * screen
 * ------------------------------------------------------------------ */

export default {
    id: 'profile',

    async enter(ctx) {
        await ctx.loadProfile();
    },

    view(ctx) {
        const { h, store } = ctx;
        const targetId = store.get('ui.profileId');
        const profile = store.get('screens.profile');
        const me = store.get('player');

        if (targetId === null || targetId === undefined) {
            return emptyState(h, {
                title: 'No player selected',
                body: 'Open a profile from the standings, ranks or chat.',
            });
        }

        if (!profile) return pending(h, 'Loading profile…');

        if (profile.error) {
            return emptyState(h, {
                title: 'Profile unavailable',
                body: String(profile.error),
                action: h('button', { class: 'btn btn-primary', onClick: () => ctx.navigate('home') }, 'Back'),
            });
        }

        if (profile.deleted) {
            return emptyState(h, { title: '[Removed]', body: 'This profile has been removed.' });
        }

        if (profile.restricted) {
            return emptyState(h, {
                title: profile.handle || 'Private profile',
                body: profile.visibility === 'FRIENDS_ONLY'
                    ? 'This profile is visible to friends only.'
                    : 'This profile is private.',
            });
        }

        const isSelf = Boolean(me) && Number(me.player_id) === Number(profile.player_id);

        return h('div', { class: 'profile' },
            identityHeader(ctx, profile, isSelf),
            relationshipActions(ctx, profile),
            liveSeasonPanel(ctx, profile),
            starsPanel(ctx, profile),
            badgesPanel(ctx, profile),
            historyPanel(ctx, profile),
            isSelf ? accountPanel(ctx) : null,
            isSelf ? requestsPanel(ctx) : null,
            isSelf ? friendsPanel(ctx) : null,
            isSelf ? blocksPanel(ctx) : null,
        );
    },
};
