<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/economy.php';
require_once __DIR__ . '/../includes/game_time.php';
require_once __DIR__ . '/../scripts/simulation/SimulationSeason.php';
require_once __DIR__ . '/../scripts/simulation/SimulationRandom.php';
require_once __DIR__ . '/../scripts/simulation/Archetypes.php';
require_once __DIR__ . '/../scripts/simulation/PolicyBehavior.php';
require_once __DIR__ . '/../scripts/simulation/ParityLedger.php';

/**
 * Milestone 5B — Parity contract tests for core economy/runtime behaviors.
 *
 * Validates:
 *   1. Star pricing progression contracts
 *   2. Lock-in semantics contracts
 *   3. Expiry/finalization behavior contracts
 *   4. Effective score ordering contracts
 *   5. presenceIsStale simulation-aware behavior
 *   6. Archetype-aware presence resolution contracts
 *   7. Idle → Offline transition semantics under simulation clock
 *
 * All tests use in-memory state (no DB required).
 */
class Milestone5BParityContractTest extends TestCase
{
    private ?int $savedSimTick = null;

    protected function setUp(): void
    {
        // Save simulation state for tests that need to manipulate it.
        $this->savedSimTick = null;
    }

    protected function tearDown(): void
    {
        GameTime::clearSimulationTick();
        // Restore the deterministic bootstrap epoch so subsequent tests never
        // fall through to Database::getInstance() via getServerEpoch().
        GameTime::setServerEpoch(TMC_TEST_EPOCH);
    }

    // -----------------------------------------------------------------------
    // 1. Star pricing progression contracts
    // -----------------------------------------------------------------------

    /**
     * publishedStarPrice must return the current_star_price during Active
     * and the blackout_star_price_snapshot during Blackout.
     */
    public function testPublishedStarPriceUsesSnapshotDuringBlackout(): void
    {
        $season = [
            'current_star_price' => 500,
            'blackout_star_price_snapshot' => 321,
        ];

        $this->assertSame(500, Economy::publishedStarPrice($season, 'Active'));
        $this->assertSame(321, Economy::publishedStarPrice($season, 'Blackout'));
    }

    /**
     * Star price must be non-negative and return the live price when no snapshot exists.
     */
    public function testPublishedStarPriceFallsBackToLiveWhenNoSnapshot(): void
    {
        $season = [
            'current_star_price' => 250,
            'blackout_star_price_snapshot' => 0,
        ];

        $this->assertSame(250, Economy::publishedStarPrice($season, 'Active'));
        // With snapshot=0 during Blackout, should fall back to current price.
        $this->assertSame(250, Economy::publishedStarPrice($season, 'Blackout'));
    }

    /**
     * Star price table from SimulationSeason must produce monotonically
     * increasing prices across the configured supply milestones.
     */
    public function testSimulationSeasonStarPriceTableIsMonotonic(): void
    {
        $season = SimulationSeason::build(1, 'parity-test');
        $table = json_decode($season['starprice_table'], true);

        $this->assertNotEmpty($table, 'Star price table must not be empty');

        $prevMilestone = -1;
        $prevPrice = -1;
        foreach ($table as $entry) {
            $this->assertGreaterThan($prevMilestone, $entry['m'],
                'Milestones must be strictly increasing');
            $this->assertGreaterThanOrEqual($prevPrice, $entry['price'],
                'Prices must be non-decreasing across milestones');
            $prevMilestone = $entry['m'];
            $prevPrice = $entry['price'];
        }
    }

    // -----------------------------------------------------------------------
    // 2. Lock-in semantics contracts
    // -----------------------------------------------------------------------

    /**
     * Lock-in payout must freeze seasonal stars at the snapshot value and
     * add sigil refund.
     */
    public function testLockInPayoutFreezesStarsAndAddsSigilRefund(): void
    {
        $lockIn = Economy::computeEarlyLockInPayout(
            100,
            [1, 2, 0, 1, 0],
            [50, 250, 1000, 3000, 9000]
        );

        // Sigil refund: 1×50 + 2×250 + 0×1000 + 1×3000 + 0×9000 = 3550
        $this->assertSame(3550, (int)$lockIn['sigil_refund_stars']);
        // Total: 100 + 3550 = 3650
        $this->assertSame(3650, (int)$lockIn['total_seasonal_stars']);
    }

    /**
     * Lock-in with zero sigils must return just the base seasonal stars.
     */
    public function testLockInPayoutWithZeroSigils(): void
    {
        $lockIn = Economy::computeEarlyLockInPayout(
            500,
            [0, 0, 0, 0, 0],
            [50, 250, 1000, 3000, 9000]
        );

        $this->assertSame(0, (int)$lockIn['sigil_refund_stars']);
        $this->assertSame(500, (int)$lockIn['total_seasonal_stars']);
    }

    /**
     * effectiveSeasonalStars must use lock_in_snapshot when lock-in is active.
     */
    public function testEffectiveScorePrefersLockInSnapshotOverCurrent(): void
    {
        $participation = [
            'lock_in_effect_tick' => 100,
            'lock_in_snapshot_seasonal_stars' => 555,
            'seasonal_stars' => 999,
        ];

        $this->assertSame(555, Economy::effectiveSeasonalStars($participation));
    }

    /**
     * effectiveSeasonalStars must use final_seasonal_stars for expired membership.
     */
    public function testEffectiveScoreUsesFinalStarsForExpiredMembership(): void
    {
        $participation = [
            'end_membership' => 1,
            'final_seasonal_stars' => 444,
            'seasonal_stars' => 888,
        ];

        $this->assertSame(444, Economy::effectiveSeasonalStars($participation));
    }

    /**
     * effectiveSeasonalStars must use current seasonal_stars when neither
     * locked in nor expired.
     */
    public function testEffectiveScoreUsesCurrentWhenNoLockInOrExpiry(): void
    {
        $participation = [
            'seasonal_stars' => 777,
        ];

        $this->assertSame(777, Economy::effectiveSeasonalStars($participation));
    }

    // -----------------------------------------------------------------------
    // 3. Expiry/finalization behavior contracts
    // -----------------------------------------------------------------------

    /**
     * Natural expiry payout must include participation bonus and placement bonus.
     */
    public function testNaturalExpiryPayoutIncludesBonuses(): void
    {
        $payout = Economy::computeNaturalExpiryPayout(200, 7200, 2, 0, true);

        $this->assertGreaterThan(0, $payout['participation_bonus'],
            'Participation bonus must be positive for 7200 active ticks');
        $this->assertSame(
            (int)(PLACEMENT_BONUS[2] ?? 0),
            $payout['placement_bonus'],
            'Placement bonus must match PLACEMENT_BONUS table for rank 2'
        );
        $this->assertGreaterThan($payout['payout_seasonal_stars'], $payout['total_source_stars'],
            'Total source must exceed base payout when bonuses are earned');
    }

    /**
     * Natural expiry with awardBadgesAndPlacement=false must not grant placement bonus.
     */
    public function testNaturalExpiryWithoutPlacementBonus(): void
    {
        $payout = Economy::computeNaturalExpiryPayout(200, 7200, 1, 0, false);

        $this->assertSame(0, $payout['placement_bonus']);
    }

    /**
     * Blackout phase must produce zero UBI accrual.
     */
    public function testBlackoutZeroAccrual(): void
    {
        $season = SimulationSeason::build(1, 'blackout-test');
        $season['status'] = 'Blackout';
        $season['computed_status'] = 'Blackout';
        $season['blackout_star_price_snapshot'] = 300;

        $player = [
            'player_id' => 1,
            'participation_enabled' => 1,
            'activity_state' => 'Active',
            'economic_presence_state' => 'Active',
            'current_game_time' => (int)$season['blackout_time'],
            'online_current' => 1,
        ];
        $participation = [
            'coins' => 10000,
            'coins_fractional_fp' => 0,
            'seasonal_stars' => 50,
            'active_ticks_total' => 3600,
        ];

        $rate = Economy::calculateRateBreakdown($season, $player, $participation, 0, false);
        $this->assertSame(0, (int)$rate['gross_rate_fp'], 'Blackout must produce zero gross UBI');
        $this->assertSame(0, (int)$rate['net_rate_fp'], 'Blackout must produce zero net UBI');
    }

    // -----------------------------------------------------------------------
    // 4. Effective score ordering contracts
    // -----------------------------------------------------------------------

    /**
     * Lock-in score must be frozen and not affected by subsequent star changes.
     */
    public function testLockedInScoreIsStable(): void
    {
        $base = [
            'lock_in_effect_tick' => 100,
            'lock_in_snapshot_seasonal_stars' => 500,
            'seasonal_stars' => 300, // Would be lower after lock-in spend
        ];

        $this->assertSame(500, Economy::effectiveSeasonalStars($base));

        // Even if seasonal_stars increases somehow, lock-in snapshot wins.
        $base['seasonal_stars'] = 9999;
        $this->assertSame(500, Economy::effectiveSeasonalStars($base));
    }

    /**
     * Lock-in score must take precedence over end_membership finalisation.
     */
    public function testLockInTakesPrecedenceOverEndMembership(): void
    {
        $participation = [
            'lock_in_effect_tick' => 100,
            'lock_in_snapshot_seasonal_stars' => 600,
            'end_membership' => 1,
            'final_seasonal_stars' => 400,
            'seasonal_stars' => 300,
        ];

        // Lock-in check comes first in effectiveSeasonalStars
        $this->assertSame(600, Economy::effectiveSeasonalStars($participation));
    }

    // -----------------------------------------------------------------------
    // 5. presenceIsStale simulation-aware behavior (TMC-5A-003 fix validation)
    // -----------------------------------------------------------------------

    /**
     * presenceIsStale must use wall-clock in non-simulation mode (production behavior).
     */
    public function testPresenceIsStaleUsesWallClockOutsideSimulation(): void
    {
        $this->assertFalse(GameTime::isSimulationClockActive());

        $freshPlayer = [
            'online_current' => 1,
            'last_seen_at' => date('Y-m-d H:i:s', time()),
        ];
        $this->assertFalse(Economy::presenceIsStale($freshPlayer),
            'Fresh last_seen_at must not be stale in production mode');

        $stalePlayer = [
            'online_current' => 1,
            'last_seen_at' => date('Y-m-d H:i:s', time() - TMC_PRESENCE_STALE_OFFLINE_SECONDS - 10),
        ];
        $this->assertTrue(Economy::presenceIsStale($stalePlayer),
            'Old last_seen_at must be stale in production mode');
    }

    /**
     * presenceIsStale must use simulated wall-clock when simulation mode is active.
     *
     * This validates the TMC-5A-003 fix: in simulation, fast-forwarded game time
     * means wall-clock time() barely advances, but the simulated timestamp derived
     * from the simulation tick does advance correctly.
     */
    public function testPresenceIsStaleUsesSimulatedClockInSimulation(): void
    {
        // Requires TMC_SIMULATION_MODE=fresh-run for setSimulationTick.
        $origMode = getenv('TMC_SIMULATION_MODE') ?: '';
        putenv('TMC_SIMULATION_MODE=fresh-run');

        try {
            // Set simulation tick to a point well past a player's last_seen_at.
            // The server epoch is 2026-01-01 00:00:00 Z = 1767225600.
            // Tick 200 @ 60s/tick = 12000 seconds after epoch.
            // Tick 2000 @ 60s/tick = 120000 seconds after epoch.
            $epochTs = strtotime('2026-01-01 00:00:00 UTC');
            GameTime::setServerEpoch($epochTs);
            $earlyTick = 200;
            $lateTick = 2000;

            // Player was "last seen" at tick 200's wall-clock equivalent.
            $lastSeenTs = $epochTs + intdiv($earlyTick * TICK_REAL_SECONDS, TIME_SCALE);
            $player = [
                'online_current' => 1,
                'last_seen_at' => gmdate('Y-m-d H:i:s', $lastSeenTs),
            ];

            // At tick 200, player is fresh (just seen).
            GameTime::setSimulationTick($earlyTick);
            $this->assertFalse(Economy::presenceIsStale($player),
                'Player seen at tick 200 must not be stale at tick 200');

            // At tick 2000 (108000 seconds later), player is stale.
            GameTime::setSimulationTick($lateTick);
            $elapsed = GameTime::tickStartRealUnix($lateTick) - $lastSeenTs;
            $this->assertGreaterThanOrEqual(TMC_PRESENCE_STALE_OFFLINE_SECONDS, $elapsed,
                'Elapsed simulated time must exceed stale threshold');
            $this->assertTrue(Economy::presenceIsStale($player),
                'Player seen at tick 200 must be stale at tick 2000 under simulation clock');
        } finally {
            GameTime::clearSimulationTick();
            GameTime::setServerEpoch(TMC_TEST_EPOCH);
            if ($origMode !== '') {
                putenv("TMC_SIMULATION_MODE=$origMode");
            } else {
                putenv('TMC_SIMULATION_MODE');
            }
        }
    }

    // -----------------------------------------------------------------------
    // 6. Archetype-aware presence resolution contracts
    // -----------------------------------------------------------------------

    /**
     * PolicyBehavior::resolvePresenceState must be deterministic for the same
     * seed/player/tick/phase combination.
     */
    public function testPresenceResolutionIsDeterministic(): void
    {
        $archetype = Archetypes::get('mostly_idle');

        $result1 = PolicyBehavior::resolvePresenceState($archetype, 'EARLY', 'det-seed', 42, 100);
        $result2 = PolicyBehavior::resolvePresenceState($archetype, 'EARLY', 'det-seed', 42, 100);

        $this->assertSame($result1, $result2);
    }

    /**
     * Mostly Idle archetype must produce a lower Active rate than Hardcore
     * across a statistically meaningful sample.
     */
    public function testMostlyIdleHasLowerActiveRateThanHardcore(): void
    {
        $idle = Archetypes::get('mostly_idle');
        $hardcore = Archetypes::get('hardcore');

        $idleActiveCount = 0;
        $hardcoreActiveCount = 0;
        $sampleSize = 500;

        for ($tick = 1; $tick <= $sampleSize; $tick++) {
            if (PolicyBehavior::resolvePresenceState($idle, 'MID', 'rate-test', 1, $tick) === 'Active') {
                $idleActiveCount++;
            }
            if (PolicyBehavior::resolvePresenceState($hardcore, 'MID', 'rate-test', 1, $tick) === 'Active') {
                $hardcoreActiveCount++;
            }
        }

        $this->assertLessThan($hardcoreActiveCount, $idleActiveCount,
            "Mostly Idle active rate ($idleActiveCount/$sampleSize) must be lower than Hardcore ($hardcoreActiveCount/$sampleSize)");
    }

    /**
     * All three presence states (Active/Idle/Offline) must be reachable for
     * archetypes with non-zero probabilities in each category.
     */
    public function testAllPresenceStatesAreReachable(): void
    {
        $archetype = Archetypes::get('regular');
        $states = ['Active' => false, 'Idle' => false, 'Offline' => false];

        for ($tick = 1; $tick <= 2000; $tick++) {
            $state = PolicyBehavior::resolvePresenceState($archetype, 'MID', 'reach-test', 1, $tick);
            $states[$state] = true;
            if ($states['Active'] && $states['Idle'] && $states['Offline']) {
                break;
            }
        }

        $this->assertTrue($states['Active'], 'Active state must be reachable for Regular archetype');
        $this->assertTrue($states['Idle'], 'Idle state must be reachable for Regular archetype');
        $this->assertTrue($states['Offline'], 'Offline state must be reachable for Regular archetype');
    }

    // -----------------------------------------------------------------------
    // 7. Idle → Offline transition under simulation clock (TMC-5A-002/003 combined)
    // -----------------------------------------------------------------------

    /**
     * With simulation clock active, a player with stale last_seen_at (in
     * simulated time) must transition through Idle → Offline via the
     * resolveEconomicPresenceState path.
     */
    public function testIdleToOfflineTransitionUnderSimulationClock(): void
    {
        $origMode = getenv('TMC_SIMULATION_MODE') ?: '';
        putenv('TMC_SIMULATION_MODE=fresh-run');

        try {
            $epochTs = strtotime('2026-01-01 00:00:00 UTC');
            GameTime::setServerEpoch($epochTs);

            // Player was last seen at tick 100.
            $lastSeenTick = 100;
            $lastSeenTs = $epochTs + intdiv($lastSeenTick * TICK_REAL_SECONDS, TIME_SCALE);

            $player = [
                'activity_state' => 'Idle',
                'idle_since_tick' => $lastSeenTick,
                'online_current' => 1,
                'last_seen_at' => gmdate('Y-m-d H:i:s', $lastSeenTs),
            ];
            $season = ['blackout_time' => 99999];

            // At tick enough for FORCED_OFFLINE_IDLE_HOLD_TICKS + stale threshold.
            $offlineTick = $lastSeenTick + FORCED_OFFLINE_IDLE_HOLD_TICKS + 100;
            GameTime::setSimulationTick($offlineTick);

            // Verify presenceIsStale fires under simulation clock.
            $elapsed = GameTime::tickStartRealUnix($offlineTick) - $lastSeenTs;
            $this->assertGreaterThanOrEqual(TMC_PRESENCE_STALE_OFFLINE_SECONDS, $elapsed,
                'Simulated elapsed time must exceed stale threshold');

            $state = Economy::resolveEconomicPresenceState($player, $season, $offlineTick);
            $this->assertSame('Offline', $state,
                'Player must transition to Offline when idle long enough AND presence is stale under simulation clock');
        } finally {
            GameTime::clearSimulationTick();
            GameTime::setServerEpoch(TMC_TEST_EPOCH);
            if ($origMode !== '') {
                putenv("TMC_SIMULATION_MODE=$origMode");
            } else {
                putenv('TMC_SIMULATION_MODE');
            }
        }
    }

    /**
     * With simulation clock active, a player with FRESH last_seen_at must
     * stay Idle even past the hold threshold.
     */
    public function testIdlePlayerStaysIdleWithFreshPresenceUnderSimulation(): void
    {
        $origMode = getenv('TMC_SIMULATION_MODE') ?: '';
        putenv('TMC_SIMULATION_MODE=fresh-run');

        try {
            $epochTs = strtotime('2026-01-01 00:00:00 UTC');
            GameTime::setServerEpoch($epochTs);
            $currentTick = 500;

            // Player is idle since tick 100 but was last seen very recently
            // (simulated timestamp near the current tick).
            $lastSeenTs = $epochTs + intdiv($currentTick * TICK_REAL_SECONDS, TIME_SCALE) - 5;

            $player = [
                'activity_state' => 'Idle',
                'idle_since_tick' => 100,
                'online_current' => 1,
                'last_seen_at' => gmdate('Y-m-d H:i:s', $lastSeenTs),
            ];
            $season = ['blackout_time' => 99999];

            GameTime::setSimulationTick($currentTick);
            $state = Economy::resolveEconomicPresenceState($player, $season, $currentTick);
            $this->assertSame('Idle', $state,
                'Player with fresh simulated presence must stay Idle even past hold threshold');
        } finally {
            GameTime::clearSimulationTick();
            GameTime::setServerEpoch(TMC_TEST_EPOCH);
            if ($origMode !== '') {
                putenv("TMC_SIMULATION_MODE=$origMode");
            } else {
                putenv('TMC_SIMULATION_MODE');
            }
        }
    }

    // -----------------------------------------------------------------------
    // 8. Season timing parity contracts
    // -----------------------------------------------------------------------

    /**
     * Season phases must cover the entire duration without gaps or overlaps.
     */
    public function testSeasonPhasesCoverFullDuration(): void
    {
        $season = SimulationSeason::build(1, 'phase-test');
        $start = (int)$season['start_time'];
        $end = (int)$season['end_time'];
        $blackout = (int)$season['blackout_time'];

        // Active phase: [start, blackout)
        $this->assertSame('Active', GameTime::getSeasonStatus($season, $start));
        $this->assertSame('Active', GameTime::getSeasonStatus($season, $blackout - 1));

        // Blackout phase: [blackout, end)
        $this->assertSame('Blackout', GameTime::getSeasonStatus($season, $blackout));
        $this->assertSame('Blackout', GameTime::getSeasonStatus($season, $end - 1));

        // Expired: [end, ∞)
        $this->assertSame('Expired', GameTime::getSeasonStatus($season, $end));
    }

    // -----------------------------------------------------------------------
    // 9. Simulation determinism (unit-level)
    // -----------------------------------------------------------------------

    /**
     * SimulationRandom must produce identical sequences for the same seed.
     */
    public function testSimulationRandomIsDeterministic(): void
    {
        $a1 = SimulationRandom::float01('det-seed', ['context', 'a']);
        $a2 = SimulationRandom::float01('det-seed', ['context', 'a']);
        $this->assertSame($a1, $a2);

        $b1 = SimulationRandom::intRange('det-seed', 0, 100, ['context', 'b']);
        $b2 = SimulationRandom::intRange('det-seed', 0, 100, ['context', 'b']);
        $this->assertSame($b1, $b2);

        $c1 = SimulationRandom::chance('det-seed', 0.5, ['context', 'c']);
        $c2 = SimulationRandom::chance('det-seed', 0.5, ['context', 'c']);
        $this->assertSame($c1, $c2);
    }

    /**
     * Different seeds must produce different sequences.
     */
    public function testSimulationRandomDiffersBySeed(): void
    {
        $a = SimulationRandom::float01('seed-A', ['ctx']);
        $b = SimulationRandom::float01('seed-B', ['ctx']);
        $this->assertNotSame($a, $b, 'Different seeds must produce different values');
    }
}
