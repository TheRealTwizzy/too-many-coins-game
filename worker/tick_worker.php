<?php
/**
 * Too Many Coins - Dedicated Tick Worker
 *
 * Runs TickEngine::processTicks() on a fixed interval without HTTP traffic.
 * Intended for a separate Dokploy worker service using the same image as web.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/game_time.php';
require_once __DIR__ . '/../includes/economy.php';
require_once __DIR__ . '/../includes/tick_engine.php';
require_once __DIR__ . '/../includes/runtime_readiness.php';

$intervalSeconds = max(1, (int)(getenv('TMC_WORKER_INTERVAL_SECONDS') ?: getenv('TMC_TICK_REAL_SECONDS') ?: 60));
$startDelaySeconds = max(0, (int)(getenv('TMC_WORKER_START_DELAY_SECONDS') ?: 0));
$runOnce = filter_var(getenv('TMC_WORKER_RUN_ONCE') ?: '0', FILTER_VALIDATE_BOOLEAN);
$errorBackoffSeconds = max(1, (int)(getenv('TMC_WORKER_ERROR_BACKOFF_SECONDS') ?: 2));
// Align cycles to tick boundaries (default on). Set to false to restore the
// old fixed-interval loop.
$alignToTicks = filter_var(getenv('TMC_WORKER_ALIGN_TO_TICKS') ?: '1', FILTER_VALIDATE_BOOLEAN);
// How long after a boundary to run, so the boundary's second has fully passed
// on this host before GameTime::now() is read.
$alignSettleSeconds = 0.15;
$timing = get_timing_diagnostics($intervalSeconds);
$lockMissCount = 0;

if ($startDelaySeconds > 0) {
    error_log("[tick-worker] startup delay: {$startDelaySeconds}s");
    sleep($startDelaySeconds);
}

error_log("[tick-worker] starting (interval={$intervalSeconds}s, align_to_ticks=" . ($alignToTicks ? 'true' : 'false') . ", run_once=" . ($runOnce ? 'true' : 'false') . ")");
error_log("[tick-worker] timing (time_scale=" . $timing['time_scale']
    . ", tick_real_seconds=" . $timing['tick_real_seconds']
    . ", idle_timeout_ticks=" . $timing['idle_timeout_ticks']
    . ", idle_timeout_real_seconds=" . $timing['idle_timeout_real_seconds'] . ")");
if (!empty($timing['warnings'])) {
    error_log('[tick-worker] timing warnings: ' . implode(',', $timing['warnings']));
}

// Say loudly at start when the schema is behind the code. With
// TMC_AUTO_SQL_MIGRATIONS=false nothing applies a new migration on its own,
// and ticks that touch its columns roll back every cycle. Log only: the loop
// still runs so the worker recovers by itself once migrate.php has run and
// the worker restarts.
try {
    foreach (tmc_runtime_migration_log_lines(tmc_runtime_load_migration_state(Database::getInstance()->getConnection())) as $line) {
        error_log($line);
    }
} catch (Throwable $migrationCheckError) {
    Database::resetInstance();
    error_log('[tick-worker] migration check skipped: ' . $migrationCheckError->getMessage());
}

while (true) {
    $cycleStarted = microtime(true);
    $hadError = false;

    try {
        $db = Database::getInstance();

        // Guard against concurrent workers across replicas.
        $lockRow = $db->fetch("SELECT GET_LOCK('tmc_tick_worker', 0) AS got_lock");
        $hasLock = isset($lockRow['got_lock']) && (int)$lockRow['got_lock'] === 1;

        if ($hasLock) {
            $lockMissCount = 0;
            try {
                $summary = TickEngine::processTicks();
                if (
                    TMC_TICK_TRACE
                    || !empty($summary['season_error_count'])
                    || empty($summary['progressed'])
                    || !empty($summary['no_participant_season_count'])
                    || !empty($summary['expired_finalize_count'])
                ) {
                    error_log('[tick-worker] cycle_result ' . json_encode([
                        'game_time' => (int)($summary['game_time'] ?? 0),
                        'progressed' => !empty($summary['progressed']),
                        'advanced_season_count' => (int)($summary['advanced_season_count'] ?? 0),
                        'advanced_tick_count' => (int)($summary['advanced_tick_count'] ?? 0),
                        'season_error_count' => (int)($summary['season_error_count'] ?? 0),
                        'no_participant_season_count' => (int)($summary['no_participant_season_count'] ?? 0),
                        'waiting_season_count' => (int)($summary['waiting_season_count'] ?? 0),
                    ]));
                }
            } finally {
                try {
                    $db->fetch("SELECT RELEASE_LOCK('tmc_tick_worker') AS released_lock");
                } catch (Throwable $releaseError) {
                    // If connection drops, MySQL releases session locks automatically.
                    error_log('[tick-worker] lock release check failed: ' . $releaseError->getMessage());
                }
            }
        } else {
            $lockMissCount++;
            if (TMC_TICK_TRACE || ($lockMissCount % (int)TMC_TICK_LOCK_MISS_LOG_EVERY) === 0) {
                error_log('[tick-worker] advisory_lock_busy skip_count=' . $lockMissCount);
            }
        }
    } catch (Throwable $e) {
        $hadError = true;
        // Reset singleton so a fresh DB connection can be created next loop.
        Database::resetInstance();
        error_log('[tick-worker] error: ' . $e->getMessage());
    }

    if ($runOnce) {
        error_log('[tick-worker] run once complete, exiting');
        exit(0);
    }

    $elapsed = microtime(true) - $cycleStarted;
    $targetSleep = $intervalSeconds - $elapsed;
    if (!$hadError && $alignToTicks) {
        // Wake just after a tick boundary rather than at an arbitrary offset
        // from when this worker happened to start. Coins then land when the
        // client's countdown reaches zero instead of up to one interval later.
        try {
            // First boundary after (cycle start + interval - one tick +
            // settle). In steady state the cycle started just after boundary
            // B, so this lands on B + interval; an unaligned first cycle
            // snaps to the very next boundary instead of skipping one.
            $earliest = $cycleStarted + $intervalSeconds - (int)TICK_REAL_SECONDS + $alignSettleSeconds + 0.05;
            // Never pick a boundary that has already passed. When the interval
            // is shorter than a tick this means one cycle per tick, not a
            // zero-sleep spin until the next boundary.
            $earliest = max($earliest, microtime(true));
            // realTickSchedule(x) returns the first boundary strictly after x.
            $schedule = GameTime::realTickSchedule((int)ceil($earliest) - 1);
            if ($schedule['next_tick_at'] !== null) {
                $targetSleep = ($schedule['next_tick_at'] + $alignSettleSeconds) - microtime(true);
            }
        } catch (Throwable $alignError) {
            error_log('[tick-worker] tick alignment skipped: ' . $alignError->getMessage());
        }
    }
    $sleepSeconds = $hadError ? max($errorBackoffSeconds, $targetSleep) : max(0.0, $targetSleep);

    if ($sleepSeconds > 0) {
        usleep((int)round($sleepSeconds * 1000000));
    }
}
