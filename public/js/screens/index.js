/**
 * screens/index.js — screen registry
 *
 * A screen is a plain object:
 *
 *   {
 *     id,                    matches the rail id and store.screen
 *     enter(ctx),            optional. Runs once when the screen becomes
 *                            active — fetch what the poll does not already
 *                            provide.
 *     view(ctx),             required. Pure: returns vnodes, reads state,
 *                            writes nothing.
 *     leave(ctx),            optional. Cancel in-flight work.
 *   }
 *
 * view() is called on every render pass, which happens on every store change
 * and once a second for countdowns. That is only affordable because the
 * reconciler makes a redundant render free — an unchanged tree touches no DOM
 * at all. So screens are written as though they redraw everything, and in
 * practice almost nothing moves.
 *
 * The corollary is that view() must stay pure. Fetching from inside it would
 * fire a request per second; that belongs in enter().
 */

import home from './home.js';
import seasons from './seasons.js';
import season from './season.js';
import ranks from './ranks.js';
import chat from './chat.js';
import shop from './shop.js';

const SCREENS = [home, seasons, season, ranks, chat, shop];

/**
 * Screens reachable from the rail, in rail order.
 *
 * 'season' is deliberately absent: it is reached by choosing one from the
 * seasons list, not from the rail. It still highlights Seasons while open —
 * see RAIL_PARENT.
 */
export const RAIL_IDS = ['home', 'seasons', 'ranks', 'chat', 'shop'];

/** Which rail entry should look active for a screen that is not itself on the rail. */
export const RAIL_PARENT = { season: 'seasons' };

const byId = new Map(SCREENS.map(s => [s.id, s]));

export function getScreen(id) {
    return byId.get(id) || null;
}

export function allScreens() {
    return SCREENS.slice();
}

// Shared view helpers live in ./ui.js, not here. Screens import them from
// there so this file can import every screen without the two forming a cycle.
