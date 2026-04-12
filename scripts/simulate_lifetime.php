<?php

require_once __DIR__ . '/simulation/SimulationSeason.php';
require_once __DIR__ . '/simulation/SimulationRandom.php';
require_once __DIR__ . '/simulation/Archetypes.php';
require_once __DIR__ . '/simulation/PolicyBehavior.php';
require_once __DIR__ . '/simulation/MetricsCollector.php';
require_once __DIR__ . '/simulation/SimulationPlayer.php';
require_once __DIR__ . '/simulation/SimulationPopulationSeason.php';
require_once __DIR__ . '/simulation/SimulationPopulationLifetime.php';

$options = [
    'seed' => 'phase1-lifetime',
    'players-per-archetype' => 5,
    'seasons' => 12,
    'output' => __DIR__ . '/../simulation_output/lifetime',
    'season-config' => null,
  'archetypes' => null,
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
    } elseif (str_starts_with($arg, '--season-config=')) {
        $options['season-config'] = substr($arg, 16);
    } elseif (str_starts_with($arg, '--archetypes=')) {
      $options['archetypes'] = substr($arg, 13);
    } elseif ($arg === '--help') {
        $help = <<<'HELP'
Simulation C — Lifetime Overlapping-Season Population Simulator

Usage:
  php scripts/simulate_lifetime.php [OPTIONS]

Options:
  --seed=VALUE                Run identifier (default: phase1-lifetime)
  --players-per-archetype=N   Players per archetype cohort (default: 5)
  --seasons=N                 Number of seasons to simulate (default: 12, min 2)
  --output=DIR                Output directory (default: simulation_output/lifetime)
  --season-config=FILE        JSON file with season config overrides (applied to all seasons)
  --archetypes=A,B,C          Optional archetype key subset for focused harness runs
  --help                      Show this help

Env:
  TMC_TICK_REAL_SECONDS=3600  Set this to speed simulation (1 real second = 1 game tick)

Outputs:
  simulation_output/lifetime/lifetime_<seed>_s<N>_ppa<N>.json    Lifetime metrics payload
  simulation_output/lifetime/lifetime_<seed>_s<N>_ppa<N>.csv     Per-player CSV

Export/import workflow:
  php tools/export-season-config.php --output=simulation_output/live_season.json
  $env:TMC_TICK_REAL_SECONDS=3600; php scripts/simulate_lifetime.php --seed=live-test --season-config=simulation_output/live_season.json

Example:
  $env:TMC_TICK_REAL_SECONDS=3600; php scripts/simulate_lifetime.php --seed=run1 --players-per-archetype=5 --seasons=12
HELP;
        echo $help;
        exit(0);
    }
}

$payload = SimulationPopulationLifetime::run(
    (string)$options['seed'],
    (int)$options['players-per-archetype'],
    (int)$options['seasons'],
  $options['season-config'] ? (string)$options['season-config'] : null,
  [
    'archetype_keys' => $options['archetypes'] !== null
      ? array_values(array_filter(array_map('trim', explode(',', (string)$options['archetypes']))))
      : [],
  ]
);

$baseName = 'lifetime_' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string)$options['seed']) . '_s' . (int)$options['seasons'] . '_ppa' . (int)$options['players-per-archetype'];
$jsonPath = MetricsCollector::writeJson($payload, (string)$options['output'], $baseName);
$csvPath = MetricsCollector::writeLifetimeCsv($payload, (string)$options['output'], $baseName);
MetricsCollector::printLifetimeSummary($payload);
echo 'JSON: ' . $jsonPath . PHP_EOL;
if ($csvPath !== null) {
    echo 'CSV: ' . $csvPath . PHP_EOL;
}
