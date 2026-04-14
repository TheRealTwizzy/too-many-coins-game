<?php

require_once __DIR__ . '/simulation/SimulationSeason.php';
require_once __DIR__ . '/simulation/EconomicCandidateValidator.php';

$options = [
    'input' => null,
    'season-config' => null,
];

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--input=')) {
        $options['input'] = substr($arg, 8);
    } elseif (str_starts_with($arg, '--season-config=')) {
        $options['season-config'] = substr($arg, 16);
    } elseif ($arg === '--help') {
        echo <<<'HELP'
Strict Candidate Package Linter

Usage:
  php scripts/lint_candidate_packages.php --input=FILE [--season-config=FILE]

Options:
  --input=FILE          Candidate package JSON, scenario JSON, or raw candidate patch JSON
  --season-config=FILE  Optional base season JSON used for subsystem-gate/range context
  --help                Show this help
HELP;
        exit(0);
    }
}

if ($options['input'] === null) {
    fwrite(STDERR, "Candidate lint failed: --input is required.\n");
    exit(1);
}

$inputPath = realpath((string)$options['input']);
if ($inputPath === false || !is_file($inputPath)) {
    fwrite(STDERR, 'Candidate lint failed: file not found: ' . $options['input'] . PHP_EOL);
    exit(1);
}

$decoded = json_decode((string)file_get_contents($inputPath), true);
if (!is_array($decoded)) {
    fwrite(STDERR, 'Candidate lint failed: input must decode to a JSON object or array.' . PHP_EOL);
    exit(1);
}

$baseSeason = null;
if ($options['season-config'] !== null) {
    $seasonPath = realpath((string)$options['season-config']);
    if ($seasonPath === false || !is_file($seasonPath)) {
        fwrite(STDERR, 'Candidate lint failed: season config file not found: ' . $options['season-config'] . PHP_EOL);
        exit(1);
    }

    $seasonDecoded = json_decode((string)file_get_contents($seasonPath), true);
    if (!is_array($seasonDecoded)) {
        fwrite(STDERR, 'Candidate lint failed: season config must decode to a JSON object.' . PHP_EOL);
        exit(1);
    }

    $baseSeason = SimulationSeason::build(1, 'candidate-lint', $seasonDecoded);
}

$failures = EconomicCandidateValidator::validateCandidateDocument($decoded, [
    'base_season' => $baseSeason,
]);

if ($failures !== []) {
    fwrite(STDERR, 'Candidate lint failed: ' . count($failures) . ' issue(s).' . PHP_EOL);
    foreach ($failures as $failure) {
        fwrite(
            STDERR,
            sprintf(
                '- [%s] %s: %s',
                (string)($failure['reason_code'] ?? 'invalid'),
                (string)($failure['path'] ?? $failure['context'] ?? 'unknown'),
                (string)($failure['reason_detail'] ?? 'Validation failed')
            ) . PHP_EOL
        );
    }
    exit(2);
}

$packageCount = count((array)($decoded['packages'] ?? []));
$scenarioCount = count((array)($decoded['scenarios'] ?? []));
echo sprintf(
    'Candidate lint passed: %s | packages=%d scenarios=%d',
    $inputPath,
    $packageCount,
    $scenarioCount
) . PHP_EOL;
exit(0);
