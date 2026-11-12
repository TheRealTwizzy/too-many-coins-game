<?php
/**
 * Too Many Coins - Migration Runner
 *
 * Applies every pending migrations/migration_*.sql in filename order and
 * records each one in schema_migrations - the same selection, order and
 * bookkeeping the runtime uses when TMC_AUTO_SQL_MIGRATIONS=true. This is the
 * controlled deployment step the README recommends for production, where
 * auto-apply is off. It needs only PHP and the app's DB_* configuration, so it
 * works inside the container, which ships no mysql client.
 *
 * Deliberately does NOT use Database::getInstance(): that constructor runs the
 * auto-apply as a side effect. This opens its own connection.
 *
 * Usage:
 *   php tools/migrate.php             # apply pending migrations
 *   php tools/migrate.php --dry-run   # list what would run; change nothing
 *
 * Rules, shared with the runtime (includes/migrations.php):
 *   - *_optional.sql and migration_boosts_drops.sql are never applied here.
 *   - A migration recorded as failed is not retried: ship a new file.
 *   - An applied file that was edited is reported as checksum drift, not re-run.
 *
 * Exit: 0 = nothing failed (including nothing to do)
 *       1 = a migration failed this run, or --dry-run found pending/failed ones
 *       2 = could not run at all (no connection, schema not initialised, lock busy)
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/migrations.php';

$dryRun = in_array('--dry-run', $argv, true);

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
        ]
    );
} catch (Throwable $e) {
    fwrite(STDERR, "Cannot connect: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Checked DB_HOST=" . DB_HOST . " DB_PORT=" . DB_PORT . " DB_NAME=" . DB_NAME . " DB_USER=" . DB_USER . "\n");
    exit(2);
}

// Migrations assume the base schema is in place, like the runtime does.
$base = $pdo->query(
    "SELECT COUNT(*) AS c FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'server_state'"
)->fetch();
if ((int)($base['c'] ?? 0) === 0) {
    fwrite(STDERR, "Base schema is not loaded (no server_state table). Run `php init_db.php` first.\n");
    exit(2);
}

$files = tmc_auto_migration_files();
printf("Migration runner  (%s@%s/%s)%s\n", DB_USER, DB_HOST, DB_NAME, $dryRun ? '  [dry run]' : '');
echo str_repeat('-', 66) . "\n";
printf("directory: %s\nconsidered: %d\n\n", tmc_migrations_dir(), count($files));

if ($dryRun) {
    $plan = tmc_classify_migrations($files, tmc_recorded_migrations($pdo));
    if ($plan['pending']) {
        echo "WOULD APPLY, in this order:\n";
        foreach ($plan['pending'] as $n) echo "  + {$n}\n";
    } else {
        echo "WOULD APPLY: nothing - every migration is recorded.\n";
    }
    if ($plan['failed']) {
        echo "\nFAILED - recorded and never retried. Fix with a NEW migration file:\n";
        foreach ($plan['failed'] as $n) echo "  ! {$n}\n";
    }
    if ($plan['drifted']) {
        echo "\nCHECKSUM DRIFT - file edited after being applied (will not re-run):\n";
        foreach ($plan['drifted'] as $n) echo "  ~ {$n}\n";
    }
    echo "\n";
    exit(($plan['pending'] || $plan['failed']) ? 1 : 0);
}

if (!tmc_acquire_migration_lock($pdo, 30)) {
    fwrite(STDERR, "Another process holds the tmc_migrations lock (a redeploy may be applying migrations). Try again shortly.\n");
    exit(2);
}

try {
    tmc_ensure_schema_migrations_table($pdo);
    $result = tmc_apply_migration_files($pdo, $files, static function (string $line): void {
        echo "  ! {$line}\n";
    });
} finally {
    tmc_release_migration_lock($pdo);
}

if ($result['applied']) {
    echo "APPLIED:\n";
    foreach ($result['applied'] as $n) echo "  + {$n}\n";
    echo "\n";
}
if ($result['failed']) {
    echo "FAILED - recorded with status='failed' and will not be retried.\n";
    echo "Fix with a NEW migration file; see README \"Failure-loop guard\":\n";
    foreach ($result['failed'] as $n) echo "  ! {$n}\n";
    echo "\n";
}
printf("applied: %d   failed: %d   already recorded: %d\n",
    count($result['applied']), count($result['failed']), count($result['skipped']));

exit($result['failed'] ? 1 : 0);
