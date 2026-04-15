<?php

require_once __DIR__ . '/../../scripts/optimization/TuningCandidateGenerator.php';

$diagnosis = json_decode((string)file_get_contents(__DIR__ . '/discovery_diagnosis.json'), true);
$season = json_decode((string)file_get_contents(__DIR__ . '/../../simulation_output/current-db/export/current_season_economy_only.json'), true);

if (!is_array($diagnosis) || !is_array($season)) {
    fwrite(STDERR, "Failed to load discovery diagnosis or baseline season.\n");
    exit(1);
}

$doc = TuningCandidateGenerator::generate($diagnosis, [
    'baseline_season' => SimulationSeason::build(1, 'balance-discovery-baseline', $season),
    'season_config' => $season,
    'tuning_version' => 3,
    'diagnosis_path' => 'tmp/balance-discovery/discovery_diagnosis.json',
]);

file_put_contents(__DIR__ . '/generated_candidates.json', json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents(__DIR__ . '/generated_candidates.md', TuningCandidateGenerator::renderMarkdown($doc));

echo json_encode([
    'packages_generated' => $doc['metadata']['packages_generated'] ?? null,
    'stage_counts' => $doc['metadata']['stage_counts'] ?? null,
    'blocked_counts' => $doc['metadata']['advancement_blocked_counts'] ?? null,
    'active_search_space' => $doc['baseline_context']['active_search_space_keys'] ?? [],
    'suppressed_count' => $doc['suppression_report']['count'] ?? null,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
