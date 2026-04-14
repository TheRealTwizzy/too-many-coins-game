<?php

require_once __DIR__ . '/simulation/RuntimeParityCertification.php';

$options = [
    'candidate-id' => 'runtime-parity',
    'seed' => null,
    'season-config' => null,
    'output' => __DIR__ . '/../simulation_output/runtime-parity',
];

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--candidate-id=')) {
        $options['candidate-id'] = substr($arg, 15);
    } elseif (str_starts_with($arg, '--seed=')) {
        $options['seed'] = substr($arg, 7);
    } elseif (str_starts_with($arg, '--season-config=')) {
        $options['season-config'] = substr($arg, 16);
    } elseif (str_starts_with($arg, '--output=')) {
        $options['output'] = substr($arg, 9);
    } elseif ($arg === '--help') {
        echo <<<'HELP'
Runtime Parity Certification

Usage:
  php scripts/certify_runtime_parity.php [OPTIONS]

Options:
  --candidate-id=VALUE      Optional report label (default: runtime-parity)
  --seed=VALUE              Optional deterministic seed
  --season-config=FILE      Optional exported season config JSON
  --output=DIR              Output root (default: simulation_output/runtime-parity)
  --help                    Show this help
HELP;
        exit(0);
    }
}

$result = RuntimeParityCertification::run([
    'candidate_id' => $options['candidate-id'],
    'seed' => $options['seed'],
    'season_config_path' => $options['season-config'],
    'output_dir' => $options['output'],
]);

$report = (array)$result['report'];
$artifacts = (array)$result['artifact_paths'];

echo 'Runtime Parity Certification' . PHP_EOL;
echo 'Candidate: ' . (string)$report['candidate_id'] . PHP_EOL;
echo 'Status: ' . (string)$report['certification_status'] . PHP_EOL;
echo 'Certified: ' . (!empty($report['certified']) ? 'YES' : 'NO') . PHP_EOL;
echo 'JSON: ' . (string)($artifacts['runtime_parity_certification_json'] ?? '') . PHP_EOL;
echo 'Markdown: ' . (string)($artifacts['runtime_parity_certification_md'] ?? '') . PHP_EOL;

exit(!empty($report['certified']) ? 0 : 2);
