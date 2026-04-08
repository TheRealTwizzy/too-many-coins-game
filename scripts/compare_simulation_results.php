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
        echo 'Usage: php scripts/compare_simulation_results.php --sweep-manifest=FILE [--baseline-b=FILE] [--baseline-c=FILE] [--seed=VALUE] [--output=DIR]' . PHP_EOL;
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
        '- %s | wins %d | losses %d | mixed %d | disposition: %s',
        (string)$scenario['scenario_name'],
        (int)$scenario['wins'],
        (int)$scenario['losses'],
        (int)$scenario['mixed_tradeoffs'],
        (string)$scenario['recommended_disposition']
    ) . PHP_EOL;
    $flags = (array)($scenario['regression_flags'] ?? []);
    if (!empty($flags)) {
        echo '  regression flags: ' . implode(', ', $flags) . PHP_EOL;
    }
}
echo 'JSON: ' . (string)$result['json_path'] . PHP_EOL;
