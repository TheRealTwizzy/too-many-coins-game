<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/simulation/MetricsCollector.php';
require_once __DIR__ . '/../scripts/simulation/ResultComparator.php';

class SimulationResultComparatorSmokeTest extends TestCase
{
    public function testComparatorIngestsSweepAndEmitsDeltaFlagsAndMetadata(): void
    {
        $tempDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'tmc_comparator_smoke_' . uniqid();
        mkdir($tempDir, 0777, true);
        $runsDir = $tempDir . DIRECTORY_SEPARATOR . 'runs';
        mkdir($runsDir, 0777, true);

        $baselineBPath = $runsDir . DIRECTORY_SEPARATOR . 'baseline_b.json';
        $scenarioBPath = $runsDir . DIRECTORY_SEPARATOR . 'scenario_b.json';
        $baselineCPath = $runsDir . DIRECTORY_SEPARATOR . 'baseline_c.json';
        $scenarioCPath = $runsDir . DIRECTORY_SEPARATOR . 'scenario_c.json';

        file_put_contents($baselineBPath, json_encode($this->fakeSeasonPayload('baseline-b', [
            'star_focused' => 2000,
            'hoarder' => 1800,
            'boost_focused' => 200,
            'mostly_idle' => 800,
            'natural_expiry' => 1,
            'late_active_rate' => 0.55,
            't6_total' => 3,
            't6_drop' => 2,
            't6_combine' => 1,
            't6_theft' => 0,
        ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        file_put_contents($scenarioBPath, json_encode($this->fakeSeasonPayload('scenario-b', [
            'star_focused' => 1500,
            'hoarder' => 1600,
            'boost_focused' => 400,
            'mostly_idle' => 600,
            'natural_expiry' => 3,
            'late_active_rate' => 0.62,
            't6_total' => 9,
            't6_drop' => 5,
            't6_combine' => 2,
            't6_theft' => 2,
        ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        file_put_contents($baselineCPath, json_encode($this->fakeLifetimePayload('baseline-c', [
            'star_focused' => 9000,
            'hoarder' => 9200,
            'boost_focused' => 2200,
            'mostly_idle' => 5000,
            'natural_expiry' => 8,
            'late_active_rate' => 0.50,
            't6_total' => 4,
            'skip_edge' => 800,
            'top10_share' => 0.22,
            'top1_share' => 0.06,
            'lock_rate' => 0.90,
        ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        file_put_contents($scenarioCPath, json_encode($this->fakeLifetimePayload('scenario-c', [
            'star_focused' => 8200,
            'hoarder' => 8800,
            'boost_focused' => 2600,
            'mostly_idle' => 4300,
            'natural_expiry' => 11,
            'late_active_rate' => 0.57,
            't6_total' => 9,
            'skip_edge' => 1200,
            'top10_share' => 0.27,
            'top1_share' => 0.07,
            'lock_rate' => 0.87,
        ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $manifestPath = $tempDir . DIRECTORY_SEPARATOR . 'sweep_manifest.json';
        $manifest = [
            'schema_version' => MetricsCollector::SCHEMA_VERSION,
            'runs' => [
                [
                    'scenario_name' => 'baseline',
                    'simulator_type' => 'B',
                    'seed' => 'baseline|B',
                    'is_baseline' => true,
                    'cohort' => ['players_per_archetype' => 1],
                    'horizon' => ['season_count' => 1],
                    'override_categories' => [],
                    'override_keys' => [],
                    'json' => $baselineBPath,
                ],
                [
                    'scenario_name' => 'baseline',
                    'simulator_type' => 'C',
                    'seed' => 'baseline|C',
                    'is_baseline' => true,
                    'cohort' => ['players_per_archetype' => 1],
                    'horizon' => ['season_count' => 4],
                    'override_categories' => [],
                    'override_keys' => [],
                    'json' => $baselineCPath,
                ],
                [
                    'scenario_name' => 'candidate-x',
                    'simulator_type' => 'B',
                    'seed' => 'candidate|B',
                    'is_baseline' => false,
                    'cohort' => ['players_per_archetype' => 1],
                    'horizon' => ['season_count' => 1],
                    'override_categories' => ['boost_related'],
                    'override_keys' => ['target_spend_rate_per_tick'],
                    'json' => $scenarioBPath,
                ],
                [
                    'scenario_name' => 'candidate-x',
                    'simulator_type' => 'C',
                    'seed' => 'candidate|C',
                    'is_baseline' => false,
                    'cohort' => ['players_per_archetype' => 1],
                    'horizon' => ['season_count' => 4],
                    'override_categories' => ['boost_related'],
                    'override_keys' => ['target_spend_rate_per_tick'],
                    'json' => $scenarioCPath,
                ],
            ],
        ];
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $result = ResultComparator::run([
            'seed' => 'comparator-smoke',
            'sweep_manifest' => $manifestPath,
            'output_dir' => $tempDir,
        ]);

        $this->assertFileExists((string)$result['json_path']);
        $payload = (array)$result['payload'];
        $this->assertSame('tmc-sim-comparator.v1', (string)$payload['comparator_schema_version']);
        $this->assertCount(1, (array)$payload['scenarios']);

        $scenario = (array)$payload['scenarios'][0];
        $this->assertSame('candidate-x', (string)$scenario['scenario_name']);
        $this->assertArrayHasKey('simulator_comparisons', $scenario);
        $this->assertNotEmpty((array)$scenario['simulator_comparisons']);

        $firstComparison = (array)$scenario['simulator_comparisons'][0];
        $this->assertArrayHasKey('delta_flags', $firstComparison);
        $this->assertArrayHasKey('regression_flags', $firstComparison);
        $this->assertArrayHasKey('metadata', $firstComparison);

        $allFlags = [];
        foreach ((array)$scenario['simulator_comparisons'] as $comparison) {
            foreach ((array)($comparison['regression_flags'] ?? []) as $flag) {
                $allFlags[(string)$flag] = true;
            }
        }

        $this->assertArrayHasKey('engagement_up_but_t6_supply_spike', $allFlags);
        $this->assertArrayHasKey('long_run_concentration_worsened', $allFlags);
    }

    private function fakeSeasonPayload(string $seed, array $v): array
    {
        return [
            'schema_version' => MetricsCollector::SCHEMA_VERSION,
            'simulator' => 'single-season-population',
            'seed' => $seed,
            'config' => ['players_per_archetype' => 1, 'total_players' => 10],
            'diagnostics' => [
                'lock_in_timing' => ['EARLY' => 0, 'MID' => 4, 'LATE_ACTIVE' => 5, 'BLACKOUT' => 0, 'NONE' => 1],
                'natural_expiry_count' => (int)$v['natural_expiry'],
                'late_active_engaged_rate' => (float)$v['late_active_rate'],
            ],
            'archetypes' => [
                'star_focused' => ['label' => 'Star-Focused', 'global_stars_gained' => (int)$v['star_focused'], 't6_total_acquired' => 1, 't6_by_source' => ['drop' => (int)$v['t6_drop'], 'combine' => 0, 'theft' => 0], 'natural_expiry_count' => 0],
                'hoarder' => ['label' => 'Hoarder', 'global_stars_gained' => (int)$v['hoarder'], 't6_total_acquired' => 1, 't6_by_source' => ['drop' => 0, 'combine' => (int)$v['t6_combine'], 'theft' => 0], 'natural_expiry_count' => 0],
                'boost_focused' => ['label' => 'Boost-Focused', 'global_stars_gained' => (int)$v['boost_focused'], 't6_total_acquired' => 1, 't6_by_source' => ['drop' => 0, 'combine' => 0, 'theft' => (int)$v['t6_theft']], 'natural_expiry_count' => (int)$v['natural_expiry']],
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
}
