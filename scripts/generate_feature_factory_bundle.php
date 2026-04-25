<?php

require_once __DIR__ . '/feature_factory/FeatureFactory.php';

$options = [
    'proposal' => null,
    'output' => __DIR__ . '/../simulation_output/feature_factory',
];

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--proposal=')) {
        $options['proposal'] = substr($arg, 11);
    } elseif (str_starts_with($arg, '--output=')) {
        $options['output'] = substr($arg, 9);
    } elseif ($arg === '--help') {
        echo <<<'HELP'
Feature Factory Bundle Generator

Usage:
  php scripts/generate_feature_factory_bundle.php --proposal=FILE [--output=DIR]

Inputs:
  --proposal=FILE   Mechanic proposal JSON file.

Options:
  --output=DIR      Output root. Default: simulation_output/feature_factory
  --help            Show this help.

Behavior:
  - writes simulation/config/mechanic scaffolding only
  - blocks runtime/API/frontend/database/deployment/unknown paths before approval
  - validates existing-key candidate templates against the canonical candidate surface
HELP;
        exit(0);
    }
}

if ($options['proposal'] === null || $options['proposal'] === '') {
    fwrite(STDERR, "Missing required --proposal=FILE argument.\n");
    exit(1);
}

try {
    $result = FeatureFactory::generateFromProposalFile((string)$options['proposal'], [
        'output_root' => (string)$options['output'],
    ]);
} catch (FeatureFactoryException $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    foreach ($e->details() as $detail) {
        fwrite(STDERR, '- ' . json_encode($detail, JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

echo 'Feature Factory Bundle' . PHP_EOL;
echo 'Mechanic: ' . $result['mechanic_id'] . PHP_EOL;
echo 'Bundle hash: ' . $result['bundle_hash'] . PHP_EOL;
echo 'Candidate validation: ' . $result['candidate_validation']['status'] . PHP_EOL;
echo 'Bundle root: ' . $result['bundle_root'] . PHP_EOL;

exit(0);
