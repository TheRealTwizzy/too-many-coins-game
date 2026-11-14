<?php
/**
 * Too Many Coins - Runtime readiness checks
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/migrations.php';
require_once __DIR__ . '/game_time.php';

function tmc_runtime_table_exists(Database $db, string $tableName): bool
{
    static $cache = [];

    if (isset($cache[$tableName])) {
        return $cache[$tableName];
    }

    $row = $db->fetch(
        "SELECT COUNT(*) AS c
         FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?",
        [$tableName]
    );

    $exists = ((int)($row['c'] ?? 0)) > 0;
    if ($exists) {
        $cache[$tableName] = true;
    } else {
        unset($cache[$tableName]);
    }

    return $exists;
}

function tmc_runtime_required_tick_tables(): array
{
    return [
        'boost_catalog',
        'active_boosts',
        'active_freezes',
        'sigil_drop_log',
        'sigil_drop_tracking',
        'player_notifications',
    ];
}

/**
 * Pending/failed migration state, without touching the database.
 *
 * $files is tmc_auto_migration_files() (name => path, apply order) and
 * $recorded is tmc_recorded_migrations(). Any pending or failed file blocks:
 * with auto-apply off, code that names a new column throws until an operator
 * runs tools/migrate.php, and the tick transaction rolls back every cycle.
 * With auto-apply on, a file still pending after Database connected means the
 * runner could not apply it (lock busy, base schema missing), which is just as
 * broken. Checksum drift is reported but does not block: the file did apply.
 */
function tmc_runtime_migration_state(array $files, array $recorded, bool $autoApply): array
{
    $plan = tmc_classify_migrations($files, $recorded);
    $diagnosis = [];

    if (!empty($plan['pending'])) {
        $diagnosis[] = ($autoApply
                ? 'Schema migrations still pending although TMC_AUTO_SQL_MIGRATIONS is on (lock busy or base schema missing): '
                : 'Schema migrations pending and TMC_AUTO_SQL_MIGRATIONS=false, so they will not apply on their own: ')
            . implode(', ', $plan['pending'])
            . '. Run php tools/migrate.php, then restart web and worker.';
    }
    if (!empty($plan['failed'])) {
        $diagnosis[] = 'Schema migrations recorded as failed (never retried; ship a new migration file): '
            . implode(', ', $plan['failed']) . '.';
    }

    return [
        'auto_apply' => $autoApply,
        'pending' => $plan['pending'],
        'failed' => $plan['failed'],
        'drifted' => $plan['drifted'],
        'blocking' => !empty($plan['pending']) || !empty($plan['failed']),
        'diagnosis' => $diagnosis,
    ];
}

/**
 * Reads the live migration state through an open connection.
 */
function tmc_runtime_load_migration_state(PDO $pdo): array
{
    return tmc_runtime_migration_state(
        tmc_auto_migration_files(),
        tmc_recorded_migrations($pdo),
        defined('TMC_AUTO_SQL_MIGRATIONS') && TMC_AUTO_SQL_MIGRATIONS
    );
}

/**
 * Startup log lines for the tick worker; empty when nothing is wrong.
 */
function tmc_runtime_migration_log_lines(array $state): array
{
    $lines = [];
    if (!empty($state['pending'])) {
        $lines[] = '[tick-worker] pending_migrations count=' . count($state['pending'])
            . ' files=' . implode(',', $state['pending'])
            . ' auto_apply=' . (!empty($state['auto_apply']) ? 'true' : 'false')
            . ' fix="php tools/migrate.php, then restart web and worker"';
    }
    if (!empty($state['failed'])) {
        $lines[] = '[tick-worker] failed_migrations count=' . count($state['failed'])
            . ' files=' . implode(',', $state['failed'])
            . ' fix="ship a new migration file; failed ones are never retried"';
    }
    return $lines;
}

function tmc_runtime_tick_path_report(): array
{
    $tickOnRequest = (bool)TMC_TICK_ON_REQUEST;
    $tickSecretConfigured = trim((string)TMC_TICK_SECRET) !== '';
    $workerIntervalSeconds = getenv('TMC_WORKER_INTERVAL_SECONDS');
    $workerIntervalSeconds = ($workerIntervalSeconds === false || $workerIntervalSeconds === '')
        ? null
        : max(1, (int)$workerIntervalSeconds);
    $workerConfiguredInThisProcess = $workerIntervalSeconds !== null;

    $configuredPaths = [];
    if ($workerConfiguredInThisProcess) {
        $configuredPaths[] = 'worker';
    }
    if ($tickSecretConfigured) {
        $configuredPaths[] = 'scheduler';
    }
    if ($tickOnRequest) {
        $configuredPaths[] = 'request_fallback';
    }

    return [
        'tick_on_request' => $tickOnRequest,
        'tick_secret_configured' => $tickSecretConfigured,
        'worker_interval_seconds' => $workerIntervalSeconds,
        'worker_configured_in_this_process' => $workerConfiguredInThisProcess,
        'configured_paths_in_this_process' => $configuredPaths,
        'has_provable_authoritative_path_in_this_process' => !empty($configuredPaths),
        'deployment_warning' => (!$tickOnRequest && !$workerConfiguredInThisProcess && !$tickSecretConfigured)
            ? 'No authoritative tick path is provable from current process env. Validate the worker service separately if this is a web container.'
            : null,
    ];
}

function tmc_runtime_readiness_report(Database $db, array $options = []): array
{
    $observeSeconds = max(0, (int)($options['observe_seconds'] ?? 0));
    $gameTime = GameTime::now();
    $requiredTables = tmc_runtime_required_tick_tables();
    $missingTables = [];
    foreach ($requiredTables as $tableName) {
        if (!tmc_runtime_table_exists($db, $tableName)) {
            $missingTables[] = $tableName;
        }
    }

    $migrationState = tmc_runtime_load_migration_state($db->getConnection());

    $failedMigrations = [];
    if (tmc_runtime_table_exists($db, 'schema_migrations')) {
        $failedMigrations = $db->fetchAll(
            "SELECT migration_name, checksum, status, applied_at
             FROM schema_migrations
             WHERE status = 'failed'
             ORDER BY applied_at ASC, migration_name ASC"
        );
    }

    $serverState = $db->fetch("SELECT * FROM server_state WHERE id = 1");
    $seasons = $db->fetchAll("SELECT * FROM seasons ORDER BY start_time ASC");

    $seasonSummaries = [];
    $joinableSeasonCount = 0;
    $activeSeasonCount = 0;
    $blackoutSeasonCount = 0;
    $expiredSeasonCount = 0;
    $activeSeasonWithParticipantsCount = 0;
    $maxSeasonLag = 0;

    foreach ($seasons as $season) {
        $computedStatus = GameTime::getSeasonStatus($season, $gameTime);
        $seasonId = (int)$season['season_id'];
        $participantRow = $db->fetch(
            "SELECT COUNT(*) AS c
             FROM players
             WHERE joined_season_id = ? AND participation_enabled = 1",
            [$seasonId]
        );
        $participants = (int)($participantRow['c'] ?? 0);
        $lastProcessedTick = (int)($season['last_processed_tick'] ?? 0);
        $lagTicks = max(0, $gameTime - $lastProcessedTick);
        $maxSeasonLag = max($maxSeasonLag, $lagTicks);

        if ($computedStatus === 'Active') {
            $activeSeasonCount++;
            if ($participants > 0) {
                $activeSeasonWithParticipantsCount++;
            }
        } elseif ($computedStatus === 'Blackout') {
            $blackoutSeasonCount++;
        } elseif ($computedStatus === 'Expired') {
            $expiredSeasonCount++;
        }

        $isJoinable = ($computedStatus === 'Active' && $gameTime < (int)$season['blackout_time'] && $gameTime < ((int)$season['end_time'] - 1));
        if ($isJoinable) {
            $joinableSeasonCount++;
        }

        $seasonSummaries[] = [
            'season_id' => $seasonId,
            'stored_status' => (string)($season['status'] ?? ''),
            'computed_status' => $computedStatus,
            'participants' => $participants,
            'joinable' => $isJoinable,
            'start_time' => (int)$season['start_time'],
            'blackout_time' => (int)$season['blackout_time'],
            'end_time' => (int)$season['end_time'],
            'last_processed_tick' => $lastProcessedTick,
            'tick_lag' => $lagTicks,
            'expiration_finalized' => (bool)($season['expiration_finalized'] ?? false),
        ];
    }

    $diagnosis = [];
    $status = 'ready';

    if (!empty($missingTables)) {
        $status = 'blocked';
        $diagnosis[] = 'Missing required tick-runtime tables: ' . implode(', ', $missingTables);
    }
    if ($migrationState['blocking']) {
        $status = 'blocked';
        foreach ($migrationState['diagnosis'] as $line) {
            $diagnosis[] = $line;
        }
    }
    if (!empty($failedMigrations) && empty($migrationState['failed'])) {
        // Failed rows for files no longer shipped in migrations/.
        $status = 'blocked';
        $diagnosis[] = 'Schema migrations recorded as failed: '
            . implode(', ', array_map(static fn($row) => (string)($row['migration_name'] ?? ''), $failedMigrations)) . '.';
    }
    if ($activeSeasonCount === 0 && $blackoutSeasonCount > 0) {
        $diagnosis[] = 'Current visible season state is blackout; no accrual or sigil drops is expected.';
        if ($status === 'ready') {
            $status = 'expected_no_progression';
        }
    } elseif ($activeSeasonCount === 0 && $expiredSeasonCount > 0) {
        $diagnosis[] = 'Visible seasons are expired; progression requires a reset or fresh season bootstrap.';
        if ($status === 'ready') {
            $status = 'expected_no_progression';
        }
    }
    if ($activeSeasonCount > 0 && $activeSeasonWithParticipantsCount === 0) {
        $diagnosis[] = 'There is at least one active season but zero joined participants; empty seasons intentionally produce no accrual or drops.';
        if ($status === 'ready') {
            $status = 'expected_no_progression';
        }
    }
    if ($joinableSeasonCount === 0) {
        $diagnosis[] = 'No currently joinable active season was found.';
    }

    $observation = null;
    if ($observeSeconds > 0) {
        $beforeServerState = $serverState;
        $beforeSeasonTicks = [];
        foreach ($seasonSummaries as $seasonSummary) {
            $beforeSeasonTicks[$seasonSummary['season_id']] = (int)$seasonSummary['last_processed_tick'];
        }

        sleep($observeSeconds);

        Database::resetInstance();
        $dbObserved = Database::getInstance();
        $afterServerState = $dbObserved->fetch("SELECT * FROM server_state WHERE id = 1");
        $afterSeasons = $dbObserved->fetchAll("SELECT season_id, last_processed_tick FROM seasons");
        $advancedSeasons = [];
        foreach ($afterSeasons as $row) {
            $seasonId = (int)$row['season_id'];
            $afterTick = (int)($row['last_processed_tick'] ?? 0);
            $beforeTick = (int)($beforeSeasonTicks[$seasonId] ?? 0);
            if ($afterTick > $beforeTick) {
                $advancedSeasons[] = [
                    'season_id' => $seasonId,
                    'before' => $beforeTick,
                    'after' => $afterTick,
                    'delta' => $afterTick - $beforeTick,
                ];
            }
        }

        $serverHeartbeatAdvanced = false;
        if ($beforeServerState && $afterServerState) {
            $beforeHeartbeat = (string)($beforeServerState['last_tick_processed_at'] ?? '');
            $afterHeartbeat = (string)($afterServerState['last_tick_processed_at'] ?? '');
            $serverHeartbeatAdvanced = ($beforeHeartbeat !== '' && $afterHeartbeat !== '' && $beforeHeartbeat !== $afterHeartbeat);
        }

        $observation = [
            'observe_seconds' => $observeSeconds,
            'server_global_tick_before' => (int)($beforeServerState['global_tick_index'] ?? 0),
            'server_global_tick_after' => (int)($afterServerState['global_tick_index'] ?? 0),
            'server_heartbeat_advanced' => $serverHeartbeatAdvanced,
            'advanced_seasons' => $advancedSeasons,
        ];

        if ($serverHeartbeatAdvanced && empty($advancedSeasons)) {
            $diagnosis[] = 'Server heartbeat advanced during observation, but no season last_processed_tick advanced.';
            if ($status === 'ready') {
                $status = 'degraded';
            }
        } elseif (!$serverHeartbeatAdvanced && $activeSeasonCount > 0 && $activeSeasonWithParticipantsCount > 0) {
            $diagnosis[] = 'No tick heartbeat advanced during observation while active seasons with participants exist.';
            if ($status === 'ready') {
                $status = 'blocked';
            }
        }
    }

    return [
        'status' => $status,
        'generated_at' => gmdate('c'),
        'game_time' => $gameTime,
        'tick_path' => tmc_runtime_tick_path_report(),
        'timing' => get_timing_diagnostics(),
        'schema' => [
            'required_tick_tables' => $requiredTables,
            'missing_tick_tables' => $missingTables,
            'failed_migrations' => $failedMigrations,
            'auto_sql_migrations' => $migrationState['auto_apply'],
            'pending_migrations' => $migrationState['pending'],
            'drifted_migrations' => $migrationState['drifted'],
        ],
        'server_state' => $serverState ? [
            'global_tick_index' => (int)($serverState['global_tick_index'] ?? 0),
            'last_tick_processed_at' => $serverState['last_tick_processed_at'] ?? null,
            'created_at' => $serverState['created_at'] ?? null,
        ] : null,
        'season_state' => [
            'joinable_season_count' => $joinableSeasonCount,
            'active_season_count' => $activeSeasonCount,
            'blackout_season_count' => $blackoutSeasonCount,
            'expired_season_count' => $expiredSeasonCount,
            'active_season_with_participants_count' => $activeSeasonWithParticipantsCount,
            'max_tick_lag' => $maxSeasonLag,
            'seasons' => $seasonSummaries,
        ],
        'diagnosis' => $diagnosis,
        'observation' => $observation,
    ];
}
