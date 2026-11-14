<?php
/**
 * Too Many Coins - Pending-migration readiness self-check (DB-free)
 *
 * Deployments run with TMC_AUTO_SQL_MIGRATIONS=false, so a build that ships a
 * new migrations/migration_*.sql file runs against the old schema until an
 * operator runs tools/migrate.php. Code that names the new columns then throws
 * and the tick transaction rolls back every cycle, while readiness still said
 * "ready". This check pins the logic that makes that state loud:
 *
 *   - tmc_runtime_migration_state() turns the migration files and the
 *     schema_migrations rows into pending/failed lists, a blocking flag and
 *     diagnosis lines naming every file plus the fix.
 *   - tmc_runtime_migration_log_lines() gives the worker's startup lines,
 *     prefixed "[tick-worker] pending_migrations" / "failed_migrations".
 *
 * No database: the files are temp files and the recorded rows are arrays.
 *
 * Usage:  php tools/pending_migrations_selfcheck.php
 * Exit:   0 = pass, 1 = fail
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/migrations.php';
require_once __DIR__ . '/../includes/runtime_readiness.php';

$pass = 0;
$fail = 0;

function pm_check(string $name, bool $ok, $detail = null): void {
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok   {$name}\n";
        return;
    }
    $fail++;
    echo "  FAIL {$name}" . ($detail !== null ? ' :: ' . json_encode($detail) : '') . "\n";
}

$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tmc_pm_selfcheck_' . getmypid();
@mkdir($dir);
$files = [];
foreach (['migration_20260101_a.sql', 'migration_20260429_pacing.sql', 'migration_20260501_b.sql'] as $name) {
    $path = $dir . DIRECTORY_SEPARATOR . $name;
    file_put_contents($path, "-- {$name}\nSELECT 1;\n");
    $files[$name] = $path;
}
$applied = static function (string $name, string $status = 'applied') use ($files): array {
    return [
        'migration_name' => $name,
        'checksum' => hash_file('sha256', $files[$name]),
        'status' => $status,
        'applied_at' => '2026-01-01 00:00:00',
    ];
};

echo "pending_migrations_selfcheck\n";

// 1. Everything recorded: clean, not blocking, worker stays quiet.
$recorded = [];
foreach (array_keys($files) as $n) {
    $recorded[$n] = $applied($n);
}
$s = tmc_runtime_migration_state($files, $recorded, false);
pm_check('clean: nothing pending', $s['pending'] === [], $s['pending']);
pm_check('clean: nothing failed', $s['failed'] === [], $s['failed']);
pm_check('clean: not blocking', $s['blocking'] === false);
pm_check('clean: no diagnosis', $s['diagnosis'] === [], $s['diagnosis']);
pm_check('clean: no worker log lines', tmc_runtime_migration_log_lines($s) === []);

// 2. Auto-apply off, two files pending: blocking, every file named, fix named.
$recorded = ['migration_20260101_a.sql' => $applied('migration_20260101_a.sql')];
$s = tmc_runtime_migration_state($files, $recorded, false);
pm_check('pending: lists files in apply order',
    $s['pending'] === ['migration_20260429_pacing.sql', 'migration_20260501_b.sql'], $s['pending']);
pm_check('pending: blocking', $s['blocking'] === true);
pm_check('pending: auto_apply reported false', $s['auto_apply'] === false);
$text = implode("\n", $s['diagnosis']);
pm_check('pending: diagnosis names each file',
    strpos($text, 'migration_20260429_pacing.sql') !== false && strpos($text, 'migration_20260501_b.sql') !== false, $text);
pm_check('pending: diagnosis names the fix', strpos($text, 'tools/migrate.php') !== false, $text);
pm_check('pending: diagnosis names auto-apply off', strpos($text, 'TMC_AUTO_SQL_MIGRATIONS=false') !== false, $text);
$lines = tmc_runtime_migration_log_lines($s);
pm_check('pending: one worker log line', count($lines) === 1, $lines);
pm_check('pending: worker line prefix',
    isset($lines[0]) && strpos($lines[0], '[tick-worker] pending_migrations ') === 0, $lines);
pm_check('pending: worker line count and files',
    isset($lines[0]) && strpos($lines[0], 'count=2') !== false
        && strpos($lines[0], 'migration_20260429_pacing.sql,migration_20260501_b.sql') !== false, $lines);

// 3. Schema never migrated (no schema_migrations rows): every file pending.
$s = tmc_runtime_migration_state($files, [], false);
pm_check('empty table: all pending', count($s['pending']) === 3, $s['pending']);
pm_check('empty table: blocking', $s['blocking'] === true);

// 4. A recorded failure blocks even with nothing pending, and is named.
$recorded = [];
foreach (array_keys($files) as $n) {
    $recorded[$n] = $applied($n);
}
$recorded['migration_20260429_pacing.sql'] = $applied('migration_20260429_pacing.sql', 'failed');
$s = tmc_runtime_migration_state($files, $recorded, false);
pm_check('failed: listed', $s['failed'] === ['migration_20260429_pacing.sql'], $s['failed']);
pm_check('failed: blocking', $s['blocking'] === true);
pm_check('failed: diagnosis names file',
    strpos(implode("\n", $s['diagnosis']), 'migration_20260429_pacing.sql') !== false, $s['diagnosis']);
$lines = tmc_runtime_migration_log_lines($s);
pm_check('failed: worker line',
    count($lines) === 1 && strpos($lines[0], '[tick-worker] failed_migrations count=1 files=migration_20260429_pacing.sql') === 0, $lines);

// 5. Auto-apply on but still pending (lock busy / base schema missing): still
//    blocking, and the message does not claim auto-apply is off.
$recorded = ['migration_20260101_a.sql' => $applied('migration_20260101_a.sql')];
$s = tmc_runtime_migration_state($files, $recorded, true);
pm_check('auto on: still blocking', $s['blocking'] === true);
$text = implode("\n", $s['diagnosis']);
pm_check('auto on: no false auto-off claim', strpos($text, 'TMC_AUTO_SQL_MIGRATIONS=false') === false, $text);
pm_check('auto on: names the fix', strpos($text, 'tools/migrate.php') !== false, $text);

// 6. Checksum drift alone is reported but does not block (file is applied).
$recorded = [];
foreach (array_keys($files) as $n) {
    $recorded[$n] = $applied($n);
}
$recorded['migration_20260501_b.sql']['checksum'] = str_repeat('0', 64);
$s = tmc_runtime_migration_state($files, $recorded, false);
pm_check('drift: listed', $s['drifted'] === ['migration_20260501_b.sql'], $s['drifted']);
pm_check('drift: not blocking', $s['blocking'] === false);

foreach ($files as $path) {
    @unlink($path);
}
@rmdir($dir);

echo "\n{$pass}/" . ($pass + $fail) . " passed\n";
exit($fail === 0 ? 0 : 1);
