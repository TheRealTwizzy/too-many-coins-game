/**
 * screens/shop.js
 *
 * Cosmetics only. Nothing here touches the economy — global stars spent on
 * cosmetics do not lower a player's rank, which is worth saying on the screen
 * itself because it is the first thing anyone assumes otherwise.
 */

import { pending, emptyState, errorState, panel } from './ui.js';

const CATEGORIES = [
    { id: 'all', label: 'All' },
    { id: 'avatar_frame', label: 'Frames' },
    { id: 'name_color', label: 'Colours' },
    { id: 'profile_bg', label: 'Backgrounds' },
    { id: 'title', label: 'Titles' },
    { id: 'effect', label: 'Effects' },
];

const fmt = new Intl.NumberFormat('en-US');

function item(ctx, entry, owned, equippedIds, balance) {
    const { h, store } = ctx;
    const id = Number(entry.cosmetic_id ?? entry.id);
    const price = Math.round(Number(entry.price_global_stars) || 0);
    const isOwned = owned.has(id);
    const isEquipped = equippedIds.has(id);
    const busy = Number(store.get('ui.shopBusy')) === id;
    const affordable = balance >= price;

    return h('article', { key: id, class: 'shop-item' + (isEquipped ? ' is-equipped' : '') },
        h('div', { class: 'shop-preview', 'data-category': entry.category },
            h('span', { class: 'shop-preview-mark' }, (entry.name || '?').slice(0, 1)),
        ),
        h('h3', { class: 'shop-name' }, entry.name || 'Unnamed'),
        entry.description ? h('p', { class: 'shop-desc muted small' }, entry.description) : null,

        isOwned
            ? h('button', {
                class: 'btn ' + (isEquipped ? 'btn-ghost' : 'btn-primary'),
                disabled: busy,
                onClick: () => ctx.equipCosmetic(id, !isEquipped),
            }, busy ? 'Working…' : isEquipped ? 'Unequip' : 'Equip')
            : h('button', {
                class: 'btn btn-primary',
                disabled: busy || !affordable,
                title: affordable ? null : 'Not enough global stars',
                onClick: () => ctx.buyCosmetic(id),
            }, busy ? 'Buying…' : `${fmt.format(price)} ★`),
    );
}

export default {
    id: 'shop',

    async enter(ctx) {
        await ctx.loadShop();
    },

    view(ctx) {
        const { h, store } = ctx;
        const player = store.get('player');
        const data = store.get('screens.shop');
        const filter = store.get('ui.shopFilter') || 'all';

        if (!data) return pending(h, 'Loading cosmetics…');
        if (data.error) {
            return errorState(h, {
                title: 'Could not load the shop',
                message: data.error,
                onRetry: () => ctx.loadShop(),
            });
        }

        const catalog = data.catalog || [];
        const owned = new Set((data.owned || []).map(c => Number(c.cosmetic_id ?? c.id)));
        const equipped = new Set(
            Object.values(data.equipped || {})
                .map(v => Number(v))
                .filter(n => Number.isFinite(n)),
        );
        const balance = player ? Math.round(Number(player.global_stars) || 0) : 0;

        const shown = filter === 'all'
            ? catalog
            : catalog.filter(c => c.category === filter);

        return panel(h, 'Cosmetics',
            h('p', { class: 'panel-sub' },
                'Spend global stars on appearance. Pure prestige — spending here does not lower your rank.'),

            h('div', { class: 'shop-balance' },
                h('span', { class: 'muted' }, 'Balance'),
                h('span', { class: 'tabular' }, `${fmt.format(balance)} ★`),
            ),

            h('div', { class: 'tabs', role: 'tablist' },
                CATEGORIES.map(c => h('button', {
                    key: c.id,
                    class: 'tab' + (c.id === filter ? ' is-active' : ''),
                    role: 'tab',
                    'aria-selected': c.id === filter ? 'true' : 'false',
                    onClick: () => store.set('ui.shopFilter', c.id),
                }, c.label)),
            ),

            shown.length
                ? h('div', { class: 'shop-grid' },
                    shown.map(entry => item(ctx, entry, owned, equipped, balance)))
                : emptyState(h, {
                    title: 'Nothing in this category',
                    body: 'Try another filter.',
                }),
        );
    },
};
