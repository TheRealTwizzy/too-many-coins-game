<?php

require_once __DIR__ . '/simulation/SimulationSeason.php';
require_once __DIR__ . '/simulation/MetricsCollector.php';
require_once __DIR__ . '/simulation/ContractSimulator.php';

$options = [
    'seed' => 'phase1',
    'output' => __DIR__ . '/../simulation_output/contracts',
];

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--seed=')) {
        $options['seed'] = substr($arg, 7);
    } elseif (str_starts_with($arg, '--output=')) {
        $options['output'] = substr($arg, 9);
    } elseif ($arg === '--help') {
        $help = <<<'HELP'
Simulation A — Contract Simulator

Usage:
  php scripts/simulate_contracts.php [OPTIONS]

Options:
  --seed=VALUE   Run identifier (default: phase1)
  --output=DIR   Output directory (default: simulation_output/contracts)
  --help         Show this help

Outputs:
  simulation_output/contracts/contract_<seed>.json    Contract check results

Example:
  php scripts/simulate_contracts.php --seed=verify-20260408
HELP;
        echo $help;
        exit(0);
    }
}

$payload = ContractSimulator::run((string)$options['seed']);
$baseName = 'contract_' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string)$options['seed']);
$jsonPath = MetricsCollector::writeJson($payload, (string)$options['output'], $baseName);
MetricsCollector::printContractSummary($payload);
echo 'JSON: ' . $jsonPath . PHP_EOL;
