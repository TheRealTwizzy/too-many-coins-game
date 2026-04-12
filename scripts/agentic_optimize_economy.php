<?php

require_once __DIR__ . '/optimization/AgenticOptimization.php';

$options = [
    'seed' => 'agentic-' . gmdate('Ymd-His'),
    'season-config' => null,
    'output' => __DIR__ . '/../simulation_output/current-db/agentic-optimization',
];

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--seed=')) {
        $options['seed'] = substr($arg, 7);
    } elseif (str_starts_with($arg, '--season-config=')) {
        $options['season-config'] = substr($arg, 16);
    } elseif (str_starts_with($arg, '--output=')) {
        $options['output'] = substr($arg, 9);
    } elseif ($arg === '--help') {
        echo <<<'HELP'
Agentic Hierarchical Economy Optimizer

Refactors tuning away from monolithic full-suite brute force into a staged
subsystem-first search with promotion gates:
- Tier 1: cheap local harness screening
- Tier 2: cross-subsystem integration validation
- Tier 3: full-lifecycle acceptance validation

Usage:
  php scripts/agentic_optimize_economy.php [OPTIONS]

Options:
  --seed=VALUE            Run identifier (default: agentic-<UTC timestamp>)
  --season-config=FILE    Optional fallback season config JSON if DB baseline load fails
  --output=DIR            Output root (default: simulation_output/current-db/agentic-optimization)
  --help                  Show this help

Output structure:
  <output>/<run-id>/
    baseline_config_snapshot.json
    decomposition/economy_decomposition_map.json
    decomposition/economy_decomposition_map.md
    audit/rejected_iteration_audit.json
    audit/rejected_iteration_audit.md
    tier1/runs/*.json|*.csv
    tier2/runs/*.json|*.csv
    tier3/runs/*.json|*.csv
    search-memory/run_cache_index.json
    reports/final_integration_report.json
    reports/final_integration_report.md
    best_composed_config.json

HELP;
        exit(0);
    }
}

$repoRoot = realpath(__DIR__ . '/..');
if ($repoRoot === false) {
    fwrite(STDERR, "Failed to resolve repository root.\n");
    exit(1);
}

$outputRoot = trim((string)$options['output'], " \t\n\r\0\x0B\"'");
$isWindowsAbsolute = preg_match('/^[A-Za-z]:/', $outputRoot) === 1;
$isUnixAbsolute = str_starts_with($outputRoot, DIRECTORY_SEPARATOR);

if (!$isWindowsAbsolute && !$isUnixAbsolute) {
    $outputRoot = $repoRoot . DIRECTORY_SEPARATOR . $outputRoot;
}

$outputRoot = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $outputRoot);

try {
    $result = AgenticOptimizationCoordinator::run([
        'repo_root' => $repoRoot,
        'seed' => (string)$options['seed'],
        'season_config' => $options['season-config'],
        'output_root' => $outputRoot,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, 'Agentic optimizer failed: ' . $e->getMessage() . PHP_EOL);
    exit(2);
}

$summary = (array)$result['summary'];
$bestVariant = $summary['best_variant'] ?? null;

echo "Agentic Hierarchical Optimization Complete" . PHP_EOL;
echo "Run ID: " . (string)$result['run_id'] . PHP_EOL;
echo "Run Dir: " . (string)$result['run_dir'] . PHP_EOL;
echo "Decomposition Map: " . (string)$summary['decomposition_artifacts']['json'] . PHP_EOL;
echo "Audit Events: " . (int)$summary['rejected_iteration_audit']['audited_events_count'] . PHP_EOL;
echo "Subsystems optimized: " . count((array)$summary['subsystem_results']) . PHP_EOL;
echo "Accepted subsystem winners: " . count((array)$summary['accepted_subsystem_winners']) . PHP_EOL;
echo "Globally valid full configuration found: " . ($summary['globally_valid_full_configuration_found'] ? 'YES' : 'NO') . PHP_EOL;
if (is_array($bestVariant)) {
    echo "Best variant: " . (string)$bestVariant['variant_id'] . " (delta=" . round((float)$bestVariant['global_score_delta_vs_baseline'], 4) . ")" . PHP_EOL;
}
echo "Best config: " . (string)$summary['best_config_path'] . PHP_EOL;
echo "Final report: " . (string)$result['run_dir'] . DIRECTORY_SEPARATOR . 'reports' . DIRECTORY_SEPARATOR . 'final_integration_report.md' . PHP_EOL;
