<?php

use PHPUnit\Framework\TestCase;

// Keep smoke tests fast: 1 tick = 1 hour in test runtime.
putenv('TMC_TICK_REAL_SECONDS=3600');

require_once __DIR__ . '/../scripts/simulation/SimulationSeason.php';
require_once __DIR__ . '/../scripts/simulation/SimulationRandom.php';
require_once __DIR__ . '/../scripts/simulation/Archetypes.php';
require_once __DIR__ . '/../scripts/simulation/PolicyBehavior.php';
require_once __DIR__ . '/../scripts/simulation/MetricsCollector.php';
require_once __DIR__ . '/../scripts/simulation/SimulationPlayer.php';
require_once __DIR__ . '/../scripts/simulation/SimulationPopulationSeason.php';
require_once __DIR__ . '/../scripts/simulation/SimulationPopulationLifetime.php';

class SimulationLifetimeSmokeTest extends TestCase
{
    public function testLifetimeSimulationIsDeterministicForSameSeed(): void
    {
        $first = SimulationPopulationLifetime::run('lifetime-stable', 2, 8, null);
        $second = SimulationPopulationLifetime::run('lifetime-stable', 2, 8, null);

        $this->assertSame($first['season_timeline'], $second['season_timeline']);
        $this->assertSame($first['players'], $second['players']);
        $this->assertSame($first['population_diagnostics'], $second['population_diagnostics']);
    }

    public function testOverlapSchedulingAndAccountingSanity(): void
    {
        $payload = SimulationPopulationLifetime::run('lifetime-sanity', 2, 10, null);

        $this->assertSame(SEASON_DURATION, (int)$payload['config']['season_duration_ticks']);
        $this->assertSame(SEASON_CADENCE, (int)$payload['config']['season_cadence_ticks']);
        $this->assertCount(10, $payload['season_timeline']);

        $maxOverlap = max(array_map(static fn($row) => (int)$row['active_seasons_at_start'], $payload['season_timeline']));
        $this->assertGreaterThanOrEqual(2, $maxOverlap);

        $sumPlayers = array_sum(array_map(static fn($row) => (int)$row['cumulative_global_stars'], $payload['players']));
        $this->assertSame((int)$payload['population_diagnostics']['total_cumulative_global_stars'], $sumPlayers);
    }

    public function testRejoinAndSkipBehaviorSanity(): void
    {
        $payload = SimulationPopulationLifetime::run('lifetime-rejoin', 3, 10, null);

        $skippers = array_values(array_filter($payload['players'], static fn($row) => (int)$row['seasons_skipped'] > 0));
        $rejoiners = array_values(array_filter($payload['players'], static fn($row) => !empty($row['rejoin_delay_samples'])));

        $this->assertNotEmpty($skippers);
        $this->assertNotEmpty($rejoiners);

        foreach ($payload['players'] as $row) {
            $this->assertGreaterThanOrEqual(0, (int)$row['seasons_entered']);
            $this->assertGreaterThanOrEqual(0, (int)$row['seasons_skipped']);
            $this->assertGreaterThanOrEqual(0.0, (float)$row['rejoin_delay_average']);
            $this->assertGreaterThanOrEqual(0, (int)$row['cumulative_global_stars']);
        }
    }
}
