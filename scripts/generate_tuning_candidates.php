<?php
/**
 * Phase C — Candidate Tuning Package Generator
 *
 * Consumes the Phase B diagnosis report and produces staged, simulation-testable
 * economy tuning candidates.
 *
 * Usage:
 *   php scripts/generate_tuning_candidates.php --diagnosis=<diagnosis_report.json> [OPTIONS]
 *
 * Options:
 *   --diagnosis=FILE       Path to diagnosis_report.json (required)
 *   --season-config=FILE   Path to exported season config JSON (for current_value lookup; optional)
 *   --output=DIR           Output directory (default: simulation_output/current-db/tuning/)
 *   --version=N            Tuning version (1, 2, or 3; default 1)
 *   --help                 Show this help
 */

require_once __DIR__ . '/simulation/SimulationSeason.php';
require_once __DIR__ . '/optimization/TuningCandidateGenerator.php';

$options = [
    'diagnosis' => null,
    'season-config' => null,
    'output' => __DIR__ . '/../simulation_output/current-db/tuning',
    'version' => 1,
];

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--diagnosis=')) {
        $options['diagnosis'] = substr($arg, 12);
    } elseif (str_starts_with($arg, '--season-config=')) {
        $options['season-config'] = substr($arg, 16);
    } elseif (str_starts_with($arg, '--output=')) {
        $options['output'] = substr($arg, 9);
    } elseif (str_starts_with($arg, '--version=')) {
        $options['version'] = (int)substr($arg, 10);
    } elseif ($arg === '--help') {
        echo <<<'HELP'
Phase C — Candidate Tuning Package Generator

Consumes the Phase B diagnosis report and produces staged economy tuning candidates.

Usage:
  php scripts/generate_tuning_candidates.php --diagnosis=<diagnosis_report.json> [OPTIONS]

Options:
  --diagnosis=FILE       Path to diagnosis_report.json (required)
  --season-config=FILE   Path to exported season config JSON (optional; for current_value)
  --output=DIR           Output directory (default: simulation_output/current-db/tuning/)
  --version=N            Tuning version (1, 2, or 3; default 1)
  --help                 Show this help

Outputs:
  tuning_candidates[_vN].json   Staged candidates + lineage + scenarios
  tuning_candidates[_vN].md     Human-readable staged summary

HELP;
        exit(0);
    }
}

if ($options['diagnosis'] === null) {
    fwrite(STDERR, "ERROR: --diagnosis is required.\n");
    exit(1);
}

$diagnosisPath = realpath($options['diagnosis']);
if ($diagnosisPath === false || !is_file($diagnosisPath)) {
    fwrite(STDERR, "ERROR: Diagnosis report not found: {$options['diagnosis']}\n");
    exit(1);
}

$diagnosis = json_decode(file_get_contents($diagnosisPath), true);
if (!is_array($diagnosis) || !isset($diagnosis['schema_version'])) {
    fwrite(STDERR, "ERROR: Invalid diagnosis report format.\n");
    exit(1);
}

$seasonConfig = null;
if ($options['season-config'] !== null) {
    $seasonConfigPath = realpath($options['season-config']);
    if ($seasonConfigPath !== false && is_file($seasonConfigPath)) {
        $seasonConfig = json_decode(file_get_contents($seasonConfigPath), true);
        echo "Loaded season config from: {$seasonConfigPath}\n";
    }
}

$outputDir = $options['output'];
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0777, true);
}

$document = TuningCandidateGenerator::generate($diagnosis, [
    'diagnosis_path' => $diagnosisPath,
    'season_config' => $seasonConfig,
    'baseline_season' => SimulationSeason::build(1, 'baseline-defaults'),
    'tuning_version' => (int)$options['version'],
]);

$fileSuffix = (int)$options['version'] >= 2 ? '_v' . (int)$options['version'] : '';
$jsonPath = $outputDir . DIRECTORY_SEPARATOR . "tuning_candidates{$fileSuffix}.json";
$mdPath = $outputDir . DIRECTORY_SEPARATOR . "tuning_candidates{$fileSuffix}.md";

file_put_contents($jsonPath, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents($mdPath, TuningCandidateGenerator::renderMarkdown($document));

$stageCounts = (array)($document['metadata']['stage_counts'] ?? []);

echo "=== Phase C Complete ===\n";
echo "Candidates generated: " . (int)($document['metadata']['packages_generated'] ?? 0) . "\n";
echo "Escalations: " . count((array)($document['escalations'] ?? [])) . "\n";
echo "Scenarios: " . count((array)($document['scenarios'] ?? [])) . "\n";
foreach ($stageCounts as $stage => $count) {
    echo "  {$stage}: {$count}\n";
}
echo "JSON: {$jsonPath}\n";
echo "Markdown: {$mdPath}\n";

exit(0);
