<?php
/**
 * Reset economy dry-run utility.
 *
 * Usage:
 *   php tools/reset_economy_dry_run.php --season-id=12
 *   php tools/reset_economy_dry_run.php --all-seasons
 *   php tools/reset_economy_dry_run.php --season-id=12 --apply-migration
 *   php tools/reset_economy_dry_run.php --all-seasons --apply-migration
 *   php tools/reset_economy_dry_run.php --season-id=12 --apply-migration --migration-file=migration_20260402d_reset_season_economy_preserve_auth.sql
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/game_time.php';

function printJson(string $title, array $payload): void {
    echo "\n=== {$title} ===\n";
    echo json_encode($payload, JSON_PRETTY_PRINT) . "\n";
}

function resolveSeasonId(Database $db, ?int $seasonId): ?int {
    if ($seasonId !== null && $seasonId > 0) {
        return $seasonId;
    }

    $row = $db->fetch(
        "SELECT season_id
         FROM seasons
         WHERE status IN ('Active', 'Blackout')
         ORDER BY season_id DESC
         LIMIT 1"
    );
    return $row ? (int)$row['season_id'] : null;
}

function auditSeasonReset(Database $db, int $seasonId): array {
    $playerScope = $db->fetch(
        "SELECT
            COUNT(*) AS total_players,
            SUM(CASE WHEN role = 'Player' THEN 1 ELSE 0 END) AS player_accounts,
            SUM(CASE WHEN role = 'Player' THEN global_stars ELSE 0 END) AS global_stars_total,
            SUM(CASE WHEN joined_season_id = ? AND participation_enabled = 1 THEN 1 ELSE 0 END) AS joined_active,
            SUM(CASE WHEN joined_season_id = ? THEN 1 ELSE 0 END) AS joined_any
         FROM players",
        [$seasonId, $seasonId]
    );

    $economy = $db->fetch(
        "SELECT
            COALESCE(SUM(coins), 0) AS coins,
            COALESCE(SUM(seasonal_stars), 0) AS seasonal_stars,
            COALESCE(SUM(sigils_t1 + sigils_t2 + sigils_t3 + sigils_t4 + sigils_t5 + sigils_t6), 0) AS sigils
         FROM season_participation
         WHERE season_id = ?",
        [$seasonId]
    );

    $tables = [
        'season_participation' => "SELECT COUNT(*) AS c FROM season_participation WHERE season_id = ?",
        'active_boosts' => "SELECT COUNT(*) AS c FROM active_boosts WHERE season_id = ?",
        'active_freezes' => "SELECT COUNT(*) AS c FROM active_freezes WHERE season_id = ?",
        'trades' => "SELECT COUNT(*) AS c FROM trades WHERE season_id = ?",
        'season_vault' => "SELECT COUNT(*) AS c FROM season_vault WHERE season_id = ?",
        'player_season_vault' => "SELECT COUNT(*) AS c FROM player_season_vault WHERE season_id = ?",
        'sigil_drop_log' => "SELECT COUNT(*) AS c FROM sigil_drop_log WHERE season_id = ?",
        'sigil_drop_tracking' => "SELECT COUNT(*) AS c FROM sigil_drop_tracking WHERE season_id = ?",
        'pending_actions' => "SELECT COUNT(*) AS c FROM pending_actions WHERE season_id = ?",
        'economy_ledger' => "SELECT COUNT(*) AS c FROM economy_ledger WHERE season_id = ?",
        'season_chat_messages' => "SELECT COUNT(*) AS c FROM chat_messages WHERE channel_kind = 'SEASON' AND season_id = ?",
        'season_badges' => "SELECT COUNT(*) AS c FROM badges WHERE season_id = ?",
    ];

    $counts = [];
    foreach ($tables as $key => $sql) {
        $row = $db->fetch($sql, [$seasonId]);
        $counts[$key] = (int)($row['c'] ?? 0);
    }

    $activeSeason = $db->fetch(
        "SELECT season_id, start_time, end_time, blackout_time, status
         FROM seasons
         WHERE status IN ('Active', 'Blackout')
         ORDER BY season_id DESC
         LIMIT 1"
    );

    return [
        'target_season_id' => $seasonId,
        'players' => [
            'total_players' => (int)($playerScope['total_players'] ?? 0),
            'player_accounts' => (int)($playerScope['player_accounts'] ?? 0),
            'global_stars_total_players' => (int)($playerScope['global_stars_total'] ?? 0),
            'joined_target_active' => (int)($playerScope['joined_active'] ?? 0),
            'joined_target_any_state' => (int)($playerScope['joined_any'] ?? 0),
        ],
        'season_economy' => [
            'coins_total' => (int)($economy['coins'] ?? 0),
            'seasonal_stars_total' => (int)($economy['seasonal_stars'] ?? 0),
            'sigils_total' => (int)($economy['sigils'] ?? 0),
        ],
        'table_rows' => $counts,
        'latest_active_season' => $activeSeason ? [
            'season_id' => (int)$activeSeason['season_id'],
            'start_time' => (int)$activeSeason['start_time'],
            'end_time' => (int)$activeSeason['end_time'],
            'blackout_time' => (int)$activeSeason['blackout_time'],
            'status' => (string)$activeSeason['status'],
        ] : null,
    ];
}

function evaluatePostChecks(array $audit, int $targetSeasonId): array {
    $checks = [];

    $checks[] = [
        'name' => 'Target season participants detached',
        'ok' => (int)($audit['players']['joined_target_any_state'] ?? 1) === 0,
        'actual' => (int)($audit['players']['joined_target_any_state'] ?? -1),
        'expected' => 0,
    ];

    $checks[] = [
        'name' => 'Global stars reset for player accounts',
        'ok' => (int)($audit['players']['global_stars_total_players'] ?? 1) === 0,
        'actual' => (int)($audit['players']['global_stars_total_players'] ?? -1),
        'expected' => 0,
    ];

    foreach (($audit['table_rows'] ?? []) as $table => $count) {
        $checks[] = [
            'name' => "Target season rows cleared: {$table}",
            'ok' => (int)$count === 0,
            'actual' => (int)$count,
            'expected' => 0,
        ];
    }

    $active = $audit['latest_active_season'] ?? null;
    $checks[] = [
        'name' => 'Active season exists after reset',
        'ok' => is_array($active) && (int)($active['season_id'] ?? 0) > 0,
        'actual' => $active,
        'expected' => 'non-null active season',
    ];

    $checks[] = [
        'name' => 'New active season differs from target',
        'ok' => is_array($active) && (int)($active['season_id'] ?? 0) !== $targetSeasonId,
        'actual' => is_array($active) ? (int)$active['season_id'] : null,
        'expected' => 'season_id != target',
    ];

    return $checks;
}

function auditGlobalReset(Database $db): array {
    $playerScope = $db->fetch(
        "SELECT
            COUNT(*) AS total_players,
            SUM(CASE WHEN role = 'Player' THEN 1 ELSE 0 END) AS player_accounts,
            SUM(CASE WHEN role = 'Player' THEN global_stars ELSE 0 END) AS global_stars_total,
            SUM(CASE WHEN joined_season_id IS NOT NULL AND participation_enabled = 1 THEN 1 ELSE 0 END) AS joined_active,
            SUM(CASE WHEN joined_season_id IS NOT NULL THEN 1 ELSE 0 END) AS joined_any
         FROM players"
    );

    $economy = $db->fetch(
        "SELECT
            COALESCE(SUM(coins), 0) AS coins,
            COALESCE(SUM(seasonal_stars), 0) AS seasonal_stars,
            COALESCE(SUM(sigils_t1 + sigils_t2 + sigils_t3 + sigils_t4 + sigils_t5 + sigils_t6), 0) AS sigils
         FROM season_participation"
    );

    $tables = [
        'seasons' => "SELECT COUNT(*) AS c FROM seasons",
        'season_participation' => "SELECT COUNT(*) AS c FROM season_participation",
        'active_boosts' => "SELECT COUNT(*) AS c FROM active_boosts",
        'active_freezes' => "SELECT COUNT(*) AS c FROM active_freezes",
        'trades' => "SELECT COUNT(*) AS c FROM trades",
        'season_vault' => "SELECT COUNT(*) AS c FROM season_vault",
        'player_season_vault' => "SELECT COUNT(*) AS c FROM player_season_vault",
        'sigil_drop_log' => "SELECT COUNT(*) AS c FROM sigil_drop_log",
        'sigil_drop_tracking' => "SELECT COUNT(*) AS c FROM sigil_drop_tracking",
        'pending_actions_season_scoped' => "SELECT COUNT(*) AS c FROM pending_actions WHERE season_id IS NOT NULL",
        'economy_ledger_season_scoped' => "SELECT COUNT(*) AS c FROM economy_ledger WHERE season_id IS NOT NULL",
        'season_chat_messages' => "SELECT COUNT(*) AS c FROM chat_messages WHERE channel_kind = 'SEASON'",
        'season_badges' => "SELECT COUNT(*) AS c FROM badges WHERE season_id IS NOT NULL",
        'leaderboard_cache' => "SELECT COUNT(*) AS c FROM leaderboard_cache",
    ];

    $counts = [];
    foreach ($tables as $key => $sql) {
        try {
            $row = $db->fetch($sql);
            $counts[$key] = (int)($row['c'] ?? 0);
        } catch (Throwable $e) {
            $counts[$key] = -1;
        }
    }

    $activeSeason = $db->fetch(
        "SELECT season_id, start_time, end_time, blackout_time, status
         FROM seasons
         WHERE status IN ('Active', 'Blackout', 'Scheduled')
         ORDER BY season_id ASC
         LIMIT 1"
    );

    $serverState = $db->fetch("SELECT current_year_seq, global_tick_index, created_at FROM server_state WHERE id = 1");

    return [
        'scope' => 'all_seasons',
        'players' => [
            'total_players' => (int)($playerScope['total_players'] ?? 0),
            'player_accounts' => (int)($playerScope['player_accounts'] ?? 0),
            'global_stars_total_players' => (int)($playerScope['global_stars_total'] ?? 0),
            'joined_active_any_season' => (int)($playerScope['joined_active'] ?? 0),
            'joined_any_season_any_state' => (int)($playerScope['joined_any'] ?? 0),
        ],
        'season_economy' => [
            'coins_total' => (int)($economy['coins'] ?? 0),
            'seasonal_stars_total' => (int)($economy['seasonal_stars'] ?? 0),
            'sigils_total' => (int)($economy['sigils'] ?? 0),
        ],
        'table_rows' => $counts,
        'server_state' => $serverState,
        'first_visible_season' => $activeSeason ? [
            'season_id' => (int)$activeSeason['season_id'],
            'start_time' => (int)$activeSeason['start_time'],
            'end_time' => (int)$activeSeason['end_time'],
            'blackout_time' => (int)$activeSeason['blackout_time'],
            'status' => (string)$activeSeason['status'],
        ] : null,
    ];
}

function evaluateGlobalPostChecks(array $audit): array {
    $checks = [];
    $checks[] = [
        'name' => 'Players detached from seasons',
        'ok' => (int)($audit['players']['joined_any_season_any_state'] ?? 1) === 0,
        'actual' => (int)($audit['players']['joined_any_season_any_state'] ?? -1),
        'expected' => 0,
    ];
    $checks[] = [
        'name' => 'Player global stars reset',
        'ok' => (int)($audit['players']['global_stars_total_players'] ?? 1) === 0,
        'actual' => (int)($audit['players']['global_stars_total_players'] ?? -1),
        'expected' => 0,
    ];

    foreach (($audit['table_rows'] ?? []) as $table => $count) {
        if ($table === 'seasons') {
            continue;
        }
        $checks[] = [
            'name' => "Global season rows cleared: {$table}",
            'ok' => (int)$count === 0,
            'actual' => (int)$count,
            'expected' => 0,
        ];
    }

    $season = $audit['first_visible_season'] ?? null;
    $checks[] = [
        'name' => 'Fresh season exists',
        'ok' => is_array($season) && (int)($season['season_id'] ?? 0) > 0,
        'actual' => $season,
        'expected' => 'season 1 present',
    ];
    $checks[] = [
        'name' => 'Fresh season starts at tick 1',
        'ok' => is_array($season) && (int)($season['start_time'] ?? -1) === 1,
        'actual' => is_array($season) ? (int)$season['start_time'] : null,
        'expected' => 1,
    ];

    $serverState = $audit['server_state'] ?? null;
    $checks[] = [
        'name' => 'Server tick reset to day 0',
        'ok' => is_array($serverState) && (int)($serverState['global_tick_index'] ?? -1) === 0,
        'actual' => is_array($serverState) ? (int)$serverState['global_tick_index'] : null,
        'expected' => 0,
    ];

    return $checks;
}

$options = getopt('', ['season-id::', 'all-seasons', 'apply-migration', 'migration-file::']);
$db = Database::getInstance();

$requestedSeasonId = isset($options['season-id']) ? (int)$options['season-id'] : null;
$allSeasons = array_key_exists('all-seasons', $options);

$seasonId = null;
if (!$allSeasons) {
    $seasonId = resolveSeasonId($db, $requestedSeasonId);
    if ($seasonId === null || $seasonId <= 0) {
        fwrite(STDERR, "Unable to resolve target season. Pass --season-id=<id> or use --all-seasons.\n");
        exit(1);
    }
}

$pre = $allSeasons ? auditGlobalReset($db) : auditSeasonReset($db, (int)$seasonId);
printJson('PRE-CHECK AUDIT', $pre);

$applyMigration = array_key_exists('apply-migration', $options);
if (!$applyMigration) {
    echo "\nDry run complete. No migration applied.\n";
    exit(0);
}

$migrationFile = $options['migration-file'] ?? ($allSeasons
    ? 'migration_20260402f_reset_all_seasons_day0.sql'
    : 'migration_20260402d_reset_season_economy_preserve_auth.sql');
$migrationPath = realpath(__DIR__ . '/..' . DIRECTORY_SEPARATOR . $migrationFile);
if ($migrationPath === false || !is_file($migrationPath)) {
    fwrite(STDERR, "Migration file not found: {$migrationFile}\n");
    exit(1);
}

$sql = file_get_contents($migrationPath);
if ($sql === false || trim($sql) === '') {
    fwrite(STDERR, "Migration file is empty or unreadable: {$migrationPath}\n");
    exit(1);
}

try {
    $db->getConnection()->exec($sql);
    echo "\nApplied migration: {$migrationPath}\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Migration apply failed: {$e->getMessage()}\n");
    exit(1);
}

$post = $allSeasons ? auditGlobalReset($db) : auditSeasonReset($db, (int)$seasonId);
printJson('POST-CHECK AUDIT', $post);

$checks = $allSeasons ? evaluateGlobalPostChecks($post) : evaluatePostChecks($post, (int)$seasonId);
printJson('POST-CHECK ASSERTIONS', $checks);

$failed = array_filter($checks, static fn($c) => empty($c['ok']));
if (!empty($failed)) {
    fwrite(STDERR, "\nPost-checks reported failures. Review output above.\n");
    exit(2);
}

echo "\nPost-checks passed.\n";
exit(0);
