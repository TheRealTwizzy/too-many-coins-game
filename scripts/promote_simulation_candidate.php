<?php

require_once __DIR__ . '/simulation/CandidatePromotionPipeline.php';

$options = [
    'candidate' => null,
    'candidate-id' => null,
    'seed' => null,
    'season-config' => null,
    'output' => __DIR__ . '/../simulation_output/promotion',
    'players-per-archetype' => 1,
    'season-count' => 4,
    'debug-bypass-stages' => [],
];

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--candidate=')) {
        $options['candidate'] = substr($arg, 12);
    } elseif (str_starts_with($arg, '--candidate-id=')) {
        $options['candidate-id'] = substr($arg, 15);
    } elseif (str_starts_with($arg, '--seed=')) {
        $options['seed'] = substr($arg, 7);
    } elseif (str_starts_with($arg, '--season-config=')) {
        $options['season-config'] = substr($arg, 16);
    } elseif (str_starts_with($arg, '--output=')) {
        $options['output'] = substr($arg, 9);
    } elseif (str_starts_with($arg, '--players-per-archetype=')) {
        $options['players-per-archetype'] = max(1, (int)substr($arg, 24));
    } elseif (str_starts_with($arg, '--season-count=')) {
        $options['season-count'] = max(2, (int)substr($arg, 15));
    } elseif (str_starts_with($arg, '--debug-bypass-stages=')) {
        $options['debug-bypass-stages'] = array_values(array_filter(array_map('trim', explode(',', substr($arg, 23)))));
    } elseif ($arg === '--help') {
        echo <<<'HELP'
Simulation Candidate Promotion Ladder

Usage:
  php scripts/promote_simulation_candidate.php --candidate=FILE [OPTIONS]

Required:
  --candidate=FILE              Candidate patch JSON (object or flat change list)

Options:
  --candidate-id=VALUE         Optional stable candidate id override
  --seed=VALUE                 Run seed / report label
  --season-config=FILE         Optional exported base season config JSON
  --output=DIR                 Output root (default: simulation_output/promotion)
  --players-per-archetype=N    Cohort size for validation runs (default: 1)
  --season-count=N             Lifetime season count for multi-season validation (default: 4)
  --debug-bypass-stages=A,B    Developer-only bypass list using stage ids
  --help                       Show this help

Stages:
  1. candidate_schema_validation
  2. effective_config_preflight
  3. targeted_subsystem_harnesses
  4. full_single_season_validation
  5. multi_season_exploit_regression_validation
  6. official_qualification_comparator_validation
  7. patch_serialization_validation
  8. play_test_runtime_parity_certification
  9. play_test_repo_compatibility_validation
  10. promotion_eligibility_marking
HELP;
        exit(0);
    }
}

if ($options['candidate'] === null || $options['candidate'] === '') {
    fwrite(STDERR, "Missing required --candidate=FILE argument.\n");
    exit(1);
}

if (!is_file((string)$options['candidate'])) {
    fwrite(STDERR, "Candidate file not found: {$options['candidate']}\n");
    exit(1);
}

$decoded = json_decode((string)file_get_contents((string)$options['candidate']), true);
if (!is_array($decoded)) {
    fwrite(STDERR, "Candidate file must decode to a JSON object or array.\n");
    exit(1);
}

$result = CandidatePromotionPipeline::run([
    'candidate_document' => $decoded,
    'candidate_id' => $options['candidate-id'],
    'seed' => $options['seed'],
    'base_season_config_path' => $options['season-config'],
    'output_dir' => $options['output'],
    'players_per_archetype' => (int)$options['players-per-archetype'],
    'season_count' => (int)$options['season-count'],
    'debug_bypass_stages' => (array)$options['debug-bypass-stages'],
]);

$state = (array)$result['state'];
$artifacts = (array)$result['artifact_paths'];

echo 'Candidate Promotion Pipeline' . PHP_EOL;
echo 'Candidate: ' . (string)$state['candidate_id'] . PHP_EOL;
echo 'Status: ' . (string)$state['status'] . PHP_EOL;
echo 'Patch ready: ' . ($state['patch_ready'] ? 'YES' : 'NO') . PHP_EOL;
echo 'Promotion eligible: ' . ($state['promotion_eligible'] ? 'YES' : 'NO') . PHP_EOL;
echo 'State JSON: ' . (string)($artifacts['promotion_state_json'] ?? '') . PHP_EOL;
echo 'Report JSON: ' . (string)($artifacts['promotion_report_json'] ?? '') . PHP_EOL;
echo 'Report MD: ' . (string)($artifacts['promotion_report_md'] ?? '') . PHP_EOL;

exit($state['promotion_eligible'] ? 0 : 2);
