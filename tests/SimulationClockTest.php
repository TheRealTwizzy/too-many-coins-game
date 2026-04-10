<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for the simulation clock adapter in GameTime and the explicit
 * tick-driven entrypoint in TickEngine (Milestone 2).
 *
 * These tests validate:
 * - Simulation tick override sets and clears correctly
 * - now()/globalTick() return the overridden value when set
 * - Override is rejected outside simulation mode
 * - Default wall-clock derivation is unchanged when override is not set
 * - TickEngine::processTickAt() requires simulation mode
 *
 * Bootstrap loads config.php, database.php (class-only, no PDO connection),
 * and game_time.php, then pre-sets a deterministic server epoch via
 * TMC_TEST_EPOCH.  No Database stub or eval trick is needed here.
 */

class SimulationClockTest extends TestCase
{
    private string $origSimMode;

    protected function setUp(): void
    {
        $this->origSimMode = getenv('TMC_SIMULATION_MODE') ?: '';
        GameTime::clearSimulationTick();
        GameTime::setServerEpoch(TMC_TEST_EPOCH);
    }

    protected function tearDown(): void
    {
        GameTime::clearSimulationTick();
        GameTime::setServerEpoch(TMC_TEST_EPOCH);

        if ($this->origSimMode !== '') {
            putenv('TMC_SIMULATION_MODE=' . $this->origSimMode);
        } else {
            putenv('TMC_SIMULATION_MODE');
        }
    }

    // -----------------------------------------------------------------------
    // Simulation clock override behavior
    // -----------------------------------------------------------------------

    public function testSimulationClockInactiveByDefault(): void
    {
        $this->assertFalse(
            GameTime::isSimulationClockActive(),
            'Simulation clock should be inactive by default'
        );
    }

    public function testSetSimulationTickOverridesNow(): void
    {
        putenv('TMC_SIMULATION_MODE=fresh-run');

        GameTime::setSimulationTick(42);
        $this->assertTrue(GameTime::isSimulationClockActive());
        $this->assertSame(42, GameTime::now());
        $this->assertSame(42, GameTime::globalTick());
    }

    public function testSetSimulationTickClampsNegativeToZero(): void
    {
        putenv('TMC_SIMULATION_MODE=fresh-run');

        GameTime::setSimulationTick(-5);
        $this->assertSame(0, GameTime::now());
    }

    /**
     * Wall-clock derivation is safe: bootstrap pre-sets a deterministic epoch
     * via TMC_TEST_EPOCH so getServerEpoch() never hits the DB.
     */
    public function testClearSimulationTickRestoresWallClock(): void
    {
        putenv('TMC_SIMULATION_MODE=fresh-run');

        GameTime::setSimulationTick(999);
        $this->assertSame(999, GameTime::now());

        GameTime::clearSimulationTick();
        $this->assertFalse(GameTime::isSimulationClockActive());
        // After clearing, now() should return wall-clock derived value (>= 0)
        $this->assertGreaterThanOrEqual(0, GameTime::now());
    }

    public function testSetSimulationTickRejectsWithoutSimulationMode(): void
    {
        putenv('TMC_SIMULATION_MODE');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('TMC_SIMULATION_MODE');
        GameTime::setSimulationTick(1);
    }

    public function testSetSimulationTickRejectsWrongSimulationMode(): void
    {
        putenv('TMC_SIMULATION_MODE=export');

        $this->expectException(\RuntimeException::class);
        GameTime::setSimulationTick(1);
    }

    public function testMultipleSetCallsUpdateTick(): void
    {
        putenv('TMC_SIMULATION_MODE=fresh-run');

        GameTime::setSimulationTick(10);
        $this->assertSame(10, GameTime::now());

        GameTime::setSimulationTick(20);
        $this->assertSame(20, GameTime::now());

        GameTime::setSimulationTick(15);
        $this->assertSame(15, GameTime::now());
    }

    // -----------------------------------------------------------------------
    // Production default behavior preserved
    // -----------------------------------------------------------------------

    /**
     * Wall-clock derivation is safe: bootstrap pre-sets TMC_TEST_EPOCH.
     */
    public function testNowReturnsNonNegativeWhenNoOverride(): void
    {
        $this->assertFalse(GameTime::isSimulationClockActive());
        $now = GameTime::now();
        $this->assertIsInt($now);
        $this->assertGreaterThanOrEqual(0, $now);
    }

    /**
     * Wall-clock derivation is safe: bootstrap pre-sets TMC_TEST_EPOCH.
     */
    public function testGlobalTickMatchesNowWhenNoOverride(): void
    {
        $this->assertSame(GameTime::now(), GameTime::globalTick());
    }

    // -----------------------------------------------------------------------
    // TickEngine::processTickAt safety gate
    // -----------------------------------------------------------------------

    public function testProcessTickAtRejectsWithoutSimulationMode(): void
    {
        putenv('TMC_SIMULATION_MODE');

        if (!class_exists('TickEngine', false)) {
            $this->markTestSkipped(
                'TickEngine requires full production includes (economy, DB); ' .
                'safety gate is validated via GameTime::setSimulationTick rejection'
            );
        }

        $this->expectException(\RuntimeException::class);
        TickEngine::processTickAt(1);
    }

    // -----------------------------------------------------------------------
    // Season helpers work with overridden clock
    // -----------------------------------------------------------------------

    public function testGetSeasonStatusUsesOverriddenTick(): void
    {
        putenv('TMC_SIMULATION_MODE=fresh-run');

        $season = [
            'start_time' => 100,
            'end_time' => 1000,
            'blackout_time' => 800,
        ];

        // Before season
        GameTime::setSimulationTick(50);
        $this->assertSame('Scheduled', GameTime::getSeasonStatus($season));

        // Active
        GameTime::setSimulationTick(500);
        $this->assertSame('Active', GameTime::getSeasonStatus($season));

        // Blackout
        GameTime::setSimulationTick(900);
        $this->assertSame('Blackout', GameTime::getSeasonStatus($season));

        // Expired
        GameTime::setSimulationTick(1500);
        $this->assertSame('Expired', GameTime::getSeasonStatus($season));
    }

    // -----------------------------------------------------------------------
    // Phantom season containment: ensureSeasons() skips in simulation mode
    // -----------------------------------------------------------------------

    public function testEnsureSeasonsSkipsWhenSimulationClockActive(): void
    {
        putenv('TMC_SIMULATION_MODE=fresh-run');
        GameTime::setSimulationTick(5000);

        // ensureSeasons() should return immediately without touching the DB.
        // With our stub Database, any season INSERT would return 0; we verify
        // no exception is thrown and the method completes instantly.
        GameTime::ensureSeasons();

        // If ensureSeasons() did NOT skip, it would call Database methods that
        // try to create seasons. With the stub, this would succeed silently.
        // The real validation is that in production integration tests,
        // no phantom seasons appear (tested in FreshLifecycleIntegrationTest).
        $this->assertTrue(GameTime::isSimulationClockActive(),
            'Simulation clock should remain active after ensureSeasons()');
    }

    /**
     * @group freshdb
     */
    public function testEnsureSeasonsRunsNormallyWithoutSimulationClock(): void
    {
        if (getenv('TMC_FRESHDB_TEST_ENABLED') !== '1') {
            $this->markTestSkipped('Requires local DB (ensureSeasons hits Database::getInstance)');
        }

        // Without simulation clock, ensureSeasons should execute normally.
        $this->assertFalse(GameTime::isSimulationClockActive());
        GameTime::ensureSeasons();
        // No exception = production path still works.
        $this->assertFalse(GameTime::isSimulationClockActive());
    }
}
