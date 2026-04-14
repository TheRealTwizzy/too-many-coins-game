<?php

require_once __DIR__ . '/simulation/PromotionPatchGenerator.php';

$options = [
    'promotion-report' => null,
    'canonical-config' => null,
    'base-season-config' => null,
    'candidate-id' => null,
    'bundle-id' => null,
    'output' => __DIR__ . '/../simulation_output/promotion-bundles',
    'repo-root' => __DIR__ . '/..',
    'dry-run' => false,
];

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--promotion-report=')) {
        $options['promotion-report'] = substr($arg, 19);
    } elseif (str_starts_with($arg, '--canonical-config=')) {
        $options['canonical-config'] = substr($arg, 19);
    } elseif (str_starts_with($arg, '--base-season-config=')) {
        $options['base-season-config'] = substr($arg, 21);
    } elseif (str_starts_with($arg, '--candidate-id=')) {
        $options['candidate-id'] = substr($arg, 15);
    } elseif (str_starts_with($arg, '--bundle-id=')) {
        $options['bundle-id'] = substr($arg, 12);
    } elseif (str_starts_with($arg, '--output=')) {
        $options['output'] = substr($arg, 9);
    } elseif (str_starts_with($arg, '--repo-root=')) {
        $options['repo-root'] = substr($arg, 12);
    } elseif ($arg === '--dry-run') {
        $options['dry-run'] = true;
    } elseif ($arg === '--help') {
        echo <<<'HELP'
Promotion Patch Generator

Usage:
  php scripts/generate_promotion_patch.php --promotion-report=FILE [OPTIONS]
  php scripts/generate_promotion_patch.php --canonical-config=FILE [OPTIONS]

Inputs:
  --promotion-report=FILE      Promotion report JSON from CandidatePromotionPipeline
  --canonical-config=FILE      Promotion-eligible canonical season config JSON

Options:
  --base-season-config=FILE    Base play-test season config JSON (optional if report provides one)
  --candidate-id=VALUE         Stable candidate id override
  --bundle-id=VALUE            Stable output bundle id override
  --output=DIR                 Bundle output root (default: simulation_output/promotion-bundles)
  --repo-root=DIR              Repo root used for approved-file collision checks
  --dry-run                    Print the unified diff preview to stdout
  --help                       Show this help

Behavior:
  - stages one approved root migration file in the bundle
  - writes repo_patch.diff, promotion_bundle.json, and patched_play_test_season.json
  - never edits the repo directly; the generated bundle is always review-first
HELP;
        exit(0);
    }
}

if (($options['promotion-report'] === null || $options['promotion-report'] === '')
    && ($options['canonical-config'] === null || $options['canonical-config'] === '')
) {
    fwrite(STDERR, "Missing required --promotion-report=FILE or --canonical-config=FILE argument.\n");
    exit(1);
}

try {
    $resolved = resolveGeneratorInput($options);
    $result = PromotionPatchGenerator::generate([
        'canonical_config' => $resolved['canonical_config'],
        'base_season' => $resolved['base_season'],
        'candidate_id' => $options['candidate-id'] ?? $resolved['candidate_id'],
        'bundle_id' => $options['bundle-id'] ?? $resolved['bundle_id'],
        'output_dir' => $options['output'],
        'repo_root' => $options['repo-root'],
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

$bundle = (array)$result['bundle'];
$artifacts = (array)$result['artifact_paths'];

echo 'Promotion Patch Bundle' . PHP_EOL;
echo 'Bundle: ' . (string)$bundle['bundle_id'] . PHP_EOL;
echo 'Candidate: ' . (string)$bundle['candidate_id'] . PHP_EOL;
echo 'Changed keys: ' . (int)count((array)$bundle['changed_keys']) . PHP_EOL;
echo 'Repo diff: ' . (string)$artifacts['repo_patch_diff'] . PHP_EOL;
echo 'Bundle JSON: ' . (string)$artifacts['bundle_json'] . PHP_EOL;
echo 'Staged file: ' . (string)$artifacts['staged_repo_file'] . PHP_EOL;

if (!empty($options['dry-run'])) {
    echo PHP_EOL;
    echo file_get_contents((string)$artifacts['repo_patch_diff']);
}

exit(0);

function resolveGeneratorInput(array $options): array
{
    if (!empty($options['promotion-report'])) {
        $report = decodeJsonFile((string)$options['promotion-report'], 'promotion report');
        if (empty($report['promotion_eligible'])) {
            throw new RuntimeException('Promotion report is not promotion-eligible and cannot be converted into a repo patch bundle.');
        }

        $candidateConfig = (array)($report['candidate_effective_season']['effective_config'] ?? []);
        $baseConfig = (array)($report['base_season']['effective_config'] ?? []);
        if ($candidateConfig === []) {
            throw new RuntimeException('Promotion report is missing candidate_effective_season.effective_config.');
        }

        if (!empty($options['base-season-config'])) {
            $baseConfig = extractSeasonConfigDocument(
                decodeJsonFile((string)$options['base-season-config'], 'base season config')
            );
        }

        return [
            'candidate_id' => (string)($report['candidate_id'] ?? $options['candidate-id'] ?? ''),
            'bundle_id' => 'promotion-bundle-' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string)($report['candidate_id'] ?? 'candidate')),
            'canonical_config' => extractSeasonConfigDocument($candidateConfig),
            'base_season' => $baseConfig !== [] ? extractSeasonConfigDocument($baseConfig) : SimulationSeason::build(1, 'promotion-patch-generator-base'),
        ];
    }

    $candidateDocument = decodeJsonFile((string)$options['canonical-config'], 'canonical config');
    $baseDocument = !empty($options['base-season-config'])
        ? decodeJsonFile((string)$options['base-season-config'], 'base season config')
        : SimulationSeason::build(1, 'promotion-patch-generator-base');

    return [
        'candidate_id' => (string)($options['candidate-id'] ?? ''),
        'bundle_id' => null,
        'canonical_config' => extractSeasonConfigDocument($candidateDocument),
        'base_season' => extractSeasonConfigDocument($baseDocument),
    ];
}

function decodeJsonFile(string $path, string $label): array
{
    if (!is_file($path)) {
        throw new RuntimeException(ucfirst($label) . ' file not found: ' . $path);
    }

    $decoded = json_decode((string)file_get_contents($path), true);
    if (!is_array($decoded)) {
        throw new RuntimeException(ucfirst($label) . ' must decode to a JSON object or array: ' . $path);
    }

    return $decoded;
}

function extractSeasonConfigDocument(array $document): array
{
    if (isset($document['effective_config']) && is_array($document['effective_config'])) {
        $effective = (array)$document['effective_config'];
        if (isset($effective['season']) && is_array($effective['season'])) {
            return (array)$effective['season'];
        }
        return $effective;
    }

    if (isset($document['candidate_effective_season']['effective_config']) && is_array($document['candidate_effective_season']['effective_config'])) {
        return (array)$document['candidate_effective_season']['effective_config'];
    }

    return $document;
}
