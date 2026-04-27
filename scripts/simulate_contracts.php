<?php

require_once __DIR__ . '/simulation/SimulationSeason.php';
require_once __DIR__ . '/simulation/MetricsCollector.php';
require_once __DIR__ . '/simulation/ContractSimulator.php';
require_once __DIR__ . '/simulation/CandidatePatchLoader.php';

$options = [
    'seed' => 'phase1',
    'output' => __DIR__ . '/../simulation_output/contracts',
    'season-config' => null,
    'candidate-patch' => null,
    'allow-inactive-candidate-config' => false,
];

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--seed=')) {
        $options['seed'] = substr($arg, 7);
    } elseif (str_starts_with($arg, '--output=')) {
        $options['output'] = substr($arg, 9);
    } elseif (str_starts_with($arg, '--season-config=')) {
        $options['season-config'] = substr($arg, 16);
    } elseif (str_starts_with($arg, '--candidate-patch=')) {
        $options['candidate-patch'] = substr($arg, 18);
    } elseif (str_starts_with($arg, '--allow-inactive-candidate-config=')) {
        $value = strtolower(trim(substr($arg, 34)));
        $options['allow-inactive-candidate-config'] = in_array($value, ['1', 'true', 'yes'], true);
    } elseif ($arg === '--allow-inactive-candidate-config') {
        $options['allow-inactive-candidate-config'] = true;
    } elseif ($arg === '--help') {
        $help = <<<'HELP'
Simulation A â€” Contract Simulator

Usage:
  php scripts/simulate_contracts.php [OPTIONS]

Options:
  --seed=VALUE             Run identifier (default: phase1)
  --output=DIR             Output directory (default: simulation_output/contracts)
  --season-config=FILE     JSON file with base season config overrides
  --candidate-patch=FILE   JSON candidate patch validated by effective-config preflight
  --allow-inactive-candidate-config
                  Debug-only bypass for failed effective-config preflight
  --help         Show this help

Outputs:
  simulation_output/contracts/contract_<seed>.json    Contract check results
  simulation_output/contracts/contract_<seed>.audit/effective_config.json
  simulation_output/contracts/contract_<seed>.audit/effective_config_audit.md

Example:
  php scripts/simulate_contracts.php --seed=verify-20260408
HELP;
        echo $help;
        exit(0);
    }
}

$baseName = 'contract_' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string)$options['seed']);

try {
    $candidatePatch = CandidatePatchLoader::load($options['candidate-patch']);
    $payload = ContractSimulator::run((string)$options['seed'], [
        'run_label' => $baseName,
        'base_season_config_path' => $options['season-config'],
        'candidate_patch' => $candidatePatch,
        'preflight_artifact_dir' => rtrim((string)$options['output'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $baseName . '.audit',
        'debug_allow_inactive_candidate' => (bool)$options['allow-inactive-candidate-config'],
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, 'Simulation A failed: ' . $e->getMessage() . PHP_EOL);
    exit(2);
}

$jsonPath = MetricsCollector::writeJson($payload, (string)$options['output'], $baseName);
MetricsCollector::printContractSummary($payload);
echo 'JSON: ' . $jsonPath . PHP_EOL;
if (!empty($payload['config_audit']['artifact_paths']['effective_config_json'])) {
    echo 'Effective Config: ' . (string)$payload['config_audit']['artifact_paths']['effective_config_json'] . PHP_EOL;
}
