<?php

require_once __DIR__ . '/PolicyScenarioCatalog.php';
require_once __DIR__ . '/PolicySweepRunner.php';
require_once __DIR__ . '/ResultComparator.php';
require_once __DIR__ . '/SweepComparatorProfileCatalog.php';

class SweepComparatorCampaignRunner
{
    public const REPORT_SCHEMA_VERSION = 'tmc-sweep-comparator-campaign-report.v1';

    public static function run(array $options): array
    {
        $profileId = (string)($options['profile'] ?? 'qualification');
        $profile = SweepComparatorProfileCatalog::resolve($profileId);
        $resolvedProfile = self::applyOverrides($profile, $options);

        $seed = (string)($options['seed'] ?? ($profileId . '-' . gmdate('Ymd-His')));
        $outputDir = (string)($options['output_dir'] ?? (__DIR__ . '/../../simulation_output/sweep-comparator/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $seed)));
        $seasonConfigPath = isset($options['season_config_path']) && $options['season_config_path'] !== ''
            ? (string)$options['season_config_path']
            : null;
        $tuningCandidatesPath = isset($resolvedProfile['tuning_candidates_path']) && $resolvedProfile['tuning_candidates_path'] !== ''
            ? (string)$resolvedProfile['tuning_candidates_path']
            : null;

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        $sweepDir = $outputDir . DIRECTORY_SEPARATOR . 'sweep';
        $comparatorDir = $outputDir . DIRECTORY_SEPARATOR . 'comparator';
        if (!is_dir($sweepDir)) {
            mkdir($sweepDir, 0777, true);
        }
        if (!is_dir($comparatorDir)) {
            mkdir($comparatorDir, 0777, true);
        }

        if ($tuningCandidatesPath !== null) {
            PolicyScenarioCatalog::registerExtra(PolicyScenarioCatalog::loadTuningScenarios($tuningCandidatesPath));
        }

        $previousTickRealSeconds = getenv('TMC_TICK_REAL_SECONDS');
        $tickRealSecondsApplied = false;
        if ($previousTickRealSeconds === false || $previousTickRealSeconds === '') {
            putenv('TMC_TICK_REAL_SECONDS=3600');
            $tickRealSecondsApplied = true;
        }

        $startedAt = microtime(true);

        try {
            $sweepResult = PolicySweepRunner::run([
                'seed' => $seed,
                'players_per_archetype' => (int)$resolvedProfile['players_per_archetype'],
                'season_count' => (int)$resolvedProfile['season_count'],
                'simulators' => (array)$resolvedProfile['simulators'],
                'scenarios' => (array)$resolvedProfile['scenario_names'],
                'include_baseline' => (bool)$resolvedProfile['include_baseline'],
                'base_season_config_path' => $seasonConfigPath,
                'output_dir' => $sweepDir,
            ]);

            $compareResult = ResultComparator::run([
                'seed' => $seed,
                'sweep_manifest' => (string)$sweepResult['manifest_path'],
                'output_dir' => $comparatorDir,
            ]);
        } finally {
            if ($tickRealSecondsApplied) {
                putenv('TMC_TICK_REAL_SECONDS');
            } elseif ($previousTickRealSeconds !== false) {
                putenv('TMC_TICK_REAL_SECONDS=' . $previousTickRealSeconds);
            }
        }

        $totalDurationMs = self::msSince($startedAt);
        $sweepTiming = (array)($sweepResult['manifest']['timing_summary'] ?? []);
        $compareTiming = (array)($compareResult['payload']['timing_summary'] ?? []);

        $envelope = (array)($resolvedProfile['expected_completion_envelope'] ?? []);
        $maxMinutes = (float)($envelope['max_minutes'] ?? 0.0);
        $completionStatus = ($maxMinutes > 0.0 && $totalDurationMs > ($maxMinutes * 60_000.0))
            ? 'over-envelope'
            : 'within-envelope';

        $report = [
            'schema_version' => self::REPORT_SCHEMA_VERSION,
            'generated_at' => gmdate('c'),
            'seed' => $seed,
            'profile' => $resolvedProfile,
            'inputs' => [
                'season_config_path' => $seasonConfigPath,
                'tuning_candidates_path' => $tuningCandidatesPath,
                'tick_real_seconds' => $tickRealSecondsApplied ? '3600' : ($previousTickRealSeconds !== false ? $previousTickRealSeconds : null),
            ],
            'artifacts' => [
                'sweep_manifest_json' => (string)$sweepResult['manifest_path'],
                'comparison_json' => (string)$compareResult['json_path'],
            ],
            'timing_summary' => [
                'sweep_duration_ms' => (int)($sweepTiming['total_duration_ms'] ?? 0),
                'comparator_duration_ms' => (int)($compareTiming['total_duration_ms'] ?? 0),
                'total_duration_ms' => $totalDurationMs,
                'completion_status' => $completionStatus,
                'expected_completion_envelope' => $envelope,
            ],
            'summary' => [
                'sweep_run_count' => (int)($sweepResult['manifest']['timing_summary']['run_count'] ?? count((array)($sweepResult['manifest']['runs'] ?? []))),
                'scenario_count' => count((array)($compareResult['payload']['scenarios'] ?? [])),
                'rejected_scenario_count' => (int)($compareResult['payload']['summary']['rejected_scenario_count'] ?? 0),
            ],
        ];

        $reportJsonPath = $outputDir . DIRECTORY_SEPARATOR . 'sweep_comparator_report.json';
        $reportMdPath = $outputDir . DIRECTORY_SEPARATOR . 'sweep_comparator_report.md';
        file_put_contents($reportJsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($reportMdPath, self::buildMarkdownReport($report));

        $report['artifacts']['report_json'] = $reportJsonPath;
        $report['artifacts']['report_md'] = $reportMdPath;

        return [
            'profile' => $resolvedProfile,
            'sweep' => $sweepResult,
            'comparison' => $compareResult,
            'report' => $report,
            'report_json_path' => $reportJsonPath,
            'report_md_path' => $reportMdPath,
        ];
    }

    private static function applyOverrides(array $profile, array $options): array
    {
        $resolved = $profile;

        if (isset($options['players_per_archetype'])) {
            $resolved['players_per_archetype'] = max(1, (int)$options['players_per_archetype']);
        }
        if (isset($options['season_count'])) {
            $resolved['season_count'] = max(2, (int)$options['season_count']);
        }
        if (isset($options['simulators']) && is_array($options['simulators']) && $options['simulators'] !== []) {
            $resolved['simulators'] = array_values($options['simulators']);
        }
        if (isset($options['scenario_names']) && is_array($options['scenario_names']) && $options['scenario_names'] !== []) {
            $resolved['scenario_names'] = array_values($options['scenario_names']);
        }
        if (array_key_exists('include_baseline', $options) && $options['include_baseline'] !== null) {
            $resolved['include_baseline'] = (bool)$options['include_baseline'];
        }
        if (array_key_exists('tuning_candidates_path', $options)) {
            $resolved['tuning_candidates_path'] = $options['tuning_candidates_path'];
        }

        return $resolved;
    }

    private static function buildMarkdownReport(array $report): string
    {
        $profile = (array)($report['profile'] ?? []);
        $timing = (array)($report['timing_summary'] ?? []);
        $summary = (array)($report['summary'] ?? []);
        $artifacts = (array)($report['artifacts'] ?? []);
        $envelope = (array)($timing['expected_completion_envelope'] ?? []);

        $lines = [];
        $lines[] = '# Sweep Comparator Report';
        $lines[] = '';
        $lines[] = '- Seed: `' . (string)($report['seed'] ?? '') . '`';
        $lines[] = '- Profile: `' . (string)($profile['id'] ?? 'unknown') . '`';
        $lines[] = '- Description: ' . (string)($profile['description'] ?? 'n/a');
        $lines[] = '- Completion status: `' . (string)($timing['completion_status'] ?? 'unknown') . '`';
        $lines[] = '- Total duration: ' . self::formatMinutes((int)($timing['total_duration_ms'] ?? 0));
        if ($envelope !== []) {
            $lines[] = '- Expected envelope: '
                . self::formatEnvelope((float)($envelope['min_minutes'] ?? 0.0), (float)($envelope['max_minutes'] ?? 0.0))
                . ' (' . (string)($envelope['basis'] ?? 'measured basis unavailable') . ')';
        }
        $lines[] = '';
        $lines[] = '## Run Shape';
        $lines[] = '';
        $lines[] = '- Players per archetype: ' . (int)($profile['players_per_archetype'] ?? 0);
        $lines[] = '- Season count: ' . (int)($profile['season_count'] ?? 0);
        $lines[] = '- Simulators: ' . implode(', ', (array)($profile['simulators'] ?? []));
        $lines[] = '- Include baseline: ' . ((bool)($profile['include_baseline'] ?? false) ? 'yes' : 'no');
        $lines[] = '- Scenarios: ' . implode(', ', (array)($profile['scenario_names'] ?? []));
        $lines[] = '';
        $lines[] = '## Stage Durations';
        $lines[] = '';
        $lines[] = '- Sweep: ' . self::formatMinutes((int)($timing['sweep_duration_ms'] ?? 0));
        $lines[] = '- Comparator: ' . self::formatMinutes((int)($timing['comparator_duration_ms'] ?? 0));
        $lines[] = '- Total: ' . self::formatMinutes((int)($timing['total_duration_ms'] ?? 0));
        $lines[] = '';
        $lines[] = '## Outcome';
        $lines[] = '';
        $lines[] = '- Sweep run count: ' . (int)($summary['sweep_run_count'] ?? 0);
        $lines[] = '- Scenario count: ' . (int)($summary['scenario_count'] ?? 0);
        $lines[] = '- Rejected scenarios: ' . (int)($summary['rejected_scenario_count'] ?? 0);
        $lines[] = '- Sweep manifest: `' . (string)($artifacts['sweep_manifest_json'] ?? '') . '`';
        $lines[] = '- Comparator artifact: `' . (string)($artifacts['comparison_json'] ?? '') . '`';

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private static function formatEnvelope(float $minMinutes, float $maxMinutes): string
    {
        if ($minMinutes <= 0.0 && $maxMinutes <= 0.0) {
            return 'n/a';
        }

        return sprintf('%.1f-%.1f minutes', $minMinutes, $maxMinutes);
    }

    private static function formatMinutes(int $durationMs): string
    {
        return sprintf('%.2f minutes (%d ms)', $durationMs / 60_000.0, $durationMs);
    }

    private static function msSince(float $startedAt): int
    {
        return (int)round((microtime(true) - $startedAt) * 1000);
    }
}
