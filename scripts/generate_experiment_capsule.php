<?php

require_once __DIR__ . '/feature_factory/ExperimentCapsuleRunner.php';

$options = [
    'proposal' => null,
    'output' => __DIR__ . '/../simulation_output/feature_factory',
    'approval' => null,
];

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--proposal=')) {
        $options['proposal'] = substr($arg, 11);
    } elseif (str_starts_with($arg, '--output=')) {
        $options['output'] = substr($arg, 9);
    } elseif (str_starts_with($arg, '--approval=')) {
        $options['approval'] = substr($arg, 11);
    } elseif ($arg === '--help') {
        echo <<<'HELP'
Experiment Capsule Generator

Usage:
  php scripts/generate_experiment_capsule.php --proposal=FILE [--output=DIR] [--approval=FILE]

Inputs:
  --proposal=FILE   Hybrid experiment proposal JSON file.
  --approval=FILE   Optional approval manifest JSON for tiny player-facing paths.

Options:
  --output=DIR      Output root. Default: simulation_output/feature_factory
  --help            Show this help.

Behavior:
  - starts every experiment as a capsule
  - generates the existing feature-factory bundle
  - writes experiment_brief.json, experience_gate_report.json, and decision_record.json
  - marks broad or coupled capsules as split_to_dual_lane
  - blocks player-facing implementation readiness without approval
HELP;
        exit(0);
    }
}

if ($options['proposal'] === null || $options['proposal'] === '') {
    fwrite(STDERR, "Missing required --proposal=FILE argument.\n");
    exit(1);
}

$runnerOptions = [
    'output_root' => (string)$options['output'],
];

if ($options['approval'] !== null && $options['approval'] !== '') {
    $approvalJson = (string)file_get_contents((string)$options['approval']);
    $approval = json_decode($approvalJson, true);
    if (!is_array($approval)) {
        fwrite(STDERR, "Approval manifest JSON must decode to an object.\n");
        exit(1);
    }
    $runnerOptions['approval_manifest'] = $approval;
    $runnerOptions['approval_manifest_bundle_hash_mode'] = 'use_generated_hash';
}

try {
    $result = ExperimentCapsuleRunner::generateFromProposalFile((string)$options['proposal'], $runnerOptions);
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

echo 'Experiment Capsule' . PHP_EOL;
echo 'Experiment: ' . $result['experiment_id'] . PHP_EOL;
echo 'Mechanic: ' . $result['mechanic_id'] . PHP_EOL;
echo 'Bundle hash: ' . $result['bundle_hash'] . PHP_EOL;
echo 'Candidate validation: ' . $result['candidate_validation']['status'] . PHP_EOL;
echo 'Experience gate: ' . $result['experience_gate_report']['status'] . PHP_EOL;
echo 'Decision: ' . $result['decision_record']['status'] . PHP_EOL;
echo 'Bundle root: ' . $result['bundle_root'] . PHP_EOL;

exit(0);
