<?php
/**
 * Too Many Coins - Migration Status
 *
 * Read-only. Reports which repo-root migrations are applied, failed, or still
 * pending, using the app's own DB_* configuration - so it works inside the
 * container without a mysql client, which the php:8.3-apache image does not
 * ship.
 *
 * Deliberately does NOT use Database::getInstance(): that constructor calls
 * applyPendingMigrations(), so merely asking for status would apply migrations
 * as a side effect once TMC_AUTO_SQL_MIGRATIONS is on. This opens its own
 * connection and only reads.
 *
 * Usage:
 *   php tools/migration_status.php           # applied / failed / pending
 *   php tools/migration_status.php --verify  # also run post-apply checks
 *
 * Exit: 0 = nothing pending and nothing failed, 1 = pending or failed, 2 = error
 */

require_once __DIR__ . '/../includes/config.php';

$verify = in_array('--verify', $argv, true);

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    fwrite(STDERR, "Cannot connect: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Checked DB_HOST=" . DB_HOST . " DB_PORT=" . DB_PORT . " DB_NAME=" . DB_NAME . " DB_USER=" . DB_USER . "\n");
    exit(2);
}

// The same selection rule the runner uses (Database::getAutoMigrationFiles).
$repoRoot = dirname(__DIR__);
$auto = [];
foreach ((array)glob($repoRoot . DIRECTORY_SEPARATOR . 'migration_*.sql') as $path) {
    $name = basename($path);
    if (preg_match('/_optional\.sql$/i', $name)) continue;
    if (strcasecmp($name, 'migration_boosts_drops.sql') === 0) continue;
    $auto[$name] = hash_file('sha256', $path);
}
uksort($auto, 'strnatcasecmp');

$recorded = [];
try {
    foreach ($pdo->query("SELECT migration_name, checksum, status, applied_at FROM schema_migrations") as $row) {
        $recorded[$row['migration_name']] = $row;
    }
} catch (Throwable $e) {
    echo "schema_migrations does not exist yet - every migration below is pending.\n\n";
}

$pending = [];
$failed = [];
$drifted = [];
foreach ($auto as $name => $checksum) {
    if (!isset($recorded[$name])) { $pending[] = $name; continue; }
    if (($recorded[$name]['status'] ?? '') === 'failed') { $failed[] = $name; }
    if (($recorded[$name]['checksum'] ?? '') !== $checksum) { $drifted[] = $name; }
}

printf("Migration status  (%s@%s/%s)\n", DB_USER, DB_HOST, DB_NAME);
echo str_repeat('-', 66) . "\n";
printf("considered: %d   applied: %d   pending: %d   failed: %d\n\n",
    count($auto), count($auto) - count($pending), count($pending), count($failed));

if ($pending) {
    echo "PENDING - these will run when TMC_AUTO_SQL_MIGRATIONS=true:\n";
    foreach ($pending as $n) echo "  + {$n}\n";
    echo "\n";
} else {
    echo "PENDING: none.\n\n";
}

if ($failed) {
    // A failed migration is recorded and never retried, so it fails silently.
    // The documented remedy is a NEW migration file, never editing the old one.
    echo "FAILED - recorded and never retried. Fix with a NEW migration file:\n";
    foreach ($failed as $n) echo "  ! {$n}\n";
    echo "\n";
}

if ($drifted) {
    echo "CHECKSUM DRIFT - file edited after being applied:\n";
    foreach ($drifted as $n) echo "  ~ {$n}\n";
    echo "\n";
}

if ($verify) {
    echo "Post-apply verification\n" . str_repeat('-', 66) . "\n";
    $checks = [
        'players.global_stars_lifetime exists' => [
            "SELECT COUNT(*) AS v FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'players'
               AND COLUMN_NAME = 'global_stars_lifetime'", 1, '= 1'],
        'sigil families enabled'               => ["SELECT COUNT(*) AS v FROM sigil_family WHERE enabled = 1", 7, '= 7'],
        'seasons with hoarding sink on'        => ["SELECT COUNT(*) AS v FROM seasons WHERE hoarding_sink_enabled = 1", null, '> 0'],
        'seasons on star price model v2'       => ["SELECT COUNT(*) AS v FROM seasons WHERE starprice_model_version >= 2", null, 'new seasons only'],
    ];
    foreach ($checks as $label => [$sql, $expect, $note]) {
        try {
            $v = (int)($pdo->query($sql)->fetch()['v'] ?? 0);
            $ok = $expect === null ? ($v > 0) : ($v === $expect);
            printf("  %-38s %-6s %s\n", $label, $v, ($ok ? 'ok' : "CHECK ({$note})"));
        } catch (Throwable $e) {
            printf("  %-38s %-6s %s\n", $label, '-', 'ERROR: ' . $e->getMessage());
        }
    }
    echo "\n";
}

exit(($pending || $failed) ? 1 : 0);
