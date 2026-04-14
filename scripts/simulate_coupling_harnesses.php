<?php

require_once __DIR__ . '/optimization/AgenticOptimization.php';

function tmcHarnessNormalizeCandidateDocument(mixed $document): array
{
    if (!is_array($document) || $document === []) {
        return [];
    }

    $hasStructuredKeys = isset($document['packages']) || isset($document['scenarios']) || isset($document['overrides']) || isset($document['changes']);
    if ($hasStructuredKeys) {
        throw new InvalidArgumentException('Coupling harnesses expect a single candidate patch document, not a package/scenario bundle.');
    }

    $isAssoc = array_keys($document) !== range(0, count($document) - 1);
    if ($isAssoc) {
        return $document;
    }

    $normalized = [];
    foreach ($document as $index => $entry) {
        if (!is_array($entry)) {
            throw new InvalidArgumentException('Candidate patch entry at index ' . $index . ' must be an object.');
        }

        $target = (string)($entry['target'] ?? $entry['path'] ?? $entry['key'] ?? '');
        if ($target === '') {
            throw new InvalidArgumentException('Candidate patch entry at index ' . $index . ' is missing `target`, `path`, or `key`.');
        }

        if (str_starts_with($target, 'runtime.')) {
            continue;
        }

        $key = str_starts_with($target, 'season.') ? substr($target, 7) : $target;
        $normalized[$key] = $entry['proposed_value'] ?? $entry['requested_value'] ?? $entry['value'] ?? null;
    }

    return $normalized;
}

function tmcHarnessLoadCandidatePatch(?string $path): array
{
    if ($path === null || trim($path) === '') {
        return ['raw' => [], 'normalized' => [], 'source' => null];
    }

    if (!is_file($path)) {
        throw new InvalidArgumentException('Candidate patch file does not exist: ' . $path);
    }

    $decoded = json_decode((string)file_get_contents($path), true);
    if (!is_array($decoded)) {
        throw new InvalidArgumentException('Candidate patch file must decode to a JSON object or array: ' . $path);
    }

    return [
        'raw' => $decoded,
        'normalized' => tmcHarnessNormalizeCandidateDocument($decoded),
        'source' => $path,
    ];
}

function tmcHarnessWriteMarkdown(string $path, array $report): void
{
    $lines = [];
    $lines[] = '# Coupling Harness Report';
    $lines[] = '';
    $lines[] = 'Generated: ' . (string)$report['generated_at'];
    $lines[] = 'Seed: `' . (string)$report['seed'] . '`';
    $lines[] = 'Overall status: `' . (string)$report['status'] . '`';
    $lines[] = 'Selected families: ' . implode(', ', (array)$report['selected_family_ids']);
    $lines[] = '';
    $lines[] = '## Promotion Ladder';
    $lines[] = '';
    $lines[] = '- This gate runs before tier2/tier3 promotion in the agentic ladder.';
    $lines[] = '- A failing family is an early reject even if the local objective score looks good.';
    $lines[] = '';

    foreach ((array)$report['families'] as $family) {
        $lines[] = '## ' . (string)$family['label'] . ' (`' . (string)$family['family_id'] . '`)';
        $lines[] = '';
        $lines[] = '- Status: `' . (string)$family['status'] . '`';
        $lines[] = '- Harness profile: `' . (string)$family['profile_id'] . '`';
        $lines[] = '- Simulators: ' . implode(', ', (array)$family['profile']['simulators']);
        $lines[] = '- Estimated speedup vs tier3 full: ' . (string)$family['profile']['estimated_speedup_vs_tier3_full'] . 'x';
        $lines[] = '- Proves: ' . (string)$family['proves'];
        $lines[] = '- Cannot prove: ' . (string)$family['cannot_prove'];
        if (!empty($family['blocking_flags'])) {
            $lines[] = '- Blocking flags: ' . implode(', ', (array)$family['blocking_flags']);
        }
        $lines[] = '- Directional diagnostics:';
        foreach ((array)$family['metric_results'] as $metric) {
            $lines[] = '  - `' . (string)$metric['metric'] . '` ' . (string)$metric['status']
                . ' | baseline=' . $metric['baseline']
                . ' candidate=' . $metric['candidate']
                . ' delta=' . $metric['delta']
                . ' | ' . (string)$metric['diagnostic'];
        }
        $lines[] = '';
    }

    AgenticOptimizationUtils::ensureDir(dirname($path));
    file_put_contents($path, implode(PHP_EOL, $lines));
}

$options = [
    'seed' => 'coupling-' . gmdate('Ymd-His'),
    'season-config' => null,
    'candidate-patch' => null,
    'families' => null,
    'output' => __DIR__ . '/../simulation_output/coupling-harnesses',
];

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--seed=')) {
        $options['seed'] = substr($arg, 7);
    } elseif (str_starts_with($arg, '--season-config=')) {
        $options['season-config'] = substr($arg, 16);
    } elseif (str_starts_with($arg, '--candidate-patch=')) {
        $options['candidate-patch'] = substr($arg, 18);
    } elseif (str_starts_with($arg, '--families=')) {
        $options['families'] = substr($arg, 11);
    } elseif (str_starts_with($arg, '--output=')) {
        $options['output'] = substr($arg, 9);
    } elseif ($arg === '--help') {
        echo <<<'HELP'
Simulation H — Coupling Harness Suite

Usage:
  php scripts/simulate_coupling_harnesses.php [OPTIONS]

Options:
  --seed=VALUE             Run identifier (default: coupling-<UTC timestamp>)
  --season-config=FILE     Optional baseline season config JSON
  --candidate-patch=FILE   Optional candidate patch JSON (object or flat change list)
  --families=A,B,C         Optional subset of harness family ids
  --output=DIR             Output directory root (default: simulation_output/coupling-harnesses)
  --help                   Show this help

Family ids:
  lock_in_down_but_expiry_dominance_up
  skip_rejoin_exploit_worsened
  hoarding_pressure_imbalance
  boost_underperformance
  star_affordability_pricing_instability

Examples:
  php scripts/simulate_coupling_harnesses.php --season-config=simulation_output/current-db/export/current_season.json
  php scripts/simulate_coupling_harnesses.php --season-config=simulation_output/current-db/export/current_season.json --candidate-patch=tmp/candidate_patch.json
  php scripts/simulate_coupling_harnesses.php --families=hoarding_pressure_imbalance,boost_underperformance
HELP;
        exit(0);
    }
}

$runId = AgenticOptimizationUtils::sanitize((string)$options['seed']);
$outputRoot = rtrim((string)$options['output'], DIRECTORY_SEPARATOR);
$runDir = $outputRoot . DIRECTORY_SEPARATOR . $runId;

try {
    AgenticOptimizationUtils::ensureDir($runDir);
    $baseline = AgenticBaselineConfigLoader::load($options['season-config'] ?: null);
    $baseSeason = (array)$baseline['season'];
    $candidatePatch = tmcHarnessLoadCandidatePatch($options['candidate-patch'] ?: null);
    $candidateConfig = array_replace($baseSeason, (array)$candidatePatch['normalized']);

    $allFamilies = AgenticCouplingHarnessCatalog::families();
    $selectedFamilyIds = $options['families'] !== null
        ? array_values(array_filter(array_map('trim', explode(',', (string)$options['families']))))
        : array_keys($allFamilies);

    if ($selectedFamilyIds === []) {
        throw new InvalidArgumentException('At least one coupling harness family must be selected.');
    }

    foreach ($selectedFamilyIds as $familyId) {
        AgenticCouplingHarnessCatalog::family($familyId);
    }

    $decomposition = AgenticEconomyDecomposition::build();
    $runner = new AgenticHarnessRunner($runDir);
    $result = AgenticCouplingHarnessEvaluator::runFamilies(
        $runner,
        $decomposition,
        $baseSeason,
        $candidateConfig,
        (array)$candidatePatch['raw'],
        $selectedFamilyIds,
        $runId
    );

    $report = [
        'schema_version' => 'tmc-coupling-harness-report.v1',
        'generated_at' => gmdate('c'),
        'seed' => $runId,
        'status' => (string)$result['status'],
        'selected_family_ids' => $selectedFamilyIds,
        'failed_family_ids' => (array)$result['failed_family_ids'],
        'baseline_provenance' => (array)$baseline['provenance'],
        'candidate_patch' => [
            'source' => $candidatePatch['source'],
            'raw' => (array)$candidatePatch['raw'],
            'normalized_season_values' => (array)$candidatePatch['normalized'],
        ],
        'families' => (array)$result['families'],
    ];

    $jsonPath = $runDir . DIRECTORY_SEPARATOR . 'coupling_harness_report.json';
    $mdPath = $runDir . DIRECTORY_SEPARATOR . 'coupling_harness_report.md';
    AgenticOptimizationUtils::writeJson($jsonPath, $report);
    tmcHarnessWriteMarkdown($mdPath, $report);

    echo 'Coupling Harness Suite Complete' . PHP_EOL;
    echo 'Run Dir: ' . $runDir . PHP_EOL;
    echo 'JSON: ' . $jsonPath . PHP_EOL;
    echo 'Markdown: ' . $mdPath . PHP_EOL;
    echo 'Status: ' . (string)$report['status'] . PHP_EOL;
    echo 'Failed families: ' . (empty($report['failed_family_ids']) ? 'none' : implode(', ', (array)$report['failed_family_ids'])) . PHP_EOL;

    exit($report['status'] === 'pass' ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Coupling harness suite failed: ' . $e->getMessage() . PHP_EOL);
    exit(2);
}
