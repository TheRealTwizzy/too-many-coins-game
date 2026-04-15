<?php

use PHPUnit\Framework\TestCase;

// Keep sweep smoke tests fast.
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

class SimulationPolicySweepSmokeTest extends TestCase
{
    public function testBaselineSweepMatchesDirectSimulationB(): void
    {
        $seed = 'policy-sweep-baseline';
        $playersPerArchetype = 1;

        $direct = SimulationPopulationSeason::run($seed . '|scenario|baseline|sim|B', $playersPerArchetype, null);
        $result = PolicySweepRunner::run([
            'seed' => $seed,
            'players_per_archetype' => $playersPerArchetype,
            'season_count' => 4,
            'simulators' => ['B'],
            'scenarios' => [],
            'include_baseline' => true,
            'output_dir' => sys_get_temp_dir() . '/tmc_policy_sweep_smoke_baseline',
        ]);

        $this->assertNotEmpty($result['manifest']['runs']);
        $run = (array)$result['manifest']['runs'][0];
        $payload = json_decode((string)file_get_contents((string)$run['json']), true);

        $this->assertSame($direct['archetypes'], $payload['archetypes']);
        $this->assertSame($direct['diagnostics'], $payload['diagnostics']);
        $this->assertTrue((bool)$run['is_baseline']);
    }

    public function testScenarioOverrideIsSimulationOnlyAndMetadataTagged(): void
    {
        $before = SimulationSeason::build(1, 'policy-sweep-before');

        $result = PolicySweepRunner::run([
            'seed' => 'policy-sweep-override',
            'players_per_archetype' => 1,
            'season_count' => 4,
            'simulators' => ['C'],
            'scenarios' => ['mostly-idle-pressure-v1'],
            'include_baseline' => false,
            'output_dir' => sys_get_temp_dir() . '/tmc_policy_sweep_smoke_override',
        ]);

        $after = SimulationSeason::build(1, 'policy-sweep-after');
        $this->assertSame((int)$before['starprice_idle_weight_fp'], (int)$after['starprice_idle_weight_fp']);

        $this->assertCount(1, (array)$result['manifest']['runs']);
        $run = (array)$result['manifest']['runs'][0];
        $this->assertSame('mostly-idle-pressure-v1', (string)$run['scenario_name']);
        $this->assertSame('C', (string)$run['simulator_type']);
        $this->assertFalse((bool)$run['is_baseline']);
        $this->assertNotEmpty((array)$run['override_keys']);

        $payload = json_decode((string)file_get_contents((string)$run['json']), true);
        $this->assertSame('mostly-idle-pressure-v1', (string)$payload['sweep']['scenario_name']);
        $this->assertSame('C', (string)$payload['sweep']['simulator_type']);
        $this->assertSame(MetricsCollector::SCHEMA_VERSION, (string)$payload['schema_version']);
        $this->assertSame('tmc-sim-sweep.v1', (string)$payload['sweep']['schema_version']);
        $this->assertArrayHasKey('cohort', $payload['sweep']);
        $this->assertArrayHasKey('horizon', $payload['sweep']);
        $this->assertArrayHasKey('timing_summary', (array)$result['manifest']);
        $this->assertArrayHasKey('timings', $run);
        $this->assertGreaterThanOrEqual(0, (int)$result['manifest']['timing_summary']['total_duration_ms']);
        $this->assertGreaterThanOrEqual(0, (int)$run['timings']['total_duration_ms']);
    }
}
