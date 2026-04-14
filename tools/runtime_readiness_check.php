<?php
/**
 * Runtime readiness check for deployed play-test/live environments.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/game_time.php';
require_once __DIR__ . '/../includes/runtime_readiness.php';

if (!extension_loaded('pdo_mysql')) {
    fwrite(STDERR, "runtime_readiness_check: pdo_mysql extension is not available in this PHP runtime.\n");
    exit(2);
}

$observeSeconds = 0;
$pretty = false;
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--observe-seconds=') === 0) {
        $observeSeconds = max(0, min(60, (int)substr($arg, strlen('--observe-seconds='))));
    } elseif ($arg === '--pretty') {
        $pretty = true;
    }
}

$db = Database::getInstance();
$report = tmc_runtime_readiness_report($db, ['observe_seconds' => $observeSeconds]);

$flags = $pretty ? JSON_PRETTY_PRINT : 0;
echo json_encode($report, $flags) . PHP_EOL;

$exitCode = in_array((string)($report['status'] ?? 'ready'), ['blocked', 'degraded'], true) ? 1 : 0;
exit($exitCode);
