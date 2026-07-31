/**
 * verify_email.js — the full-page email confirmation gate.
 *
 * Not a registered screen, for the same reason as construction.js: shell()
 * short-circuits to it whenever the signed-in player has no confirmed address,
 * so the screen id in the store is untouched and they land back where they
 * were the moment they confirm.
 *
 * The server refuses everything except logout, confirm, resend and reading
 * state, so this view offers exactly those. Anything else here would be a
 * button that returns 403.
 */

export function verifyEmailShell(h, { email, onResend, onLogout, status, busy }) {
    return h('div', { id: 'shell', class: 'construction-shell' },
        h('main', { class: 'construction' },
            h('div', { class: 'construction-badge' }, '✉'),
            h('h1', null, 'Confirm your email'),
            h('p', { class: 'construction-lede' },
                'Your account is created. Open the link we sent to finish setting it up — until then the game stays closed to this account.'),

            email
                ? h('p', { class: 'construction-version' }, `Sent to ${email}`)
                : null,

            h('section', { class: 'construction-block' },
                h('h2', null, 'Not arrived?'),
                h('ul', null,
                    h('li', null, 'Check the spam or junk folder — a first message from a new domain often lands there.'),
                    h('li', null, 'Links expire. If yours has, ask for a new one below.'),
                    h('li', null, 'Make sure the address above is the one you meant. If it is wrong, sign out and register again.'),
                ),
            ),

            status
                ? h('p', {
                    class: 'verify-status' + (status.tone === 'error' ? ' is-error' : ''),
                    role: 'status',
                }, status.message)
                : null,

            h('footer', { class: 'construction-foot verify-foot' },
                h('button', {
                    class: 'btn btn-primary',
                    disabled: busy ? true : undefined,
                    onClick: () => { if (!busy && onResend) onResend(); },
                }, busy ? 'Sending…' : 'Send another link'),
                h('button', {
                    class: 'btn btn-ghost btn-sm',
                    onClick: () => { if (onLogout) onLogout(); },
                }, 'Sign out'),
            ),
        ),
    );
}
