/**
 * screens/chat.js
 *
 * The screen the rebuilt client exists for. Chat is where the legacy client's
 * innerHTML rebuild was most obviously wrong: a message arriving mid-sentence
 * took your draft with it, and the caret jumped to the end of whatever
 * survived.
 *
 * Three things keep that from happening here, and they are worth naming
 * because two of them are easy to undo by accident:
 *
 *   1. The input is keyed, so it is the same DOM node across every render.
 *   2. The reconciler refuses to write `value` to a focused text field, so a
 *      poll landing between two keystrokes cannot replace what is being typed.
 *   3. Messages are keyed by message_id, so an arriving message is inserted
 *      rather than shifting every row's content down by one.
 */

import { pending, emptyState, errorState } from './ui.js';

// Only the channels the server can actually serve.
//
// A STAFF tab used to be listed here. It was unreachable in two independent
// ways: the gate tested for lowercase 'staff'/'admin' while the server
// publishes 'Admin'/'Moderator', so it never appeared for anyone including
// admins; and had it appeared, chat_messages has no STAFF read path and
// channel_kind is an ENUM of GLOBAL/SEASON/DM, so posting to it could not
// store and reading it could not return. Staff coordination lives in the
// staff_chat_* thread actions, which are a different surface entirely.
const CHANNELS = [
    { id: 'GLOBAL', label: 'Global', always: true },
    { id: 'SEASON', label: 'Season', needsSeason: true },
];

const SCROLL_PIN_SLOP_PX = 48;

// Matches the game_state cadence. Chat has its own backoff channel in
// core/api.js, so a rate-limited transcript does not stall the main poll.
const CHAT_POLL_MS = 3000;

function timeOf(created) {
    const t = Date.parse(created);
    if (Number.isNaN(t)) return '';
    const d = new Date(t);
    return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
}

function message(ctx, msg) {
    const { h } = ctx;

    if (Number(msg.is_removed)) {
        return h('li', { key: msg.message_id, class: 'msg msg-removed' },
            h('span', { class: 'msg-body muted' },
                'Message removed', msg.removal_reason ? ` — ${msg.removal_reason}` : ''),
        );
    }

    return h('li', {
        key: msg.message_id,
        class: 'msg' + (Number(msg.is_admin_post) ? ' msg-admin' : ''),
    },
        h('span', { class: 'msg-time tabular' }, timeOf(msg.created_at)),
        // Click a handle to open the profile. sender_id ships on every row;
        // handle_snapshot stays the displayed text so history renders the
        // name as it was when the message was sent.
        msg.sender_id
            ? h('button', {
                class: 'link-handle msg-handle',
                onClick: () => ctx.openProfile(msg.sender_id),
            }, msg.handle_snapshot || '—')
            : h('span', { class: 'msg-handle' }, msg.handle_snapshot || '—'),
        h('span', { class: 'msg-body' }, msg.content || ''),
    );
}

function availableChannels(ctx) {
    const player = ctx.store.get('player');
    if (!player) return [];
    const inSeason = (player.joined_season_id ?? null) !== null;

    return CHANNELS.filter(c => c.always || (c.needsSeason && inSeason));
}

export default {
    id: 'chat',

    /**
     * Chat is the one screen with its own poll.
     *
     * game_state carries the player and the seasons, but not the transcript,
     * so without this the message list would be fetched once on entry and then
     * never again — the room would look permanently frozen at whatever was
     * there when you walked in.
     *
     * It runs on the shared clock rather than its own setInterval, so it stops
     * with everything else in a hidden tab instead of polling a backgrounded
     * chat window forever, and it is torn down in leave() so leaving the
     * screen actually stops the traffic.
     */
    enter(ctx) {
        ctx.loadChat();
        ctx.chatPollStop = ctx.clock.every(CHAT_POLL_MS, () => ctx.loadChat());
    },

    leave(ctx) {
        if (ctx.chatPollStop) {
            ctx.chatPollStop();
            ctx.chatPollStop = null;
        }
    },

    /**
     * Keep the transcript pinned to the newest message — but only if it was
     * already at the bottom. Someone scrolled up reading history is doing
     * something deliberate, and yanking them back down every 3s makes the
     * backlog unreadable.
     */
    afterRender(ctx) {
        const list = document.getElementById('chat-log');
        if (!list) return;

        const pinned = ctx.chatWasPinned !== false;
        if (pinned) list.scrollTop = list.scrollHeight;

        // Record for the *next* render, before anything else can scroll it.
        ctx.chatWasPinned =
            list.scrollHeight - list.scrollTop - list.clientHeight < SCROLL_PIN_SLOP_PX;
    },

    view(ctx) {
        const { h, store } = ctx;
        const player = store.get('player');

        if (!player) {
            return emptyState(h, {
                title: 'Chat is for players',
                body: 'Log in to read and post.',
                action: h('button', { class: 'btn btn-primary', onClick: () => ctx.navigate('auth') }, 'Log in'),
            });
        }

        const channels = availableChannels(ctx);
        const active = store.get('ui.chatChannel') || 'GLOBAL';
        const data = store.get('screens.chat');
        const draft = store.get('ui.chatDraft') || '';
        const sending = Boolean(store.get('ui.chatSending'));

        return h('div', { class: 'chat' },
            h('div', { class: 'tabs', role: 'tablist' },
                channels.map(c => h('button', {
                    key: c.id,
                    class: 'tab' + (c.id === active ? ' is-active' : ''),
                    role: 'tab',
                    'aria-selected': c.id === active ? 'true' : 'false',
                    onClick: () => ctx.switchChat(c.id),
                }, c.label)),
            ),

            !data
                ? pending(h, 'Loading messages…')
                : data.error
                    // A failed transcript fetch must not read as an empty room.
                    // The compose box stays: the channel may well accept a send
                    // even when a read failed, and removing it mid-conversation
                    // would be worse than leaving it.
                    ? errorState(h, {
                        title: 'Could not load messages',
                        message: data.error,
                        onRetry: () => ctx.loadChat(),
                    })
                    : h('ul', { id: 'chat-log', class: 'chat-log' },
                        (data.messages || []).length
                            ? (data.messages || []).map(m => message(ctx, m))
                            : h('li', { class: 'msg muted' }, 'No messages yet. Say something.'),
                    ),

            h('form', {
                class: 'chat-compose',
                onSubmit: (e) => { e.preventDefault(); ctx.sendChat(e.currentTarget); },
            },
                h('input', {
                    key: 'chat-input',
                    id: 'chat-input',
                    type: 'text',
                    class: 'input',
                    placeholder: 'Message…',
                    maxlength: 500,
                    autocomplete: 'off',
                    value: draft,
                    // Mirrored into the store on every keystroke so the draft
                    // survives switching screens and coming back. The store is
                    // not what protects it from the poll — the reconciler's
                    // focus guard is — but it is what makes it durable.
                    onInput: (e) => ctx.store.set('ui.chatDraft', e.target.value),
                }),
                h('button', {
                    type: 'submit',
                    class: 'btn btn-primary',
                    disabled: sending || !draft.trim(),
                }, sending ? 'Sending…' : 'Send'),
            ),
        );
    },
};
