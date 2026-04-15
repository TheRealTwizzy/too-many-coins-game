<?php

use PHPUnit\Framework\TestCase;

// Speed up lifetime + sweep runs in test.
putenv('TMC_TICK_REAL_SECONDS=3600');

require_once __DIR__ . '/../scripts/simulation/SimulationSeason.php';
require_once __DIR__ . '/../scripts/simulation/SimulationRandom.php';
require_once __DIR__ . '/../scripts/simulation/Archetypes.php';
require_once __DIR__ . '/../scripts/simulation/PolicyBehavior.php';
require_once __DIR__ . '/../scripts/simulation/MetricsCollector.php';
require_once __DIR__ . '/../scripts/simulation/SimulationPlayer.php';
require_once __DIR__ . '/../scripts/simulation/ContractSimulator.php';
require_once __DIR__ . '/../scripts/simulation/SimulationPopulationSeason.php';
require_once __DIR__ . '/../scripts/simulation/SimulationPopulationLifetime.php';
require_once __DIR__ . '/../scripts/simulation/PolicyScenarioCatalog.php';
require_once __DIR__ . '/../scripts/simulation/PolicySweepRunner.php';
require_once __DIR__ . '/../scripts/simulation/ResultComparator.php';
require_once __DIR__ . '/../scripts/simulation/SimulationDeterminismNormalizer.php';

/**
 * Verifies that Simulations A, D, and E produce stable, identical outputs
 * when re-run with the same seed and equivalent inputs.
 *
 * Simulations B and C determinism are covered by SimulationSeasonSmokeTest
 * (testPopulationSimulationIsDeterministicForSameSeed) and
 * SimulationLifetimeSmokeTest (testLifetimeSimulationIsDeterministicForSameSeed).
 */
class SimulationDeterminismTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Simulation A — ContractSimulator
    // -------------------------------------------------------------------------

    public function testSimulationAIsFullyDeterministic(): void
    {
        $first = SimulationDeterminismNormalizer::normalizePayload(
            ContractSimulator::run('determinism-a')
        );
        $second = SimulationDeterminismNormalizer::normalizePayload(
            ContractSimulator::run('determinism-a')
        );

        $this->assertSame($first, $second, 'Simulation A produced different outputs on second run with the same seed.');
    }

    // -------------------------------------------------------------------------
    // Simulation D — PolicySweepRunner
    // -------------------------------------------------------------------------

    public function testSimulationDPayloadsAreDeterministicForSameSeed(): void
    {
        $outDir1 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tmc_det_d_run1_' . uniqid();
        $outDir2 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tmc_det_d_run2_' . uniqid();

        $opts = [
            'seed' => 'determinism-d',
            'players_per_archetype' => 1,
            'season_count' => 4,
            'simulators' => ['B', 'C'],
            'scenarios' => ['hoarder-pressure-v1'],
            'include_baseline' => true,
        ];

        $r1 = PolicySweepRunner::run(array_merge($opts, ['output_dir' => $outDir1]));
        $r2 = PolicySweepRunner::run(array_merge($opts, ['output_dir' => $outDir2]));

        $this->assertSame(
            SimulationDeterminismNormalizer::normalizePolicySweepResult($r1),
            SimulationDeterminismNormalizer::normalizePolicySweepResult($r2),
            'Simulation D manifest/result metadata drifted despite identical semantic inputs.'
        );

        $runs1 = (array)$r1['manifest']['runs'];
        $runs2 = (array)$r2['manifest']['runs'];

        $this->assertCount(count($runs1), $runs2, 'Run count differs between sweeps with the same seed.');

        foreach ($runs1 as $i => $run1entry) {
            $p1 = SimulationDeterminismNormalizer::normalizePayload(
                json_decode((string)file_get_contents((string)$run1entry['json']), true)
            );
            $p2 = SimulationDeterminismNormalizer::normalizePayload(
                json_decode((string)file_get_contents((string)$runs2[$i]['json']), true)
            );

            $label = sprintf(
                'Simulation D payload mismatch on run %d (scenario=%s, sim=%s)',
                $i,
                (string)$run1entry['scenario_name'],
                (string)$run1entry['simulator_type']
            );
            $this->assertSame($p1, $p2, $label);
        }
    }

    public function testSimulationDManifestConfigIsStableAcrossRuns(): void
    {
        $outDir1 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tmc_det_d_cfg1_' . uniqid();
        $outDir2 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tmc_det_d_cfg2_' . uniqid();

        $opts = [
            'seed' => 'determinism-d-cfg',
            'players_per_archetype' => 1,
            'season_count' => 4,
            'simulators' => ['B'],
            'scenarios' => ['mostly-idle-pressure-v1'],
            'include_baseline' => false,
        ];

        $r1 = PolicySweepRunner::run(array_merge($opts, ['output_dir' => $outDir1]));
        $r2 = PolicySweepRunner::run(array_merge($opts, ['output_dir' => $outDir2]));

        $cfg1 = (array)$r1['manifest']['config'];
        $cfg2 = (array)$r2['manifest']['config'];

        // base_season_config_path will both be null (no config supplied); paths
        // output_dir differs by test run, so compare only structural fields.
        $this->assertSame($cfg1['simulators'], $cfg2['simulators']);
        $this->assertSame($cfg1['include_baseline'], $cfg2['include_baseline']);
        $this->assertSame($cfg1['players_per_archetype'], $cfg2['players_per_archetype']);
        $this->assertSame($cfg1['season_count'], $cfg2['season_count']);
        $this->assertSame($cfg1['scenario_names'], $cfg2['scenario_names']);
    }

    public function testSimulationDSemanticDriftStillFailsDeterminismComparison(): void
    {
        $outDir1 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tmc_det_d_drift1_' . uniqid();
        $outDir2 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tmc_det_d_drift2_' . uniqid();

        $stable = PolicySweepRunner::run([
            'seed' => 'determinism-d-drift',
            'players_per_archetype' => 1,
            'season_count' => 4,
            'simulators' => ['B'],
            'scenarios' => [],
            'include_baseline' => true,
            'output_dir' => $outDir1,
        ]);
        $drifted = PolicySweepRunner::run([
            'seed' => 'determinism-d-drift',
            'players_per_archetype' => 2,
            'season_count' => 4,
            'simulators' => ['B'],
            'scenarios' => [],
            'include_baseline' => true,
            'output_dir' => $outDir2,
        ]);

        $stablePayload = SimulationDeterminismNormalizer::normalizePayload(
            json_decode((string)file_get_contents((string)$stable['manifest']['runs'][0]['json']), true)
        );
        $driftedPayload = SimulationDeterminismNormalizer::normalizePayload(
            json_decode((string)file_get_contents((string)$drifted['manifest']['runs'][0]['json']), true)
        );

        $this->assertNotSame(
            $stablePayload,
            $driftedPayload,
            'Semantic drift must still fail determinism comparison after normalization.'
        );
    }

    public function testSimulationDNormalizationDoesNotHideMeaningfulOutputDifferences(): void
    {
        $outDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tmc_det_d_visible_' . uniqid();
        $result = PolicySweepRunner::run([
            'seed' => 'determinism-d-visible',
            'players_per_archetype' => 1,
            'season_count' => 4,
            'simulators' => ['B'],
            'scenarios' => [],
            'include_baseline' => true,
            'output_dir' => $outDir,
        ]);

        $payload = json_decode((string)file_get_contents((string)$result['manifest']['runs'][0]['json']), true);
        $mutated = $payload;
        $mutated['diagnostics']['natural_expiry_count'] = (int)($mutated['diagnostics']['natural_expiry_count'] ?? 0) + 1;

        $this->assertNotSame(
            SimulationDeterminismNormalizer::normalizePayload($payload),
            SimulationDeterminismNormalizer::normalizePayload($mutated),
            'Normalization must not erase meaningful output deltas.'
        );
    }

    // -------------------------------------------------------------------------
    // Simulation E — ResultComparator
    // -------------------------------------------------------------------------

    public function testSimulationEIsFullyDeterministic(): void
    {
        // Build shared fixture files written once; re-used for both comparator calls.
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tmc_det_e_' . uniqid();
        mkdir($tempDir, 0777, true);

        $bBaseline = $tempDir . DIRECTORY_SEPARATOR . 'b_baseline.json';
        $bScenario  = $tempDir . DIRECTORY_SEPARATOR . 'b_scenario.json';
        $cBaseline  = $tempDir . DIRECTORY_SEPARATOR . 'c_baseline.json';
        $cScenario  = $tempDir . DIRECTORY_SEPARATOR . 'c_scenario.json';
        $manifest   = $tempDir . DIRECTORY_SEPARATOR . 'manifest.json';
        $out1 = $tempDir . DIRECTORY_SEPARATOR . 'out1';
        $out2 = $tempDir . DIRECTORY_SEPARATOR . 'out2';

        $this->writeFakeSeasonPayload($bBaseline, ['star_focused' => 2000, 'hoarder' => 1800, 'top10' => 0.20]);
        $this->writeFakeSeasonPayload($bScenario,  ['star_focused' => 1600, 'hoarder' => 1600, 'top10' => 0.18]);
        $this->writeFakeLifetimePayload($cBaseline, ['star_focused' => 8000, 'hoarder' => 9000, 'top10' => 0.22, 'lock_rate' => 0.90]);
        $this->writeFakeLifetimePayload($cScenario,  ['star_focused' => 7500, 'hoarder' => 9500, 'top10' => 0.24, 'lock_rate' => 0.87]);

        file_put_contents($manifest, json_encode([
            'schema_version' => MetricsCollector::SCHEMA_VERSION,
            'runs' => [
                ['scenario_name' => 'baseline', 'simulator_type' => 'B', 'seed' => 'e-det|B', 'is_baseline' => true, 'cohort' => ['players_per_archetype' => 1], 'horizon' => ['season_count' => 1], 'override_categories' => [], 'override_keys' => [], 'json' => $bBaseline],
                ['scenario_name' => 'baseline', 'simulator_type' => 'C', 'seed' => 'e-det|C', 'is_baseline' => true, 'cohort' => ['players_per_archetype' => 1], 'horizon' => ['season_count' => 4], 'override_categories' => [], 'override_keys' => [], 'json' => $cBaseline],
                ['scenario_name' => 'hoarder-pressure-v1', 'simulator_type' => 'B', 'seed' => 'e-det-s|B', 'is_baseline' => false, 'cohort' => ['players_per_archetype' => 1], 'horizon' => ['season_count' => 1], 'override_categories' => ['hoarding_preservation_pressure'], 'override_keys' => ['hoarding_tier1_rate_hourly_fp'], 'json' => $bScenario],
                ['scenario_name' => 'hoarder-pressure-v1', 'simulator_type' => 'C', 'seed' => 'e-det-s|C', 'is_baseline' => false, 'cohort' => ['players_per_archetype' => 1], 'horizon' => ['season_count' => 4], 'override_categories' => ['hoarding_preservation_pressure'], 'override_keys' => ['hoarding_tier1_rate_hourly_fp'], 'json' => $cScenario],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $commonOpts = ['seed' => 'determinism-e', 'sweep_manifest' => $manifest, 'baseline_b_paths' => [], 'baseline_c_paths' => []];

        $r1 = ResultComparator::run(array_merge($commonOpts, ['output_dir' => $out1]));
        $r2 = ResultComparator::run(array_merge($commonOpts, ['output_dir' => $out2]));

        $p1 = SimulationDeterminismNormalizer::normalizePayload((array)$r1['payload']);
        $p2 = SimulationDeterminismNormalizer::normalizePayload((array)$r2['payload']);

        $this->assertSame($p1, $p2, 'Simulation E produced different outputs on second run with the same inputs.');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function writeFakeSeasonPayload(string $path, array $vals): void
    {
        $sf = (int)($vals['star_focused'] ?? 2000);
        $ho = (int)($vals['hoarder'] ?? 1800);
        $top10 = (float)($vals['top10'] ?? 0.20);

        $archOrders = ['Star-Focused', 'Hoarder', 'Regular', 'Casual', 'Boost-Focused',
                       'Mostly Idle', 'Early Locker', 'Late Deployer', 'Hardcore', 'Aggressive Sigil User'];
        $archetypes = [];
        $rank = 1;
        foreach ($archOrders as $label) {
            $key = strtolower(str_replace(['- ', ' '], ['_', '_'], $label));
            $val = match ($label) {
                'Star-Focused' => $sf,
                'Hoarder' => $ho,
                default => 500,
            };
            $archetypes[$key] = ['global_stars_gained' => $val, 'final_rank_distribution' => [$rank]];
            $rank++;
        }

        file_put_contents($path, json_encode([
            'schema_version' => MetricsCollector::SCHEMA_VERSION,
            'simulator' => 'single-season-population',
            'seed' => 'fake-b',
            'archetypes' => $archetypes,
            'diagnostics' => [
                'lock_in_timing' => ['EARLY' => 1, 'MID' => 1, 'LATE_ACTIVE' => 0, 'BLACKOUT' => 0],
                'natural_expiry_count' => 1,
                'late_active_engaged_rate' => 0.60,
                't6_total_acquired' => 2,
                't6_via_drop' => 1,
                't6_via_combine' => 1,
                't6_via_theft' => 0,
                'concentration' => ['top10_share' => $top10],
            ],
            'config' => ['total_players' => 10, 'players_per_archetype' => 1],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function writeFakeLifetimePayload(string $path, array $vals): void
    {
        $sf = (int)($vals['star_focused'] ?? 8000);
        $ho = (int)($vals['hoarder'] ?? 9000);
        $top10 = (float)($vals['top10'] ?? 0.22);
        $lockRate = (float)($vals['lock_rate'] ?? 0.90);

        $archOrders = ['Star-Focused', 'Hoarder', 'Regular', 'Casual', 'Boost-Focused',
                       'Mostly Idle', 'Early Locker', 'Late Deployer', 'Hardcore', 'Aggressive Sigil User'];
        $players = [];
        $pid = 1;
        foreach ($archOrders as $label) {
            $key = strtolower(str_replace(['- ', ' '], ['_', '_'], $label));
            $players[] = [
                'player_id' => $pid++,
                'archetype_key' => $key,
                'archetype_label' => $label,
                'cumulative_global_stars' => match ($label) { 'Star-Focused' => $sf, 'Hoarder' => $ho, default => 500 },
                'seasons_entered' => 4,
                'seasons_skipped' => 0,
                'lock_in_count' => 3,
                'natural_expiry_count' => 1,
            ];
        }

        file_put_contents($path, json_encode([
            'schema_version' => MetricsCollector::SCHEMA_VERSION,
            'simulator' => 'lifetime-overlapping-season',
            'seed' => 'fake-c',
            'players' => $players,
            'season_timeline' => [
                ['season_seq' => 1, 'lock_ins' => 3, 'natural_expiry' => 1, 'late_active_engaged_rate' => 0.55, 't6_total' => 1],
                ['season_seq' => 2, 'lock_ins' => 3, 'natural_expiry' => 1, 'late_active_engaged_rate' => 0.55, 't6_total' => 1],
                ['season_seq' => 3, 'lock_ins' => 3, 'natural_expiry' => 1, 'late_active_engaged_rate' => 0.55, 't6_total' => 1],
                ['season_seq' => 4, 'lock_ins' => 3, 'natural_expiry' => 1, 'late_active_engaged_rate' => 0.55, 't6_total' => 1],
            ],
            'concentration_drift' => [
                ['season_seq' => 4, 'top10_share' => $top10, 'top1_share' => 0.06, 'total_ranked' => 10],
            ],
            'population_diagnostics' => [
                'throughput_lock_in_rate' => $lockRate,
                'skip_strategy_edge' => 500,
                'highest_compounding_archetype' => 'Hoarder',
                'average_cumulative_global_stars' => 900,
                'total_cumulative_global_stars' => 9000,
            ],
            'config' => ['total_players' => 10, 'players_per_archetype' => 1, 'season_count' => 4],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
