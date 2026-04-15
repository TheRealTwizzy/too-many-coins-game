<?php

require_once __DIR__ . '/simulation/SweepComparatorProfileCatalog.php';
require_once __DIR__ . '/simulation/SweepComparatorCampaignRunner.php';

$options = [
    'profile' => 'qualification',
    'seed' => null,
    'season_config_path' => null,
    'output_dir' => null,
    'tuning_candidates_path' => null,
    'players_per_archetype' => null,
    'season_count' => null,
    'simulators' => null,
    'scenario_names' => null,
    'include_baseline' => null,
    'list_profiles' => false,
];

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--profile=')) {
        $options['profile'] = substr($arg, 10);
    } elseif (str_starts_with($arg, '--seed=')) {
        $options['seed'] = substr($arg, 7);
    } elseif (str_starts_with($arg, '--season-config=')) {
        $options['season_config_path'] = substr($arg, 16);
    } elseif (str_starts_with($arg, '--output=')) {
        $options['output_dir'] = substr($arg, 9);
    } elseif (str_starts_with($arg, '--tuning-candidates=')) {
        $options['tuning_candidates_path'] = substr($arg, 20);
    } elseif (str_starts_with($arg, '--players-per-archetype=')) {
        $options['players_per_archetype'] = max(1, (int)substr($arg, 24));
    } elseif (str_starts_with($arg, '--seasons=')) {
        $options['season_count'] = max(2, (int)substr($arg, 10));
    } elseif (str_starts_with($arg, '--simulators=')) {
        $options['simulators'] = array_values(array_filter(array_map('trim', explode(',', substr($arg, 13)))));
    } elseif (str_starts_with($arg, '--scenarios=')) {
        $options['scenario_names'] = array_values(array_filter(array_map('trim', explode(',', substr($arg, 12)))));
    } elseif (str_starts_with($arg, '--include-baseline=')) {
        $value = strtolower(trim(substr($arg, 19)));
        $options['include_baseline'] = !in_array($value, ['0', 'false', 'no'], true);
    } elseif ($arg === '--list-profiles') {
        $options['list_profiles'] = true;
    } elseif ($arg === '--help') {
        $help = <<<'HELP'
Sweep + Comparator Campaign Runner

Usage:
  php scripts/run_sweep_comparator_campaign.php [OPTIONS]

Options:
  --profile=qualification|full-campaign   Official execution profile (default: qualification)
  --seed=VALUE                            Artifact seed
  --season-config=FILE                    Canonical base season config
  --output=DIR                            Output directory
  --tuning-candidates=FILE                Register scenarios from tuning_candidates JSON
  --players-per-archetype=N               Override profile cohort size
  --seasons=N                             Override profile season count
  --simulators=B,C                        Override profile simulators
  --scenarios=A,B                         Override profile scenarios
  --include-baseline=0|1                  Override baseline inclusion
  --list-profiles                         Print built-in profiles and exit
  --help                                  Show this help
HELP;
        echo $help . PHP_EOL;
        exit(0);
    }
}

if (!empty($options['list_profiles'])) {
    foreach (SweepComparatorProfileCatalog::all() as $profile) {
        echo sprintf(
            '%s: %s | ppa=%d | seasons=%d | scenarios=%s',
            (string)$profile['id'],
            (string)$profile['description'],
            (int)$profile['players_per_archetype'],
            (int)$profile['season_count'],
            implode(',', (array)$profile['scenario_names'])
        ) . PHP_EOL;
    }
    exit(0);
}

try {
    $result = SweepComparatorCampaignRunner::run(array_filter($options, static function ($value): bool {
        return $value !== null;
    }));
} catch (Throwable $e) {
    fwrite(STDERR, 'Sweep/comparator campaign failed: ' . $e->getMessage() . PHP_EOL);
    exit(2);
}

$report = (array)$result['report'];
$timing = (array)($report['timing_summary'] ?? []);
$summary = (array)($report['summary'] ?? []);

echo 'Sweep + Comparator Campaign Runner' . PHP_EOL;
echo 'Profile: ' . (string)($report['profile']['id'] ?? 'unknown') . PHP_EOL;
echo sprintf(
    'Total duration: %.2f minutes | Sweep runs: %d | Rejected scenarios: %d',
    ((int)($timing['total_duration_ms'] ?? 0)) / 60_000.0,
    (int)($summary['sweep_run_count'] ?? 0),
    (int)($summary['rejected_scenario_count'] ?? 0)
) . PHP_EOL;
echo 'Sweep manifest: ' . (string)($report['artifacts']['sweep_manifest_json'] ?? '') . PHP_EOL;
echo 'Comparator: ' . (string)($report['artifacts']['comparison_json'] ?? '') . PHP_EOL;
echo 'Report: ' . (string)$result['report_json_path'] . PHP_EOL;
