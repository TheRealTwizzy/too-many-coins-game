<?php
/**
 * Too Many Coins - per-season tick health for game_state.
 *
 * The client's payout countdown runs off the fixed tick schedule, so it keeps
 * ticking even when the server is not paying out - e.g. when every tick batch
 * throws and rolls back (docs/economy-bugs-rootcause.md). A season's
 * last_processed_tick only moves when a batch commits, so how far game time
 * has run ahead of it says whether payouts are really landing.
 *
 * Pure: no DB, no clock. tools/tick_health_selfcheck.php pins the contract.
 */

/**
 * Lag the worker can legitimately show: it runs ~150ms after each boundary
 * (worker/tick_worker.php), so a poll can land one tick before the batch for
 * that tick commits, plus one tick of slack for a slow cycle.
 */
if (!defined('TMC_TICK_HEALTH_MAX_LAG_TICKS')) {
    define('TMC_TICK_HEALTH_MAX_LAG_TICKS', 2);
}

/**
 * @param array|null $season    seasons row the player is in, or null
 * @param int        $gameTime  GameTime::now()
 * @return array{season_last_processed_tick: ?int, lag_ticks: ?int, degraded: bool}
 */
function tmc_tick_health(?array $season, int $gameTime): array {
    if ($season === null) {
        return ['season_last_processed_tick' => null, 'lag_ticks' => null, 'degraded' => false];
    }

    $lastProcessed = (int)($season['last_processed_tick'] ?? 0);
    $startTime = (int)($season['start_time'] ?? 0);
    $endTime = (int)($season['end_time'] ?? $gameTime);

    // Nothing is due before the season starts or after it ends, and a
    // finalized season has nothing left to process.
    if (!empty($season['expiration_finalized'])) {
        $lag = 0;
    } else {
        $dueThrough = min($gameTime, $endTime);
        $lag = max(0, $dueThrough - max($lastProcessed, $startTime));
    }

    return [
        'season_last_processed_tick' => $lastProcessed,
        'lag_ticks' => $lag,
        'degraded' => $lag > TMC_TICK_HEALTH_MAX_LAG_TICKS,
    ];
}
