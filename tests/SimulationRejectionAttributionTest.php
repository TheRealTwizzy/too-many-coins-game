<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/simulation/MetricsCollector.php';
require_once __DIR__ . '/../scripts/simulation/ResultComparator.php';

class SimulationRejectionAttributionTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tmc_rejection_attr_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->tempDir);
    }

    public function testRejectedSingleKnobRunWritesAttributionReportWithBaselineAndRanking(): void
    {
        $runsDir = $this->tempDir . DIRECTORY_SEPARATOR . 'runs';
        mkdir($runsDir, 0777, true);

        $baselineBPath = $runsDir . DIRECTORY_SEPARATOR . 'baseline_b.json';
        $scenarioBPath = $runsDir . DIRECTORY_SEPARATOR . 'scenario_b.json';
        $baselineAuditPath = $runsDir . DIRECTORY_SEPARATOR . 'baseline_b_effective_config.json';
        $scenarioAuditPath = $runsDir . DIRECTORY_SEPARATOR . 'scenario_b_effective_config.json';

        file_put_contents($baselineBPath, json_encode($this->fakeSeasonPayload('baseline-b', [
            'lock_ins' => ['EARLY' => 1, 'MID' => 4, 'LATE_ACTIVE' => 3, 'BLACKOUT' => 0, 'NONE' => 0],
            'star_focused' => 1500,
            'hoarder' => 1700,
            'boost_focused' => 300,
            'mostly_idle' => 800,
            'natural_expiry' => 1,
            'late_active_rate' => 0.55,
            't6_total' => 2,
            't6_drop' => 1,
            't6_combine' => 1,
            't6_theft' => 0,
        ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        file_put_contents($scenarioBPath, json_encode($this->fakeSeasonPayload('scenario-b', [
            'lock_ins' => ['EARLY' => 0, 'MID' => 2, 'LATE_ACTIVE' => 1, 'BLACKOUT' => 0, 'NONE' => 5],
            'star_focused' => 1400,
            'hoarder' => 1750,
            'boost_focused' => 280,
            'mostly_idle' => 780,
            'natural_expiry' => 4,
            'late_active_rate' => 0.52,
            't6_total' => 2,
            't6_drop' => 1,
            't6_combine' => 1,
            't6_theft' => 0,
        ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        file_put_contents($baselineAuditPath, json_encode($this->fakeAuditReport([], [
            'hoarding_min_factor_fp' => 100000,
        ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        file_put_contents($scenarioAuditPath, json_encode($this->fakeAuditReport([
            [
                'path' => 'season.hoarding_min_factor_fp',
                'requested_value' => 180000,
                'effective_value' => 180000,
                'effective_source' => 'candidate_patch',
                'is_active' => true,
                'reason_code' => null,
                'reason_detail' => null,
            ],
        ], [
            'hoarding_min_factor_fp' => 180000,
        ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $manifestPath = $this->tempDir . DIRECTORY_SEPARATOR . 'single_manifest.json';
        file_put_contents($manifestPath, json_encode([
            'schema_version' => MetricsCollector::SCHEMA_VERSION,
            'runs' => [
                $this->manifestRun('baseline', 'B', true, $baselineBPath, [
                    'effective_config_json' => $baselineAuditPath,
                ]),
                $this->manifestRun('candidate-single', 'B', false, $scenarioBPath, [
                    'effective_config_json' => $scenarioAuditPath,
                ], ['hoarding_min_factor_fp']),
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $result = ResultComparator::run([
            'seed' => 'attrib-single',
            'sweep_manifest' => $manifestPath,
            'output_dir' => $this->tempDir,
        ]);

        $scenario = (array)$result['payload']['scenarios'][0];
        $this->assertSame('reject', (string)$scenario['recommended_disposition']);

        $attribution = (array)$scenario['rejection_attribution'];
        $jsonPath = (string)$attribution['artifact_paths']['rejection_attribution_json'];
        $mdPath = (string)$attribution['artifact_paths']['rejection_attribution_md'];
        $this->assertFileExists($jsonPath);
        $this->assertFileExists($mdPath);

        $report = json_decode((string)file_get_contents($jsonPath), true);
        $this->assertSame('tmc-sim-rejection-attribution.v1', (string)$report['schema_version']);
        $this->assertSame('candidate-single', (string)$report['scenario_name']);
        $this->assertSame('lock_in_down_but_expiry_dominance_up', (string)$report['primary_failed_gate']['flag']);
        $this->assertFalse((bool)$report['interaction_ambiguity']['present']);
        $this->assertCount(1, (array)$report['changed_knobs']);
        $this->assertSame('season.hoarding_min_factor_fp', (string)$report['changed_knobs'][0]['path']);
        $this->assertSame('active', (string)$report['changed_knobs'][0]['classification']);
        $this->assertSame(100000, $report['changed_knobs'][0]['baseline_value']);
        $this->assertSame(180000, $report['changed_knobs'][0]['candidate_effective_value']);
        $this->assertSame('season.hoarding_min_factor_fp', (string)$report['likely_causal_knob_ranking'][0]['path']);
        $this->assertSame('moderate', (string)$report['likely_causal_knob_ranking'][0]['confidence']);

        $markdown = (string)file_get_contents($mdPath);
        $this->assertStringContainsString('# Rejection Attribution', $markdown);
        $this->assertStringContainsString('season.hoarding_min_factor_fp', $markdown);
    }

    public function testBundledKnobFailureReportsInteractionAmbiguityExplicitly(): void
    {
        $runsDir = $this->tempDir . DIRECTORY_SEPARATOR . 'runs_bundle';
        mkdir($runsDir, 0777, true);

        $baselineBPath = $runsDir . DIRECTORY_SEPARATOR . 'baseline_b.json';
        $scenarioBPath = $runsDir . DIRECTORY_SEPARATOR . 'scenario_b.json';
        $baselineCPath = $runsDir . DIRECTORY_SEPARATOR . 'baseline_c.json';
        $scenarioCPath = $runsDir . DIRECTORY_SEPARATOR . 'scenario_c.json';
        $baselineAuditPath = $runsDir . DIRECTORY_SEPARATOR . 'baseline_effective_config.json';
        $scenarioAuditPath = $runsDir . DIRECTORY_SEPARATOR . 'scenario_effective_config.json';

        file_put_contents($baselineBPath, json_encode($this->fakeSeasonPayload('baseline-b', [
            'lock_ins' => ['EARLY' => 1, 'MID' => 4, 'LATE_ACTIVE' => 4, 'BLACKOUT' => 0, 'NONE' => 1],
            'star_focused' => 1700,
            'hoarder' => 1900,
            'boost_focused' => 400,
            'mostly_idle' => 900,
            'natural_expiry' => 1,
            'late_active_rate' => 0.58,
            't6_total' => 2,
            't6_drop' => 1,
            't6_combine' => 1,
            't6_theft' => 0,
        ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        file_put_contents($scenarioBPath, json_encode($this->fakeSeasonPayload('scenario-b', [
            'lock_ins' => ['EARLY' => 0, 'MID' => 2, 'LATE_ACTIVE' => 2, 'BLACKOUT' => 0, 'NONE' => 6],
            'star_focused' => 1550,
            'hoarder' => 2100,
            'boost_focused' => 650,
            'mostly_idle' => 820,
            'natural_expiry' => 5,
            'late_active_rate' => 0.54,
            't6_total' => 2,
            't6_drop' => 1,
            't6_combine' => 1,
            't6_theft' => 0,
        ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        file_put_contents($baselineCPath, json_encode($this->fakeLifetimePayload('baseline-c', [
            'star_focused' => 8800,
            'hoarder' => 9000,
            'boost_focused' => 2600,
            'mostly_idle' => 4700,
            'natural_expiry' => 7,
            'late_active_rate' => 0.52,
            't6_total' => 3,
            'skip_edge' => 500,
            'top10_share' => 0.23,
            'top1_share' => 0.06,
            'lock_rate' => 0.90,
        ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        file_put_contents($scenarioCPath, json_encode($this->fakeLifetimePayload('scenario-c', [
            'star_focused' => 8200,
            'hoarder' => 9600,
            'boost_focused' => 3200,
            'mostly_idle' => 4300,
            'natural_expiry' => 9,
            'late_active_rate' => 0.51,
            't6_total' => 3,
            'skip_edge' => 650,
            'top10_share' => 0.26,
            'top1_share' => 0.08,
            'lock_rate' => 0.86,
        ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        file_put_contents($baselineAuditPath, json_encode($this->fakeAuditReport([], [
            'hoarding_sink_enabled' => 0,
            'base_ubi_active_per_tick' => 30,
        ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        file_put_contents($scenarioAuditPath, json_encode($this->fakeAuditReport([
            [
                'path' => 'season.hoarding_sink_enabled',
                'requested_value' => 1,
                'effective_value' => 1,
                'effective_source' => 'candidate_patch',
                'is_active' => true,
                'reason_code' => null,
                'reason_detail' => null,
            ],
            [
                'path' => 'season.base_ubi_active_per_tick',
                'requested_value' => 38,
                'effective_value' => 38,
                'effective_source' => 'candidate_patch',
                'is_active' => true,
                'reason_code' => null,
                'reason_detail' => null,
            ],
        ], [
            'hoarding_sink_enabled' => 1,
            'base_ubi_active_per_tick' => 38,
        ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $manifestPath = $this->tempDir . DIRECTORY_SEPARATOR . 'bundle_manifest.json';
        file_put_contents($manifestPath, json_encode([
            'schema_version' => MetricsCollector::SCHEMA_VERSION,
            'runs' => [
                $this->manifestRun('baseline', 'B', true, $baselineBPath, [
                    'effective_config_json' => $baselineAuditPath,
                ]),
                $this->manifestRun('baseline', 'C', true, $baselineCPath, [
                    'effective_config_json' => $baselineAuditPath,
                ]),
                $this->manifestRun('candidate-bundle', 'B', false, $scenarioBPath, [
                    'effective_config_json' => $scenarioAuditPath,
                ], ['hoarding_sink_enabled', 'base_ubi_active_per_tick']),
                $this->manifestRun('candidate-bundle', 'C', false, $scenarioCPath, [
                    'effective_config_json' => $scenarioAuditPath,
                ], ['hoarding_sink_enabled', 'base_ubi_active_per_tick']),
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $result = ResultComparator::run([
            'seed' => 'attrib-bundle',
            'sweep_manifest' => $manifestPath,
            'output_dir' => $this->tempDir,
        ]);

        $scenario = (array)$result['payload']['scenarios'][0];
        $this->assertSame('reject', (string)$scenario['recommended_disposition']);

        $report = json_decode((string)file_get_contents((string)$scenario['rejection_attribution']['artifact_paths']['rejection_attribution_json']), true);
        $this->assertTrue((bool)$report['interaction_ambiguity']['present']);
        $this->assertSame('multi_knob_bundle', (string)$report['interaction_ambiguity']['type']);
        $this->assertCount(2, (array)$report['changed_knobs']);
        $this->assertCount(2, (array)$report['likely_causal_knob_ranking']);
        $this->assertSame('active', (string)$report['changed_knobs'][0]['classification']);
        $this->assertSame('active', (string)$report['changed_knobs'][1]['classification']);
        $this->assertStringContainsString('active knobs changed together', implode(' ', (array)$report['confidence_notes']));

        $rankedPaths = array_map(static fn(array $row): string => (string)$row['path'], (array)$report['likely_causal_knob_ranking']);
        $this->assertContains('season.hoarding_sink_enabled', $rankedPaths);
        $this->assertContains('season.base_ubi_active_per_tick', $rankedPaths);

        $markdown = (string)file_get_contents((string)$scenario['rejection_attribution']['artifact_paths']['rejection_attribution_md']);
        $this->assertStringContainsString('Interaction ambiguity: present', $markdown);
    }

    private function manifestRun(
        string $scenarioName,
        string $simulatorType,
        bool $isBaseline,
        string $jsonPath,
        array $configAudit,
        array $overrideKeys = []
    ): array {
        return [
            'scenario_name' => $scenarioName,
            'simulator_type' => $simulatorType,
            'seed' => $scenarioName . '|' . $simulatorType,
            'is_baseline' => $isBaseline,
            'cohort' => ['players_per_archetype' => 1],
            'horizon' => ['season_count' => $simulatorType === 'C' ? 4 : 1],
            'override_categories' => [],
            'override_keys' => $overrideKeys,
            'json' => $jsonPath,
            'config_audit' => $configAudit,
        ];
    }

    private function fakeAuditReport(array $changes, array $seasonValues): array
    {
        return [
            'schema_version' => 'tmc-effective-config.v1',
            'status' => 'pass',
            'requested_candidate_changes' => $changes,
            'effective_config' => [
                'runtime' => [],
                'season' => $seasonValues,
            ],
        ];
    }

    private function fakeSeasonPayload(string $seed, array $v): array
    {
        return [
            'schema_version' => MetricsCollector::SCHEMA_VERSION,
            'simulator' => 'single-season-population',
            'seed' => $seed,
            'config' => ['players_per_archetype' => 1, 'total_players' => 10],
            'diagnostics' => [
                'lock_in_timing' => (array)$v['lock_ins'],
                'natural_expiry_count' => (int)$v['natural_expiry'],
                'late_active_engaged_rate' => (float)$v['late_active_rate'],
            ],
            'archetypes' => [
                'star_focused' => ['label' => 'Star-Focused', 'global_stars_gained' => (int)$v['star_focused'], 't6_total_acquired' => 1, 't6_by_source' => ['drop' => (int)$v['t6_drop'], 'combine' => 0, 'theft' => 0], 'natural_expiry_count' => 0],
                'hoarder' => ['label' => 'Hoarder', 'global_stars_gained' => (int)$v['hoarder'], 't6_total_acquired' => 1, 't6_by_source' => ['drop' => 0, 'combine' => (int)$v['t6_combine'], 'theft' => 0], 'natural_expiry_count' => 0],
                'boost_focused' => ['label' => 'Boost-Focused', 'global_stars_gained' => (int)$v['boost_focused'], 't6_total_acquired' => 0, 't6_by_source' => ['drop' => 0, 'combine' => 0, 'theft' => (int)$v['t6_theft']], 'natural_expiry_count' => (int)$v['natural_expiry']],
                'mostly_idle' => ['label' => 'Mostly Idle', 'global_stars_gained' => (int)$v['mostly_idle'], 't6_total_acquired' => 0, 't6_by_source' => ['drop' => 0, 'combine' => 0, 'theft' => 0], 'natural_expiry_count' => 0],
            ],
        ];
    }

    private function fakeLifetimePayload(string $seed, array $v): array
    {
        return [
            'schema_version' => MetricsCollector::SCHEMA_VERSION,
            'simulator' => 'lifetime-overlapping-season',
            'seed' => $seed,
            'config' => ['players_per_archetype' => 1, 'total_players' => 10, 'season_count' => 4],
            'season_timeline' => [
                ['late_active_engaged_rate' => (float)$v['late_active_rate'], 't6_total' => (int)$v['t6_total']],
            ],
            'archetypes' => [
                'star_focused' => ['label' => 'Star-Focused', 'cumulative_global_stars_avg' => (float)$v['star_focused'], 'natural_expiry_count_sum' => 0],
                'hoarder' => ['label' => 'Hoarder', 'cumulative_global_stars_avg' => (float)$v['hoarder'], 'natural_expiry_count_sum' => 0],
                'boost_focused' => ['label' => 'Boost-Focused', 'cumulative_global_stars_avg' => (float)$v['boost_focused'], 'natural_expiry_count_sum' => (int)$v['natural_expiry']],
                'mostly_idle' => ['label' => 'Mostly Idle', 'cumulative_global_stars_avg' => (float)$v['mostly_idle'], 'natural_expiry_count_sum' => 0],
            ],
            'concentration_drift' => [
                ['top_10_percent_share' => (float)$v['top10_share'], 'top_1_percent_share' => (float)$v['top1_share']],
            ],
            'population_diagnostics' => [
                'skip_strategy_edge' => (float)$v['skip_edge'],
                'throughput_lock_in_rate' => (float)$v['lock_rate'],
            ],
        ];
    }

    private function deleteDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($fullPath)) {
                $this->deleteDir($fullPath);
                continue;
            }

            @unlink($fullPath);
        }

        @rmdir($path);
    }
}
