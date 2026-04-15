<?php

require_once __DIR__ . '/simulation/MetricsCollector.php';
require_once __DIR__ . '/simulation/ResultComparator.php';

$options = [
    'seed' => 'phase1-comparator',
    'sweep-manifest' => null,
    'baseline-b' => [],
    'baseline-c' => [],
    'output' => __DIR__ . '/../simulation_output/comparator',
];

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--seed=')) {
        $options['seed'] = substr($arg, 7);
    } elseif (str_starts_with($arg, '--sweep-manifest=')) {
        $options['sweep-manifest'] = substr($arg, 17);
    } elseif (str_starts_with($arg, '--baseline-b=')) {
        $options['baseline-b'][] = substr($arg, 13);
    } elseif (str_starts_with($arg, '--baseline-c=')) {
        $options['baseline-c'][] = substr($arg, 13);
    } elseif (str_starts_with($arg, '--output=')) {
        $options['output'] = substr($arg, 9);
    } elseif ($arg === '--help') {
        $help = <<<'HELP'
Simulation E — Result Comparator

Usage:
  php scripts/compare_simulation_results.php --sweep-manifest=FILE [OPTIONS]

Required:
  --sweep-manifest=FILE   Sweep manifest JSON produced by Simulation D

Options:
  --seed=VALUE            Run identifier for this comparison artifact (default: phase1-comparator)
  --baseline-b=FILE       Additional standalone Sim B baseline JSON (repeatable)
  --baseline-c=FILE       Additional standalone Sim C baseline JSON (repeatable)
  --output=DIR            Output directory (default: simulation_output/comparator)
  --help                  Show this help

Outputs:
  simulation_output/comparator/comparison_<seed>.json    Comparison artifact with
    wins/losses, delta_flags, regression_flags, disposition per scenario
  simulation_output/comparator/rejections/<seed>/<scenario>/rejection_attribution.json
  simulation_output/comparator/rejections/<seed>/<scenario>/rejection_attribution.md

Workflow:
  1. Run Sim D to produce a sweep manifest:
     php scripts/simulate_policy_sweep.php --seed=run1 --scenarios=hoarder-pressure-v1 --include-baseline=1
  2. Compare results:
     php scripts/compare_simulation_results.php --seed=run1 --sweep-manifest=simulation_output/sweep/policy_sweep_run1_ppa5_s12.json

Dispositions:
  candidate for production tuning   wins >= losses+2, no regression flags
  mixed / revisit                    no regression flags but not clearly winning
  reject                             one or more regression flags present

Example:
  php scripts/compare_simulation_results.php --seed=fullpass --sweep-manifest=simulation_output/sweep/policy_sweep_fullpass_ppa3_s8.json
HELP;
        echo $help;
        exit(0);
    }
}

if ($options['sweep-manifest'] === null || $options['sweep-manifest'] === '') {
    throw new InvalidArgumentException('Missing required --sweep-manifest=FILE argument.');
}

$result = ResultComparator::run([
    'seed' => (string)$options['seed'],
    'sweep_manifest' => (string)$options['sweep-manifest'],
    'baseline_b_paths' => (array)$options['baseline-b'],
    'baseline_c_paths' => (array)$options['baseline-c'],
    'output_dir' => (string)$options['output'],
]);

$payload = (array)$result['payload'];
$scenarios = (array)($payload['scenarios'] ?? []);

echo 'Simulation E Result Comparator' . PHP_EOL;
echo sprintf('Scenarios compared: %d', count($scenarios)) . PHP_EOL;
foreach ($scenarios as $scenario) {
    echo sprintf(
        '- %s | wins %d | losses %d | mixed %d | disposition: %s | time %.2fs',
        (string)$scenario['scenario_name'],
        (int)$scenario['wins'],
        (int)$scenario['losses'],
        (int)$scenario['mixed_tradeoffs'],
        (string)$scenario['recommended_disposition'],
        ((int)($scenario['timing_ms'] ?? 0)) / 1000
    ) . PHP_EOL;
    $flags = (array)($scenario['regression_flags'] ?? []);
    if (!empty($flags)) {
        echo '  regression flags: ' . implode(', ', $flags) . PHP_EOL;
    }
    $attribution = (array)($scenario['rejection_attribution'] ?? []);
    $artifactPaths = (array)($attribution['artifact_paths'] ?? []);
    if (!empty($artifactPaths['rejection_attribution_json'])) {
        echo '  rejection attribution: ' . (string)$artifactPaths['rejection_attribution_json'] . PHP_EOL;
    }
}
if (!empty($payload['timing_summary'])) {
    echo sprintf(
        'Comparator duration: %.2fs',
        ((int)$payload['timing_summary']['total_duration_ms']) / 1000
    ) . PHP_EOL;
}
echo 'JSON: ' . (string)$result['json_path'] . PHP_EOL;
