<?php

use PHPUnit\Framework\TestCase;

putenv('TMC_TICK_REAL_SECONDS=3600');

require_once __DIR__ . '/../scripts/simulation/SimulationSeason.php';
require_once __DIR__ . '/../scripts/simulation/SimulationRandom.php';
require_once __DIR__ . '/../scripts/simulation/Archetypes.php';
require_once __DIR__ . '/../scripts/simulation/PolicyBehavior.php';
require_once __DIR__ . '/../scripts/simulation/MetricsCollector.php';
require_once __DIR__ . '/../scripts/simulation/SimulationPlayer.php';
require_once __DIR__ . '/../scripts/simulation/SimulationPopulationSeason.php';
require_once __DIR__ . '/../scripts/simulation/SimulationPopulationLifetime.php';
require_once __DIR__ . '/../scripts/simulation/PolicyScenarioCatalog.php';
require_once __DIR__ . '/../scripts/simulation/PolicySweepRunner.php';
require_once __DIR__ . '/../scripts/simulation/ResultComparator.php';
require_once __DIR__ . '/../scripts/simulation/SweepComparatorProfileCatalog.php';
require_once __DIR__ . '/../scripts/simulation/SweepComparatorCampaignRunner.php';

class SweepComparatorCampaignRunnerTest extends TestCase
{
    public function testRunnerWritesReportForLightweightOverrideProfile(): void
    {
        $outputDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tmc_sweep_campaign_' . uniqid();
        $result = SweepComparatorCampaignRunner::run([
            'profile' => 'qualification',
            'seed' => 'campaign-runner-smoke',
            'output_dir' => $outputDir,
            'simulators' => ['B'],
            'scenario_names' => ['hoarder-pressure-v1'],
            'tuning_candidates_path' => null,
            'players_per_archetype' => 1,
            'season_count' => 4,
        ]);

        $this->assertFileExists((string)$result['report_json_path']);
        $this->assertFileExists((string)$result['report_md_path']);
        $this->assertSame('qualification', (string)$result['report']['profile']['id']);
        $this->assertSame('within-envelope', (string)$result['report']['timing_summary']['completion_status']);
        $this->assertGreaterThanOrEqual(1, (int)$result['report']['summary']['sweep_run_count']);
    }
}
