<?php

require_once __DIR__ . '/simulation/SimulationSeason.php';
require_once __DIR__ . '/simulation/SimulationRandom.php';
require_once __DIR__ . '/simulation/Archetypes.php';
require_once __DIR__ . '/simulation/PolicyBehavior.php';
require_once __DIR__ . '/simulation/MetricsCollector.php';
require_once __DIR__ . '/simulation/SimulationPlayer.php';
require_once __DIR__ . '/simulation/SimulationPopulationSeason.php';
require_once __DIR__ . '/simulation/SimulationPopulationLifetime.php';
require_once __DIR__ . '/simulation/PolicyScenarioCatalog.php';
require_once __DIR__ . '/simulation/PolicySweepRunner.php';

$options = [
    'seed' => 'phase1-sweep',
    'players-per-archetype' => 5,
    'seasons' => 12,
    'output' => __DIR__ . '/../simulation_output/sweep',
    'simulators' => ['B', 'C'],
    'scenarios' => [],
    'include-baseline' => true,
    'list-scenarios' => false,
];

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--seed=')) {
        $options['seed'] = substr($arg, 7);
    } elseif (str_starts_with($arg, '--players-per-archetype=')) {
        $options['players-per-archetype'] = max(1, (int)substr($arg, 24));
    } elseif (str_starts_with($arg, '--seasons=')) {
        $options['seasons'] = max(2, (int)substr($arg, 10));
    } elseif (str_starts_with($arg, '--output=')) {
        $options['output'] = substr($arg, 9);
    } elseif (str_starts_with($arg, '--simulators=')) {
        $options['simulators'] = array_map('trim', explode(',', substr($arg, 13)));
    } elseif (str_starts_with($arg, '--scenarios=')) {
        $options['scenarios'] = array_values(array_filter(array_map('trim', explode(',', substr($arg, 12)))));
    } elseif (str_starts_with($arg, '--scenario=')) {
        $options['scenarios'][] = trim(substr($arg, 11));
    } elseif (str_starts_with($arg, '--include-baseline=')) {
        $value = strtolower(trim(substr($arg, 19)));
        $options['include-baseline'] = !in_array($value, ['0', 'false', 'no'], true);
    } elseif ($arg === '--list-scenarios') {
        $options['list-scenarios'] = true;
    } elseif ($arg === '--help') {
        echo 'Usage: php scripts/simulate_policy_sweep.php [--seed=VALUE] [--players-per-archetype=N] [--seasons=N] [--output=DIR] [--simulators=B,C] [--scenario=NAME] [--scenarios=A,B] [--include-baseline=0|1] [--list-scenarios]' . PHP_EOL;
        exit(0);
    }
}

if (!empty($options['list-scenarios'])) {
    echo 'Available scenarios:' . PHP_EOL;
    foreach (PolicyScenarioCatalog::all() as $scenario) {
        echo sprintf('- %s: %s', (string)$scenario['name'], (string)$scenario['description']) . PHP_EOL;
    }
    exit(0);
}

$result = PolicySweepRunner::run([
    'seed' => (string)$options['seed'],
    'players_per_archetype' => (int)$options['players-per-archetype'],
    'season_count' => (int)$options['seasons'],
    'output_dir' => (string)$options['output'],
    'simulators' => (array)$options['simulators'],
    'scenarios' => (array)$options['scenarios'],
    'include_baseline' => (bool)$options['include-baseline'],
]);

$manifest = (array)$result['manifest'];
echo 'Policy Sweep Runner' . PHP_EOL;
echo sprintf('Runs: %d | Simulators: %s | Scenarios: %s',
    count((array)$manifest['runs']),
    implode(',', (array)$manifest['config']['simulators']),
    implode(',', (array)$manifest['config']['scenario_names'])
) . PHP_EOL;
echo 'Manifest: ' . (string)$result['manifest_path'] . PHP_EOL;
