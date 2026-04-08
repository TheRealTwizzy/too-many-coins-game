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
    } elseif ($arg === '--help') {
        echo 'Usage: php scripts/simulate_lifetime.php [--seed=VALUE] [--players-per-archetype=N] [--seasons=N] [--output=DIR] [--season-config=FILE]' . PHP_EOL;
        exit(0);
    }
}

$payload = SimulationPopulationLifetime::run(
    (string)$options['seed'],
    (int)$options['players-per-archetype'],
    (int)$options['seasons'],
    $options['season-config'] ? (string)$options['season-config'] : null
);

$baseName = 'lifetime_' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string)$options['seed']) . '_s' . (int)$options['seasons'] . '_ppa' . (int)$options['players-per-archetype'];
$jsonPath = MetricsCollector::writeJson($payload, (string)$options['output'], $baseName);
$csvPath = MetricsCollector::writeLifetimeCsv($payload, (string)$options['output'], $baseName);
MetricsCollector::printLifetimeSummary($payload);
echo 'JSON: ' . $jsonPath . PHP_EOL;
if ($csvPath !== null) {
    echo 'CSV: ' . $csvPath . PHP_EOL;
}
