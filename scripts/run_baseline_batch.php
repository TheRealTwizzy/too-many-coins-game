<?php
/**
 * Phase A — Baseline Batch Runner
 *
 * Runs the approved baseline simulation matrix against an exported season
 * config and produces a machine-readable batch manifest.
 *
 * Usage:
 *   php scripts/run_baseline_batch.php [OPTIONS]
 *
 * Options:
 *   --season-config=FILE   Path to exported season config JSON (required)
 *   --output=DIR           Output directory (default: simulation_output/current-db/baseline-batch)
 *   --dry-run              Print the run matrix without executing
 *   --skip-existing        Skip runs whose output JSON already exists
 *   --skip-contracts       Skip the contract validation gate (use only if already verified)
 *   --help                 Show this help
 *
 * The batch manifest is written to <output>/batch_manifest.json after all runs.
 */

$options = [
    'season-config' => null,
    'output'        => __DIR__ . '/../simulation_output/current-db/baseline-batch',
    'dry-run'       => false,
    'skip-existing' => false,
    'skip-contracts' => false,
];

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--season-config=')) {
        $options['season-config'] = substr($arg, 16);
    } elseif (str_starts_with($arg, '--output=')) {
        $options['output'] = substr($arg, 9);
    } elseif ($arg === '--dry-run') {
        $options['dry-run'] = true;
    } elseif ($arg === '--skip-existing') {
        $options['skip-existing'] = true;
    } elseif ($arg === '--skip-contracts') {
        $options['skip-contracts'] = true;
    } elseif ($arg === '--help') {
        echo <<<'HELP'
Phase A — Baseline Batch Runner

Runs the approved baseline simulation matrix (15 Sim B + 6 Sim C = 21 runs)
against an exported season config and produces a machine-readable batch manifest.

Usage:
  php scripts/run_baseline_batch.php [OPTIONS]

Options:
  --season-config=FILE   Path to exported season config JSON (required)
  --output=DIR           Output directory (default: simulation_output/current-db/baseline-batch)
  --dry-run              Print the run matrix without executing
  --skip-existing        Skip runs whose output JSON already exists
  --skip-contracts       Skip the contract validation gate
  --help                 Show this help

The contract validation gate (Sim A) runs first. If it fails, the batch aborts.

Output:
  <output>/batch_manifest.json               Batch metadata and per-run results
  <output>/season_<seed>_ppa<N>.json          Sim B outputs
  <output>/lifetime_<seed>_s12_ppa<N>.json    Sim C outputs
  <output>/contract_baseline-econ-contracts.json  Contract gate output

HELP;
        exit(0);
    }
}

// --- Validate prerequisites ---

if ($options['season-config'] === null) {
    fwrite(STDERR, "ERROR: --season-config is required. Export first:\n");
    fwrite(STDERR, "  powershell tools/Invoke-TmcSimulationStep.ps1 -Step export\n");
    exit(1);
}

$seasonConfigPath = realpath($options['season-config']);
if ($seasonConfigPath === false || !is_file($seasonConfigPath)) {
    fwrite(STDERR, "ERROR: Season config not found: {$options['season-config']}\n");
    exit(1);
}

$seasonConfigData = json_decode(file_get_contents($seasonConfigPath), true);
if (!is_array($seasonConfigData) || empty($seasonConfigData)) {
    fwrite(STDERR, "ERROR: Season config is not valid JSON or is empty: {$seasonConfigPath}\n");
    exit(1);
}

$outputDir = $options['output'];
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0777, true);
}

// --- Define the approved baseline matrix ---

$simBSeeds = ['baseline-econ-s1', 'baseline-econ-s2', 'baseline-econ-s3', 'baseline-econ-s4', 'baseline-econ-s5'];
$simBPpa   = [5, 10, 20];

$simCSeeds   = ['baseline-econ-l1', 'baseline-econ-l2', 'baseline-econ-l3'];
$simCPpa     = [5, 10];
$simCSeasons = 12;

$runMatrix = [];

foreach ($simBSeeds as $seed) {
    foreach ($simBPpa as $ppa) {
        $baseName = 'season_' . $seed . '_ppa' . $ppa;
        $runMatrix[] = [
            'simulator'    => 'B',
            'seed'         => $seed,
            'ppa'          => $ppa,
            'seasons'      => 1,
            'base_name'    => $baseName,
            'output_json'  => $outputDir . DIRECTORY_SEPARATOR . $baseName . '.json',
            'script'       => __DIR__ . '/simulate_economy.php',
            'args'         => [
                "--seed={$seed}",
                "--players-per-archetype={$ppa}",
                "--season-config={$seasonConfigPath}",
                "--output={$outputDir}",
            ],
        ];
    }
}

foreach ($simCSeeds as $seed) {
    foreach ($simCPpa as $ppa) {
        $baseName = 'lifetime_' . $seed . '_s' . $simCSeasons . '_ppa' . $ppa;
        $runMatrix[] = [
            'simulator'    => 'C',
            'seed'         => $seed,
            'ppa'          => $ppa,
            'seasons'      => $simCSeasons,
            'base_name'    => $baseName,
            'output_json'  => $outputDir . DIRECTORY_SEPARATOR . $baseName . '.json',
            'script'       => __DIR__ . '/simulate_lifetime.php',
            'args'         => [
                "--seed={$seed}",
                "--players-per-archetype={$ppa}",
                "--seasons={$simCSeasons}",
                "--season-config={$seasonConfigPath}",
                "--output={$outputDir}",
            ],
        ];
    }
}

$totalRuns = count($runMatrix);
echo "Baseline batch: {$totalRuns} runs planned (Sim B: " . (count($simBSeeds) * count($simBPpa)) . ", Sim C: " . (count($simCSeeds) * count($simCPpa)) . ")\n";
echo "Season config: {$seasonConfigPath}\n";
echo "Output dir: {$outputDir}\n\n";

if ($options['dry-run']) {
    echo "=== DRY RUN — no simulations will execute ===\n\n";
    foreach ($runMatrix as $i => $run) {
        $idx = $i + 1;
        echo "[{$idx}/{$totalRuns}] Sim {$run['simulator']} | seed={$run['seed']} ppa={$run['ppa']} seasons={$run['seasons']}\n";
        echo "  output: {$run['output_json']}\n";
        $exists = is_file($run['output_json']) ? 'EXISTS' : 'MISSING';
        echo "  status: {$exists}\n\n";
    }
    exit(0);
}

// --- Contract validation gate (A3) ---

if (!$options['skip-contracts']) {
    echo "=== Contract Validation Gate (Sim A) ===\n";
    $contractScript = __DIR__ . '/simulate_contracts.php';
    $contractSeed   = 'baseline-econ-contracts';
    $contractOutput = $outputDir;
    $contractArgs   = [
        $contractScript,
        "--seed={$contractSeed}",
        "--output={$contractOutput}",
    ];

    $contractStart = microtime(true);
    $contractCmd = PHP_BINARY . ' ' . implode(' ', array_map('escapeshellarg', $contractArgs));
    passthru($contractCmd, $contractExitCode);
    $contractEnd = microtime(true);

    if ($contractExitCode !== 0) {
        fwrite(STDERR, "\nERROR: Contract validation gate FAILED (exit code {$contractExitCode}).\n");
        fwrite(STDERR, "Baseline batch is INVALID. Fix contract failures before proceeding.\n");
        exit(2);
    }

    // Verify the contract output indicates all passed
    $contractJsonPath = $contractOutput . DIRECTORY_SEPARATOR . 'contract_' . $contractSeed . '.json';
    if (is_file($contractJsonPath)) {
        $contractPayload = json_decode(file_get_contents($contractJsonPath), true);
        if (is_array($contractPayload) && isset($contractPayload['summary']['all_passed'])) {
            if (!$contractPayload['summary']['all_passed']) {
                $failed = (int)($contractPayload['summary']['failed'] ?? 0);
                fwrite(STDERR, "\nERROR: Contract validation gate reports {$failed} failed check(s).\n");
                fwrite(STDERR, "Baseline batch is INVALID. Fix contract failures before proceeding.\n");
                exit(2);
            }
        }
    }

    echo sprintf("\nContract gate PASSED (%.1fs)\n\n", $contractEnd - $contractStart);
} else {
    echo "=== Contract validation SKIPPED (--skip-contracts) ===\n\n";
}

// --- Execute baseline runs ---

echo "=== Executing Baseline Batch ===\n\n";

$manifest = [
    'schema_version'   => 'tmc-baseline-batch.v1',
    'generated_at'     => gmdate('c'),
    'season_config'    => $seasonConfigPath,
    'season_config_id' => $seasonConfigData['season_id'] ?? null,
    'total_planned'    => $totalRuns,
    'matrix'           => [
        'sim_b_seeds'   => $simBSeeds,
        'sim_b_ppa'     => $simBPpa,
        'sim_c_seeds'   => $simCSeeds,
        'sim_c_ppa'     => $simCPpa,
        'sim_c_seasons' => $simCSeasons,
    ],
    'contract_gate'    => $options['skip-contracts'] ? 'skipped' : 'passed',
    'runs'             => [],
];

$completed = 0;
$skipped   = 0;
$failed    = 0;
$batchStart = microtime(true);

foreach ($runMatrix as $i => $run) {
    $idx = $i + 1;
    echo "[{$idx}/{$totalRuns}] Sim {$run['simulator']} | seed={$run['seed']} ppa={$run['ppa']} seasons={$run['seasons']}\n";

    $runEntry = [
        'simulator'       => $run['simulator'],
        'seed'            => $run['seed'],
        'ppa'             => $run['ppa'],
        'seasons'         => $run['seasons'],
        'season_config'   => $seasonConfigPath,
        'output_json'     => $run['output_json'],
        'base_name'       => $run['base_name'],
    ];

    // Skip-existing check
    if ($options['skip-existing'] && is_file($run['output_json'])) {
        echo "  SKIPPED (output exists)\n\n";
        $runEntry['status']    = 'skipped';
        $runEntry['reason']    = 'output_exists';
        $runEntry['started_at']  = null;
        $runEntry['finished_at'] = null;
        $runEntry['exit_code']   = null;
        $manifest['runs'][] = $runEntry;
        $skipped++;
        continue;
    }

    // Set TMC_TICK_REAL_SECONDS for Sim C
    $envPrefix = '';
    if ($run['simulator'] === 'C') {
        putenv('TMC_TICK_REAL_SECONDS=3600');
        $_ENV['TMC_TICK_REAL_SECONDS'] = '3600';
    }

    $runStart = microtime(true);
    $runEntry['started_at'] = gmdate('c');

    $cmdArgs = array_merge([$run['script']], $run['args']);
    $cmd = PHP_BINARY . ' ' . implode(' ', array_map('escapeshellarg', $cmdArgs));
    passthru($cmd, $exitCode);

    $runEnd = microtime(true);
    $runEntry['finished_at']   = gmdate('c');
    $runEntry['exit_code']     = $exitCode;
    $runEntry['duration_secs'] = round($runEnd - $runStart, 2);

    // Clear TMC_TICK_REAL_SECONDS after Sim C
    if ($run['simulator'] === 'C') {
        putenv('TMC_TICK_REAL_SECONDS');
        unset($_ENV['TMC_TICK_REAL_SECONDS']);
    }

    if ($exitCode !== 0) {
        echo "  FAILED (exit code {$exitCode})\n\n";
        $runEntry['status'] = 'failed';
        $failed++;
    } elseif (!is_file($run['output_json'])) {
        echo "  FAILED (output not produced)\n\n";
        $runEntry['status'] = 'failed';
        $runEntry['reason'] = 'output_missing';
        $failed++;
    } else {
        echo sprintf("  OK (%.1fs)\n\n", $runEntry['duration_secs']);
        $runEntry['status'] = 'completed';
        $completed++;
    }

    $manifest['runs'][] = $runEntry;
}

$batchEnd = microtime(true);

$manifest['summary'] = [
    'completed'          => $completed,
    'skipped'            => $skipped,
    'failed'             => $failed,
    'total_duration_secs' => round($batchEnd - $batchStart, 2),
    'batch_valid'        => $failed === 0,
];

// --- Write manifest ---

$manifestPath = $outputDir . DIRECTORY_SEPARATOR . 'batch_manifest.json';
file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "=== Baseline Batch Complete ===\n";
echo "Completed: {$completed}  Skipped: {$skipped}  Failed: {$failed}\n";
echo sprintf("Total time: %.1fs\n", $batchEnd - $batchStart);
echo "Manifest: {$manifestPath}\n";

if ($failed > 0) {
    fwrite(STDERR, "\nWARNING: {$failed} run(s) failed. Batch is NOT valid for analysis.\n");
    exit(3);
}

echo "\nBatch is valid. Proceed with: php scripts/analyze_baseline.php --manifest={$manifestPath}\n";
