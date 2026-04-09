<?php
/**
 * Too Many Coins — Fresh Lifecycle Simulation CLI
 *
 * Milestone 1: Safety boundary + disposable DB bootstrap/reset/teardown.
 * Milestone 2: Simulation clock adapter + explicit tick-driven processing.
 * Milestone 3A: Lifecycle runner shell + orchestration entrypoint.
 *
 * Usage:
 *   php scripts/simulate_fresh_lifecycle.php --action=bootstrap [--drop-first]
 *   php scripts/simulate_fresh_lifecycle.php --action=teardown
 *   php scripts/simulate_fresh_lifecycle.php --action=status
 *   php scripts/simulate_fresh_lifecycle.php --action=tick --game-tick=<N>
 *   php scripts/simulate_fresh_lifecycle.php --action=lifecycle [--drop-first] [--seed=N] [--cohort-size=N]
 *
 * Required environment variables:
 *   DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
 *   TMC_SIMULATION_MODE=fresh-run
 *   TMC_FRESH_RUN_DESTRUCTIVE_RESET=1  (for destructive operations)
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI only');
}

require_once __DIR__ . '/simulation/FreshRunSafety.php';
require_once __DIR__ . '/simulation/FreshRunBootstrap.php';
require_once __DIR__ . '/simulation/FreshLifecycleRunner.php';

// --- Parse CLI arguments ---
$options = getopt('', [
    'action:',
    'drop-first',
    'help',
    'db-host:',
    'db-port:',
    'db-name:',
    'db-user:',
    'db-pass:',
    'game-tick:',
    'seed:',
    'cohort-size:',
]);

if (isset($options['help']) || !isset($options['action'])) {
    fwrite(STDOUT, <<<HELP
Fresh Lifecycle Simulation CLI (Milestone 1-3A: Bootstrap/Safety/Tick/Lifecycle)

Usage:
  php scripts/simulate_fresh_lifecycle.php --action=bootstrap [--drop-first]
  php scripts/simulate_fresh_lifecycle.php --action=teardown
  php scripts/simulate_fresh_lifecycle.php --action=status
  php scripts/simulate_fresh_lifecycle.php --action=tick --game-tick=<N>
  php scripts/simulate_fresh_lifecycle.php --action=lifecycle [--drop-first] [--seed=N] [--cohort-size=N]

Actions:
  bootstrap   Create disposable DB from schema + seed + migrations
  teardown    Drop disposable DB for clean rerun
  status      Check if disposable DB exists
  tick        Process a single explicit game tick through TickEngine
  lifecycle   Run fresh lifecycle orchestration shell (Milestone 3A: skeleton only)

Required environment:
  TMC_SIMULATION_MODE=fresh-run
  TMC_FRESH_RUN_DESTRUCTIVE_RESET=1  (for bootstrap --drop-first, teardown, and lifecycle --drop-first)
  DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS

DB name must match a safe prefix: tmc_sim_*, tmc_fresh_*, tmc_test_sim_*
DB host must be local: 127.0.0.1, localhost, or ::1

Options:
  --db-host=HOST       Override DB_HOST env var
  --db-port=PORT       Override DB_PORT env var
  --db-name=NAME       Override DB_NAME env var
  --db-user=USER       Override DB_USER env var
  --db-pass=PASS       Override DB_PASS env var
  --drop-first         Drop existing DB before bootstrap (requires destructive-reset flag)
  --game-tick=N        Explicit game tick to process (required for --action=tick)
  --seed=N             Deterministic seed for lifecycle run (default: 42)
  --cohort-size=N      Players per archetype (default: 100)
  --help               Show this help

HELP
    );
    exit(isset($options['help']) ? 0 : 1);
}

$action = $options['action'];
$dropFirst = isset($options['drop-first']);

// Resolve DB coordinates: CLI overrides > env vars
$dbHost = $options['db-host'] ?? (getenv('DB_HOST') ?: '');
$dbPort = $options['db-port'] ?? (getenv('DB_PORT') ?: '');
$dbName = $options['db-name'] ?? (getenv('DB_NAME') ?: '');
$dbUser = $options['db-user'] ?? (getenv('DB_USER') ?: '');
$dbPass = $options['db-pass'] ?? (getenv('DB_PASS') ?: '');

$bootstrap = new FreshRunBootstrap($dbHost, $dbPort, $dbName, $dbUser, $dbPass);

switch ($action) {
    case 'bootstrap':
        fwrite(STDOUT, "Fresh-run bootstrap: $dbName on $dbHost:$dbPort\n");
        $result = $bootstrap->bootstrap($dropFirst);
        fwrite(STDOUT, "Status: {$result['status']}\n");
        foreach ($result['steps'] as $step) {
            fwrite(STDOUT, "  • $step\n");
        }
        if ($result['status'] === 'bootstrapped') {
            fwrite(STDOUT, "Bootstrap complete.\n");
        }
        break;

    case 'teardown':
        fwrite(STDOUT, "Fresh-run teardown: $dbName on $dbHost:$dbPort\n");
        $result = $bootstrap->teardown();
        fwrite(STDOUT, "Status: {$result['status']}\n");
        foreach ($result['steps'] as $step) {
            fwrite(STDOUT, "  • $step\n");
        }
        break;

    case 'status':
        fwrite(STDOUT, "Fresh-run status check: $dbName on $dbHost:$dbPort\n");
        $exists = $bootstrap->exists();
        fwrite(STDOUT, "Database exists: " . ($exists ? 'yes' : 'no') . "\n");
        break;

    case 'tick':
        if (!isset($options['game-tick'])) {
            fwrite(STDERR, "Error: --game-tick=<N> is required for --action=tick\n");
            exit(1);
        }
        $gameTick = (int)$options['game-tick'];
        if ($gameTick < 0) {
            fwrite(STDERR, "Error: --game-tick must be a non-negative integer\n");
            exit(1);
        }

        // Safety: verify simulation mode before loading production includes
        $simMode = getenv('TMC_SIMULATION_MODE');
        if ($simMode !== 'fresh-run') {
            fwrite(STDERR, "Error: TMC_SIMULATION_MODE must be 'fresh-run' for tick action\n");
            exit(1);
        }

        // Load production runtime (config, DB, GameTime, TickEngine)
        require_once __DIR__ . '/../includes/tick_engine.php';

        fwrite(STDOUT, "Processing tick $gameTick via TickEngine::processTickAt()\n");
        $tickStart = microtime(true);
        TickEngine::processTickAt($gameTick);
        $durationMs = round((microtime(true) - $tickStart) * 1000, 1);
        fwrite(STDOUT, "Tick $gameTick processed in {$durationMs}ms\n");

        // Clear simulation clock after explicit tick action completes
        GameTime::clearSimulationTick();
        break;

    case 'lifecycle':
        $seed = isset($options['seed']) ? (int)$options['seed'] : 42;
        $cohortSize = isset($options['cohort-size']) ? (int)$options['cohort-size'] : 100;

        $runner = new FreshLifecycleRunner([
            'db_host'     => $dbHost,
            'db_port'     => $dbPort,
            'db_name'     => $dbName,
            'db_user'     => $dbUser,
            'db_pass'     => $dbPass,
            'seed'        => $seed,
            'cohort_size' => $cohortSize,
            'drop_first'  => $dropFirst,
        ]);

        fwrite(STDOUT, "Fresh lifecycle run: $dbName on $dbHost:$dbPort (seed=$seed, cohort_size=$cohortSize)\n");

        // Phase 1: prepare (validate + bootstrap)
        $prepResult = $runner->prepare();
        fwrite(STDOUT, "Prepare status: {$prepResult['status']}\n");
        foreach ($prepResult['steps'] as $step) {
            fwrite(STDOUT, "  • $step\n");
        }
        if ($runner->getState() === FreshLifecycleRunner::STATE_FAILED) {
            fwrite(STDERR, "Lifecycle preparation failed. Aborting.\n");
            exit(1);
        }

        // Phase 2: run (Milestone 3A: skeleton only)
        $runResult = $runner->run();
        fwrite(STDOUT, "Run status: {$runResult['status']}\n");
        fwrite(STDOUT, "  {$runResult['message']}\n");
        if (!empty($runResult['unmodeled_mechanics'])) {
            fwrite(STDOUT, "  Unmodeled mechanics: " . implode(', ', $runResult['unmodeled_mechanics']) . "\n");
        }

        fwrite(STDOUT, "Runner state: {$runner->getState()}\n");
        break;

    default:
        fwrite(STDERR, "Unknown action: $action\n");
        fwrite(STDERR, "Use --help for usage information.\n");
        exit(1);
}
