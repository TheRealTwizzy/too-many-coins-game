/**
 * payout.js — when the payout effect is allowed to play.
 *
 * The countdown follows the fixed tick schedule, which keeps running whether
 * or not the server actually paid anyone. On test it did not: every tick
 * batch was rolling back, and the HUD still glowed "paid" at each zero
 * (docs/economy-bugs-rootcause.md). game_state now publishes
 * timing.tick_health; a season's last_processed_tick only moves when a batch
 * commits, so that is what the effect follows.
 *
 * Pure functions, no DOM: tools/client_core_selfcheck.mjs pins them.
 */

/**
 * Did a payout land between two polls? True only when the player's season
 * tick moved forward. The first poll, leaving a season, and switching to a
 * newer season (whose tick restarts lower) are not payouts.
 */
export function payoutLanded(prevTick, nextTick) {
    if (prevTick === null || prevTick === undefined || nextTick === null || nextTick === undefined) {
        return false;
    }
    const prev = Number(prevTick);
    const next = Number(nextTick);
    if (!Number.isFinite(prev) || !Number.isFinite(next)) return false;
    return next > prev;
}

/**
 * Is the server behind on the player's season? A server too old to publish
 * tick_health reads as not delayed, so the HUD keeps its old countdown.
 */
export function payoutsDelayed(timing) {
    return Boolean(timing && timing.tick_health && timing.tick_health.degraded === true);
}
