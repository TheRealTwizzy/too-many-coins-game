/**
 * screens/staff.js — moderation and admin controls
 *
 * The parked legacy client was the only moderation UI; this screen restores
 * the essentials in the rebuilt client: server mode (admin), player lookup
 * with mutes and audit trail (staff), role updates (admin), and player /
 * broadcast notifications (staff).
 *
 * Everything here is double-gated: the rail hides the entry and the view
 * renders an empty state for non-staff, but the real enforcement is the
 * server's requireStaff/requireAdmin on every action this screen calls.
 * Drafts live in ui.* store keys via onInput, same as chat — the reconciler's
 * focus guard keeps the poll from clobbering a field mid-typing.
 */

import { panel, emptyState } from './ui.js';

const MUTE_SCOPES = ['ALL', 'GLOBAL', 'SEASON', 'STAFF'];
const ROLES = ['Player', 'Moderator', 'Admin'];

function isStaffRole(role) {
    return role === 'Admin' || role === 'Moderator';
}

function field(h, label, control) {
    return h('label', { class: 'staff-field' },
        h('span', { class: 'staff-field-label' }, label),
        control,
    );
}

function serverModePanel(ctx) {
    const { h, store } = ctx;
    const mode = store.get('serverMode') || 'NORMAL';
    const maintenance = mode === 'MAINTENANCE_LOCKDOWN';

    return panel(h, 'Server mode',
        h('p', { class: 'panel-sub' },
            maintenance
                ? 'Maintenance lockdown is UP: players see the construction page; the world keeps ticking.'
                : 'Server is in normal operation.'),
        h('div', { class: 'staff-form-row' },
            field(h, 'Reason', h('input', {
                class: 'input',
                type: 'text',
                placeholder: 'audit reason',
                onInput: (e) => store.set('ui.staffModeReason', e.target.value),
            })),
            h('button', {
                class: 'btn ' + (maintenance ? 'btn-primary' : 'btn-danger'),
                onClick: () => ctx.staffServerMode(maintenance ? 'NORMAL' : 'MAINTENANCE_LOCKDOWN'),
            }, maintenance ? 'Lift maintenance' : 'Enter maintenance'),
        ),
    );
}

function searchPanel(ctx, data) {
    const { h, store } = ctx;
    const results = (data && data.results) || [];

    return panel(h, 'Find player',
        h('form', {
            class: 'staff-form-row',
            onSubmit: (e) => { e.preventDefault(); ctx.staffSearch(); },
        },
            h('input', {
                class: 'input',
                type: 'search',
                placeholder: 'handle or email',
                onInput: (e) => store.set('ui.staffQuery', e.target.value),
            }),
            h('button', { type: 'submit', class: 'btn btn-primary' }, 'Search'),
        ),
        results.length
            ? h('ul', { class: 'staff-results' },
                results.map((u) => h('li', { key: u.player_id },
                    h('button', {
                        class: 'btn btn-ghost btn-sm',
                        onClick: () => ctx.staffOpenUser(u.player_id),
                    }, `${u.handle} — ${u.role}`),
                )))
            : null,
    );
}

function detailPanel(ctx, detail, viewerRole) {
    const { h, store } = ctx;
    const user = detail.user;
    const mutes = detail.mutes || [];
    const audit = detail.audit || [];
    const isAdmin = viewerRole === 'Admin';

    return panel(h, `${user.handle}`,
        h('dl', { class: 'staff-identity' },
            h('div', null, h('dt', null, 'Role'), h('dd', null, user.role)),
            h('div', null, h('dt', null, 'Email'), h('dd', null, user.email || '—')),
            h('div', null, h('dt', null, 'Visibility'), h('dd', null, user.profile_visibility || '—')),
            h('div', null, h('dt', null, 'Joined'), h('dd', null, user.created_at || '—')),
            h('div', null, h('dt', null, 'Last seen'), h('dd', null, user.last_seen_at || '—')),
        ),

        isAdmin
            ? h('div', { class: 'staff-form-row' },
                field(h, 'Role', h('select', {
                    class: 'input',
                    onChange: (e) => store.set('ui.staffRole', e.target.value),
                }, ROLES.map((r) => h('option', { key: r, value: r, selected: r === user.role }, r)))),
                field(h, 'Reason', h('input', {
                    class: 'input', type: 'text', placeholder: 'audit reason',
                    onInput: (e) => store.set('ui.staffRoleReason', e.target.value),
                })),
                h('button', {
                    class: 'btn btn-primary',
                    onClick: () => ctx.staffSetRole(user.player_id, user.role),
                }, 'Update role'),
            )
            : null,

        h('h3', { class: 'staff-subhead' }, 'Mutes'),
        mutes.length
            ? h('ul', { class: 'staff-mutes' },
                mutes.map((m) => h('li', { key: m.mute_id },
                    `${m.scope} — ${m.reason || 'no reason'}${m.expires_at ? ' (until ' + m.expires_at + ')' : ' (indefinite)'} `,
                    h('button', {
                        class: 'btn btn-ghost btn-sm',
                        onClick: () => ctx.staffUnmute(m.mute_id, user.player_id),
                    }, 'Unmute'),
                )))
            : h('p', { class: 'muted small' }, 'No active mutes.'),
        h('div', { class: 'staff-form-row' },
            field(h, 'Scope', h('select', {
                class: 'input',
                onChange: (e) => store.set('ui.staffMuteScope', e.target.value),
            }, MUTE_SCOPES.map((s) => h('option', { key: s, value: s }, s)))),
            field(h, 'Minutes', h('input', {
                class: 'input input-sm', type: 'number', min: '0', placeholder: '0 = forever',
                onInput: (e) => store.set('ui.staffMuteMinutes', e.target.value),
            })),
            field(h, 'Reason', h('input', {
                class: 'input', type: 'text', placeholder: 'audit reason',
                onInput: (e) => store.set('ui.staffMuteReason', e.target.value),
            })),
            h('button', {
                class: 'btn btn-danger',
                onClick: () => ctx.staffMute(user.player_id),
            }, 'Mute'),
        ),

        h('h3', { class: 'staff-subhead' }, 'Notify'),
        h('div', { class: 'staff-form-row' },
            field(h, 'Title', h('input', {
                class: 'input', type: 'text',
                onInput: (e) => store.set('ui.staffNoteTitle', e.target.value),
            })),
            field(h, 'Body', h('input', {
                class: 'input', type: 'text',
                onInput: (e) => store.set('ui.staffNoteBody', e.target.value),
            })),
            h('button', {
                class: 'btn btn-primary',
                onClick: () => ctx.staffNotify(user.player_id),
            }, 'Send to player'),
            h('button', {
                class: 'btn btn-ghost',
                onClick: () => ctx.staffNotify(null),
            }, 'Broadcast to all'),
        ),

        audit.length
            ? h('details', { class: 'staff-audit' },
                h('summary', null, `Audit trail (${audit.length})`),
                h('ul', null, audit.map((a, i) => h('li', { key: a.audit_id ?? String(i) },
                    `${a.created_at || ''} ${a.action_type || ''} — ${a.reason || ''}`))),
            )
            : null,
    );
}

export default {
    id: 'staff',

    view(ctx) {
        const { h, store } = ctx;
        const player = store.get('player');
        const role = player ? player.role : null;

        if (!isStaffRole(role)) {
            return emptyState(h, {
                title: 'Staff only',
                body: 'This area is for moderators and admins.',
            });
        }

        const data = store.get('screens.staff');
        return h('div', { class: 'staff-screen' },
            role === 'Admin' ? serverModePanel(ctx) : null,
            searchPanel(ctx, data),
            data && data.detail && data.detail.user
                ? detailPanel(ctx, data.detail, role)
                : h('p', { class: 'muted small' }, 'Search for a player to inspect, mute or notify them.'),
        );
    },
};
