<?php

require_once __DIR__ . '/simulation/SimulationSeason.php';
require_once __DIR__ . '/simulation/SimulationRandom.php';
require_once __DIR__ . '/simulation/Archetypes.php';
require_once __DIR__ . '/simulation/PolicyBehavior.php';
require_once __DIR__ . '/simulation/MetricsCollector.php';
require_once __DIR__ . '/simulation/SimulationPlayer.php';
require_once __DIR__ . '/simulation/SimulationPopulationSeason.php';

$options = [
    'seed' => 'phase1',
    'players-per-archetype' => 5,
    'output' => __DIR__ . '/../simulation_output/season',
    'season-config' => null,
];

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--seed=')) {
        $options['seed'] = substr($arg, 7);
    } elseif (str_starts_with($arg, '--players-per-archetype=')) {
        $options['players-per-archetype'] = max(1, (int)substr($arg, 24));
    } elseif (str_starts_with($arg, '--output=')) {
        $options['output'] = substr($arg, 9);
    } elseif (str_starts_with($arg, '--season-config=')) {
        $options['season-config'] = substr($arg, 16);
    } elseif ($arg === '--help') {
        echo 'Usage: php scripts/simulate_economy.php [--seed=VALUE] [--players-per-archetype=N] [--output=DIR] [--season-config=FILE]' . PHP_EOL;
        exit(0);
    }
}

$payload = SimulationPopulationSeason::run(
    (string)$options['seed'],
    (int)$options['players-per-archetype'],
    $options['season-config'] ? (string)$options['season-config'] : null
);
$baseName = 'season_' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string)$options['seed']) . '_ppa' . (int)$options['players-per-archetype'];
$jsonPath = MetricsCollector::writeJson($payload, (string)$options['output'], $baseName);
$csvPath = MetricsCollector::writeSeasonCsv($payload, (string)$options['output'], $baseName);
MetricsCollector::printSeasonSummary($payload);
echo 'JSON: ' . $jsonPath . PHP_EOL;
if ($csvPath !== null) {
    echo 'CSV: ' . $csvPath . PHP_EOL;
}
