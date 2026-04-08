<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/simulation/SimulationSeason.php';
require_once __DIR__ . '/../scripts/simulation/SimulationRandom.php';
require_once __DIR__ . '/../scripts/simulation/Archetypes.php';
require_once __DIR__ . '/../scripts/simulation/PolicyBehavior.php';
require_once __DIR__ . '/../scripts/simulation/MetricsCollector.php';
require_once __DIR__ . '/../scripts/simulation/SimulationPlayer.php';
require_once __DIR__ . '/../scripts/simulation/SimulationPopulationSeason.php';

class SimulationSeasonSmokeTest extends TestCase
{
    public function testPopulationSimulationProducesRequiredArchetypeMetrics(): void
    {
        $payload = SimulationPopulationSeason::run('season-smoke', 1, null);

        $this->assertSame('tmc-sim-phase1.v1', $payload['schema_version']);
        $this->assertSame('single-season-population', $payload['simulator']);
        $this->assertCount(10, $payload['archetypes']);

        $casual = $payload['archetypes']['casual'];
        $this->assertArrayHasKey('coins_earned_by_phase', $casual);
        $this->assertArrayHasKey('stars_purchased_by_phase', $casual);
        $this->assertArrayHasKey('sigils_acquired_by_tier', $casual);
        $this->assertArrayHasKey('sigils_spent_by_action', $casual);
        $this->assertArrayHasKey('final_rank_distribution', $casual);
        $this->assertArrayHasKey('global_stars_gained', $casual);
        $this->assertArrayHasKey('diagnostics', $payload);
        $this->assertArrayHasKey('lock_in_timing', $payload['diagnostics']);
        $this->assertArrayHasKey('late_active_engaged_rate', $payload['diagnostics']);
        $this->assertArrayHasKey('action_volume_by_phase', $payload['diagnostics']);
    }

    public function testPopulationSimulationIsDeterministicForSameSeed(): void
    {
        $first = SimulationPopulationSeason::run('stable-seed', 1, null);
        $second = SimulationPopulationSeason::run('stable-seed', 1, null);

        $this->assertSame($first['archetypes'], $second['archetypes']);
        $this->assertSame($first['diagnostics'], $second['diagnostics']);
    }

    public function testPopulationSimulationProducesMixedExitOutcomes(): void
    {
        $payload = SimulationPopulationSeason::run('behavior-mix', 3, null);

        $this->assertGreaterThan(0, (int)$payload['diagnostics']['natural_expiry_count']);
        $this->assertGreaterThan(0, (int)$payload['diagnostics']['late_active_active_players']);
        $this->assertGreaterThan(0, (int)$payload['diagnostics']['late_active_engaged_players']);

        $lockedIn = (int)$payload['diagnostics']['lock_in_timing']['MID']
            + (int)$payload['diagnostics']['lock_in_timing']['LATE_ACTIVE']
            + (int)$payload['diagnostics']['lock_in_timing']['BLACKOUT'];

        $this->assertGreaterThan(0, $lockedIn);
        $this->assertLessThan((int)$payload['config']['total_players'], $lockedIn);

        $lateActiveActions = array_sum((array)$payload['diagnostics']['action_volume_by_phase']['LATE_ACTIVE']);
        $this->assertGreaterThan(0, $lateActiveActions);
    }
}
