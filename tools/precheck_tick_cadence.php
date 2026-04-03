<?php
/**
 * Precheck script for minute->second tick migration readiness.
 *
 * Usage:
 *   php tools/precheck_tick_cadence.php
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';

if (!extension_loaded('pdo_mysql')) {
    fwrite(STDERR, "precheck_tick_cadence: pdo_mysql extension is not available in this PHP runtime.\n");
    exit(2);
}

$db = Database::getInstance();

$seasons = $db->fetch(
    "SELECT
        COUNT(*) AS count_all,
        MAX(end_time - start_time) AS max_duration,
        MIN(end_time - start_time) AS min_duration,
        MAX(last_processed_tick) AS max_last_processed
     FROM seasons"
);

$serverState = $db->fetch("SELECT global_tick_index, last_tick_processed_at FROM server_state WHERE id = 1");

$tradeStats = $db->fetch(
    "SELECT
        MAX(created_tick) AS max_created_tick,
        MAX(expires_tick) AS max_expires_tick,
        MAX(resolved_tick) AS max_resolved_tick
     FROM trades"
);

$hasActiveBoosts = $db->fetch(
    "SELECT COUNT(*) AS c
     FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'active_boosts'"
);

$activeBoostStats = null;
if ((int)($hasActiveBoosts['c'] ?? 0) > 0) {
    $activeBoostStats = $db->fetch(
        "SELECT
            MAX(activated_tick) AS max_activated_tick,
            MAX(expires_tick) AS max_expires_tick
         FROM active_boosts"
    );
}

$maxDuration = (int)($seasons['max_duration'] ?? 0);
$looksMinuteScale = ($maxDuration > 0 && $maxDuration < 200000);
$looksSecondScale = ($maxDuration >= 200000);

$report = [
    'runtime' => [
        'tick_real_seconds' => (int)TICK_REAL_SECONDS,
        'time_scale' => (int)TIME_SCALE,
        'tick_on_request' => (bool)TMC_TICK_ON_REQUEST,
        'minute_to_second_migration_enabled' => (bool)TMC_MINUTE_TO_SECOND_MIGRATION,
        'minute_to_second_migration_dry_run' => (bool)TMC_MINUTE_TO_SECOND_MIGRATION_DRY_RUN,
    ],
    'scale_detection' => [
        'looks_minute_scale' => $looksMinuteScale,
        'looks_second_scale' => $looksSecondScale,
        'season_max_duration' => $maxDuration,
        'season_min_duration' => (int)($seasons['min_duration'] ?? 0),
    ],
    'seasons' => $seasons,
    'server_state' => $serverState,
    'trades' => $tradeStats,
    'active_boosts' => $activeBoostStats,
    'next_step' => $looksMinuteScale
        ? 'Set TMC_TICK_REAL_SECONDS=1 and run with TMC_MINUTE_TO_SECOND_MIGRATION_DRY_RUN=1 first, then TMC_MINUTE_TO_SECOND_MIGRATION=1 for cutover.'
        : 'Minute-scale data was not detected; do not run minute-to-second migration.',
];

echo json_encode($report, JSON_PRETTY_PRINT) . PHP_EOL;
