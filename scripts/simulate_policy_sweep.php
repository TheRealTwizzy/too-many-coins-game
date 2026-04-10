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
    'season-config' => null,
    'tuning-candidates' => null,
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
    } elseif (str_starts_with($arg, '--season-config=')) {
        $options['season-config'] = substr($arg, 16);
    } elseif (str_starts_with($arg, '--tuning-candidates=')) {
        $options['tuning-candidates'] = substr($arg, 20);
    } elseif ($arg === '--list-scenarios') {
        $options['list-scenarios'] = true;
    } elseif ($arg === '--help') {
        $help = <<<'HELP'
Simulation D — Policy Sweep Runner

Usage:
  php scripts/simulate_policy_sweep.php [OPTIONS]

Options:
  --seed=VALUE              Run identifier (default: phase1-sweep)
  --players-per-archetype=N Players per archetype cohort (default: 5)
  --seasons=N               Season count for Sim C runs (default: 12)
  --simulators=B,C          Comma-separated list of simulators to use (default: B,C)
  --scenario=NAME           Add one named scenario (repeatable)
  --scenarios=A,B,C         Comma-separated list of scenarios
  --include-baseline=0|1    Include a baseline (no-override) run (default: 1)
  --season-config=FILE      JSON file to use as base season config for all runs
  --tuning-candidates=FILE  Phase C tuning_candidates.json to register tuning scenarios
  --output=DIR              Output directory (default: simulation_output/sweep)
  --list-scenarios          Print available scenario names and exit
  --help                    Show this help

Outputs:
  simulation_output/sweep/runs/          Individual run JSON + CSV files
  simulation_output/sweep/policy_sweep_<seed>_ppa<N>_s<N>.json    Sweep manifest

Examples:
  php scripts/simulate_policy_sweep.php --seed=run1 --players-per-archetype=3 --seasons=8 --simulators=B,C --include-baseline=1 --scenarios=hoarder-pressure-v1,boost-payoff-relief-v1
  php scripts/simulate_policy_sweep.php --list-scenarios
  php scripts/simulate_policy_sweep.php --seed=run1 --season-config=simulation_output/exported_season.json --scenarios=hoarder-pressure-v1
HELP;
        echo $help;
        exit(0);
    }
}

if (!empty($options['list-scenarios'])) {
    if ($options['tuning-candidates'] !== null) {
        PolicyScenarioCatalog::registerExtra(
            PolicyScenarioCatalog::loadTuningScenarios($options['tuning-candidates'])
        );
    }
    echo 'Available scenarios:' . PHP_EOL;
    foreach (PolicyScenarioCatalog::all() as $scenario) {
        echo sprintf('- %s: %s', (string)$scenario['name'], (string)$scenario['description']) . PHP_EOL;
    }
    exit(0);
}

if ($options['tuning-candidates'] !== null) {
    PolicyScenarioCatalog::registerExtra(
        PolicyScenarioCatalog::loadTuningScenarios($options['tuning-candidates'])
    );
}

$result = PolicySweepRunner::run([
    'seed' => (string)$options['seed'],
    'players_per_archetype' => (int)$options['players-per-archetype'],
    'season_count' => (int)$options['seasons'],
    'output_dir' => (string)$options['output'],
    'simulators' => (array)$options['simulators'],
    'scenarios' => (array)$options['scenarios'],
    'include_baseline' => (bool)$options['include-baseline'],
    'base_season_config_path' => $options['season-config'],
]);

$manifest = (array)$result['manifest'];
echo 'Policy Sweep Runner' . PHP_EOL;
echo sprintf('Runs: %d | Simulators: %s | Scenarios: %s',
    count((array)$manifest['runs']),
    implode(',', (array)$manifest['config']['simulators']),
    implode(',', (array)$manifest['config']['scenario_names'])
) . PHP_EOL;
echo 'Manifest: ' . (string)$result['manifest_path'] . PHP_EOL;
