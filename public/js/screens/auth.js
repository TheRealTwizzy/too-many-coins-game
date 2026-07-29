/**
 * screens/auth.js — log in and register
 *
 * The forms here are deliberately *uncontrolled*: the DOM owns the field
 * values and they are read on submit, rather than being mirrored into the
 * store on every keystroke.
 *
 * Two reasons, and the second is the important one.
 *
 *   1. Nothing else needs them. Mirroring would drive a render per keystroke
 *      to no benefit.
 *   2. A password in the store is a password in application state — readable
 *      by anything holding the store, and liable to end up in a debug dump or
 *      an error report. Leaving it in the DOM node keeps its lifetime as short
 *      as the form's.
 *
 * That works precisely because the inputs are keyed and the reconciler mutates
 * in place: the nodes survive every poll, so the values in them do too. No
 * `value` prop is passed at all, so the render layer never writes to these
 * fields and the focus guard is not even needed here.
 */

import { panel } from './ui.js';

// Mirrors Auth::validateHandle and the register() length checks server-side.
// Client-side validation is a courtesy — it turns a round trip into an instant
// answer — but the server stays authoritative and its error text wins.
const HANDLE_RE = /^[A-Za-z0-9_]{3,16}$/;
const PASSWORD_MIN = 6;

function field(h, { id, label, type, autocomplete, placeholder, hint }) {
    return h('div', { class: 'field' },
        h('label', { class: 'field-label', for: id }, label),
        h('input', {
            key: id,
            id,
            class: 'input',
            type,
            autocomplete,
            placeholder,
            required: true,
        }),
        hint ? h('p', { class: 'field-hint muted small' }, hint) : null,
    );
}

function readValue(id) {
    const el = document.getElementById(id);
    return el ? el.value : '';
}

function loginForm(ctx) {
    const { h, store } = ctx;
    const busy = Boolean(store.get('ui.authBusy'));

    return h('form', {
        key: 'login-form',
        class: 'auth-form',
        onSubmit: (e) => { e.preventDefault(); ctx.doLogin(); },
    },
        field(h, { id: 'login-email', label: 'Email', type: 'email', autocomplete: 'email', placeholder: 'player@example.com' }),
        field(h, { id: 'login-password', label: 'Password', type: 'password', autocomplete: 'current-password', placeholder: 'Your password' }),
        h('button', { type: 'submit', class: 'btn btn-primary btn-lg btn-full', disabled: busy },
            busy ? 'Signing in…' : 'Log in'),
    );
}

function registerForm(ctx) {
    const { h, store } = ctx;
    const busy = Boolean(store.get('ui.authBusy'));

    return h('form', {
        key: 'register-form',
        class: 'auth-form',
        onSubmit: (e) => { e.preventDefault(); ctx.doRegister(); },
    },
        field(h, {
            id: 'reg-handle', label: 'Handle', type: 'text', autocomplete: 'username',
            placeholder: 'YourHandle',
            hint: '3–16 characters. Letters, numbers and underscores. Handles are permanent.',
        }),
        field(h, { id: 'reg-email', label: 'Email', type: 'email', autocomplete: 'email', placeholder: 'player@example.com' }),
        field(h, {
            id: 'reg-password', label: 'Password', type: 'password', autocomplete: 'new-password',
            placeholder: `At least ${PASSWORD_MIN} characters`,
        }),
        h('button', { type: 'submit', class: 'btn btn-primary btn-lg btn-full', disabled: busy },
            busy ? 'Creating…' : 'Create account'),
    );
}

export default {
    id: 'auth',

    leave(ctx) {
        // Clear the error on the way out so returning to the screen does not
        // greet you with a stale failure from last time.
        ctx.store.set('ui.authError', null);
    },

    view(ctx) {
        const { h, store } = ctx;
        const player = store.get('player');

        if (player) {
            return panel(h, 'Already signed in',
                h('p', { class: 'muted' }, `Signed in as ${player.handle}.`),
                h('div', { class: 'auth-signed-actions' },
                    h('button', { class: 'btn btn-primary', onClick: () => ctx.navigate('home') }, 'Continue'),
                    h('button', { class: 'btn btn-ghost', onClick: () => ctx.doLogout() }, 'Log out'),
                ),
            );
        }

        const tab = store.get('ui.authTab') || 'login';
        const error = store.get('ui.authError');

        return h('div', { class: 'auth' },
            h('div', { class: 'auth-card' },
                h('div', { class: 'tabs', role: 'tablist' },
                    h('button', {
                        key: 'login', class: 'tab' + (tab === 'login' ? ' is-active' : ''),
                        role: 'tab', 'aria-selected': tab === 'login' ? 'true' : 'false',
                        onClick: () => ctx.switchAuthTab('login'),
                    }, 'Log in'),
                    h('button', {
                        key: 'register', class: 'tab' + (tab === 'register' ? ' is-active' : ''),
                        role: 'tab', 'aria-selected': tab === 'register' ? 'true' : 'false',
                        onClick: () => ctx.switchAuthTab('register'),
                    }, 'Register'),
                ),

                // role=alert so a screen reader announces the failure rather
                // than leaving someone wondering why the button did nothing.
                error ? h('div', { class: 'auth-error', role: 'alert' }, error) : null,

                tab === 'login' ? loginForm(ctx) : registerForm(ctx),
            ),
        );
    },
};

export { HANDLE_RE, PASSWORD_MIN, readValue };
