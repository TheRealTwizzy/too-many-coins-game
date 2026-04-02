<?php
/**
 * Run season-only reset (preserve accounts/auth) and verification via PDO.
 *
 * Usage:
 *   php tools/run-season-reset.php
 *   php tools/run-season-reset.php --verify-only
 *   php tools/run-season-reset.php --no-verify
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';

function printUsage(): void {
    echo "Usage:\n";
    echo "  php tools/run-season-reset.php\n";
    echo "  php tools/run-season-reset.php --verify-only\n";
    echo "  php tools/run-season-reset.php --no-verify\n";
}

function connectPdo(): PDO {
    return new PDO(
        "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
        ]
    );
}

function runReset(PDO $pdo): void {
    $sqlPath = __DIR__ . '/reinitialize-seasons-preserve-accounts.sql';
    $sql = file_get_contents($sqlPath);
    if ($sql === false) {
        throw new RuntimeException('Could not read reset script: ' . $sqlPath);
    }

    $pdo->exec($sql);
    echo "[ok] season reset script applied\n";
}

function fetchRows(PDO $pdo, string $sql): array {
    $stmt = $pdo->query($sql);
    if ($stmt === false) {
        return [];
    }
    return $stmt->fetchAll();
}

function runVerification(PDO $pdo): void {
    echo "[verify] season windows\n";
    $seasonWindows = fetchRows(
        $pdo,
        "SELECT season_id, start_time, end_time, blackout_time,
                (end_time - start_time) AS duration_ticks,
                (end_time - blackout_time) AS blackout_ticks
         FROM seasons
         ORDER BY start_time ASC
         LIMIT 6"
    );
    echo json_encode($seasonWindows, JSON_PRETTY_PRINT) . "\n";

    echo "[verify] season cadence\n";
    $cadence = fetchRows(
        $pdo,
        "SELECT s1.season_id, s1.start_time,
                (SELECT MIN(s2.start_time)
                 FROM seasons s2
                 WHERE s2.start_time > s1.start_time) - s1.start_time AS cadence_ticks
         FROM seasons s1
         ORDER BY s1.start_time ASC
         LIMIT 6"
    );
    echo json_encode($cadence, JSON_PRETTY_PRINT) . "\n";

    echo "[verify] computed statuses\n";
    $statusRows = fetchRows(
        $pdo,
        "SELECT s.season_id,
                s.status AS stored_status,
                CASE
                    WHEN ss.global_tick_index < s.start_time THEN 'Scheduled'
                    WHEN ss.global_tick_index >= s.end_time THEN 'Expired'
                    WHEN ss.global_tick_index >= s.blackout_time THEN 'Blackout'
                    ELSE 'Active'
                END AS computed_status,
                ss.global_tick_index AS now_tick
         FROM seasons s
         JOIN server_state ss ON ss.id = 1
         ORDER BY s.start_time ASC
         LIMIT 6"
    );
    echo json_encode($statusRows, JSON_PRETTY_PRINT) . "\n";

    echo "[verify] account/auth preservation counts\n";
    $counts = fetchRows(
        $pdo,
        "SELECT
            (SELECT COUNT(*) FROM players) AS players_count,
            (SELECT COUNT(*) FROM handle_registry) AS handle_registry_count,
            (SELECT COUNT(*) FROM handle_history) AS handle_history_count"
    );
    echo json_encode($counts, JSON_PRETTY_PRINT) . "\n";

    echo "[verify] playability snapshot\n";
    $playability = fetchRows(
        $pdo,
        "SELECT
            (SELECT COUNT(*) FROM seasons WHERE status = 'Active') AS active_season_rows,
            (SELECT COUNT(*) FROM players WHERE joined_season_id IS NOT NULL AND participation_enabled = 1) AS active_players,
            (SELECT COUNT(*) FROM season_participation) AS season_participation_rows"
    );
    echo json_encode($playability, JSON_PRETTY_PRINT) . "\n";

    echo "[ok] verification complete\n";
}

$verifyOnly = false;
$noVerify = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--verify-only') {
        $verifyOnly = true;
    } elseif ($arg === '--no-verify') {
        $noVerify = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        printUsage();
        exit(0);
    } else {
        fwrite(STDERR, 'Unknown argument: ' . $arg . "\n");
        printUsage();
        exit(2);
    }
}

if ($verifyOnly && $noVerify) {
    fwrite(STDERR, "Invalid flags: --verify-only cannot be combined with --no-verify\n");
    exit(2);
}

try {
    if (DB_HOST === '' || DB_NAME === '' || DB_USER === '') {
        throw new RuntimeException('DB_HOST/DB_NAME/DB_USER are not configured in environment.');
    }

    $pdo = connectPdo();

    if (!$verifyOnly) {
        runReset($pdo);
    }

    if (!$noVerify) {
        runVerification($pdo);
    }

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[error] ' . $e->getMessage() . "\n");
    exit(1);
}
