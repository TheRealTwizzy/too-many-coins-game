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
    }

    public function testPopulationSimulationIsDeterministicForSameSeed(): void
    {
        $first = SimulationPopulationSeason::run('stable-seed', 1, null);
        $second = SimulationPopulationSeason::run('stable-seed', 1, null);

        $this->assertSame($first['archetypes'], $second['archetypes']);
    }
}
