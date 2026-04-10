<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/economy.php';
require_once __DIR__ . '/../includes/game_time.php';
require_once __DIR__ . '/../scripts/simulation/ParityLedger.php';

/**
 * Milestone 5A — Targeted parity bug-hunt validation.
 *
 * These tests validate the specific bug classes surfaced during Milestones 3 and 4:
 *
 *   1. Presence / idle / action eligibility interactions
 *   2. Idle timeout and offline transition semantics
 *   3. Season timing / join window boundary conditions
 *   4. Boost expiry boundary consistency
 *   5. Presence state resolution parity between drop and UBI paths
 *   6. ParityLedger extended classification support
 *
 * No DB-dependent tests are included here; all tests use in-memory state.
 */
class Milestone5AParityBugHuntTest extends TestCase
{
    // -----------------------------------------------------------------------
    // 1. Presence / idle / action eligibility
    // -----------------------------------------------------------------------

    /**
     * After a seasonJoin, resolveEconomicPresenceState must return Active
     * regardless of any prior stale idle_since_tick on the player record.
     *
     * Bug class: seasonJoin sets activity_state='Active' but previously left
     * idle_since_tick stale. resolveEconomicPresenceState short-circuits on
     * activity_state='Active' so the stale value was harmless, but the fix
     * (clearing idle_since_tick) ensures cleaner state.
     */
    public function testPresenceActiveAfterSeasonJoinWithStaleIdleSinceTick(): void
    {
        // Simulate a player who was idle at tick 100, then rejoined.
        // After rejoin, activity_state='Active', idle_since_tick should be NULL
        // but even if stale, presence must be Active.
        $player = [
            'activity_state'          => 'Active',
            'idle_since_tick'         => 100,   // stale from prior idle
            'online_current'          => 1,
            'last_seen_at'            => date('Y-m-d H:i:s', time()),
        ];
        $season = ['blackout_time' => 99999];

        $state = Economy::resolveEconomicPresenceState($player, $season, 500);
        $this->assertSame('Active', $state, 'Active activity_state must short-circuit before idle_since_tick check');
    }

    /**
     * A player with activity_state='Active' and idle_since_tick=NULL (clean
     * state after the 5A fix) must resolve as Active.
     */
    public function testPresenceActiveWithCleanIdleSinceTick(): void
    {
        $player = [
            'activity_state'          => 'Active',
            'idle_since_tick'         => null,
            'online_current'          => 1,
            'last_seen_at'            => date('Y-m-d H:i:s', time()),
        ];
        $season = ['blackout_time' => 99999];

        $state = Economy::resolveEconomicPresenceState($player, $season, 200);
        $this->assertSame('Active', $state);
    }

    /**
     * idle_modal_active is the UI-level action gate; economic_presence_state
     * is the tick-level economic gate. These are intentionally separate layers.
     *
     * A player with idle_modal_active=0 but activity_state='Idle' can still
     * perform actions (UI says "go ahead") while receiving Idle-rate UBI
     * (economy says "you're idle"). This is by design.
     */
    public function testIdleModalAndEconomicPresenceAreDistinctLayers(): void
    {
        // Player dismissed idle modal but hasn't taken an action yet: stays Idle economically.
        $player = [
            'activity_state'          => 'Idle',
            'idle_modal_active'       => 0,     // dismissed
            'idle_since_tick'         => 100,
            'online_current'          => 1,
            'last_seen_at'            => date('Y-m-d H:i:s', time()),
        ];
        $season = ['blackout_time' => 99999];

        $state = Economy::resolveEconomicPresenceState($player, $season, 200);
        // Should be Idle (not Active) because activity_state is Idle,
        // even though idle_modal_active is 0.
        $this->assertSame('Idle', $state);
    }

    // -----------------------------------------------------------------------
    // 2. Idle timeout and offline transition semantics
    // -----------------------------------------------------------------------

    /**
     * Offline transition requires BOTH idle_held_ticks >= threshold AND
     * presenceIsStale(). If presence is fresh, player stays Idle even
     * after exceeding the hold threshold.
     */
    public function testIdlePlayerStaysIdleWhenPresenceIsFresh(): void
    {
        $player = [
            'activity_state'  => 'Idle',
            'idle_since_tick' => 100,
            'online_current'  => 1,
            // last_seen_at is fresh (now) → presenceIsStale returns false
            'last_seen_at'    => date('Y-m-d H:i:s', time()),
        ];
        $season = ['blackout_time' => 99999];
        $tick = 100 + FORCED_OFFLINE_IDLE_HOLD_TICKS + 100;

        $state = Economy::resolveEconomicPresenceState($player, $season, $tick);
        $this->assertSame('Idle', $state, 'Fresh presence prevents Offline transition even past hold threshold');
    }

    /**
     * Offline transition fires when idle_held_ticks >= threshold AND
     * presenceIsStale() is true.
     */
    public function testIdlePlayerGoesOfflineWhenPresenceIsStale(): void
    {
        $player = [
            'activity_state'  => 'Idle',
            'idle_since_tick' => 100,
            'online_current'  => 1,
            'last_seen_at'    => date('Y-m-d H:i:s', time() - TMC_PRESENCE_STALE_OFFLINE_SECONDS - 10),
        ];
        $season = ['blackout_time' => 99999];
        $tick = 100 + FORCED_OFFLINE_IDLE_HOLD_TICKS;

        $state = Economy::resolveEconomicPresenceState($player, $season, $tick);
        $this->assertSame('Offline', $state);
    }

    /**
     * presenceIsStale uses wall-clock time. In fast simulation contexts,
     * wall-clock barely advances so presenceIsStale returns false even for
     * long game-tick intervals. This is a known simulator parity limitation.
     */
    public function testPresenceIsStaleUsesWallClockNotGameTick(): void
    {
        // Player with recent last_seen_at but game tick far in the future.
        $player = [
            'online_current' => 1,
            'last_seen_at'   => date('Y-m-d H:i:s', time()),
        ];
        $this->assertFalse(
            Economy::presenceIsStale($player),
            'Fresh last_seen_at means not stale, regardless of how many game ticks have passed'
        );

        // Player with old last_seen_at.
        $stalePlayer = [
            'online_current' => 1,
            'last_seen_at'   => date('Y-m-d H:i:s', time() - TMC_PRESENCE_STALE_OFFLINE_SECONDS - 1),
        ];
        $this->assertTrue(
            Economy::presenceIsStale($stalePlayer),
            'Old last_seen_at means stale'
        );
    }

    // -----------------------------------------------------------------------
    // 3. Season timing / join window boundary conditions
    // -----------------------------------------------------------------------

    /**
     * getSeasonStatus returns correct status at exact boundary ticks.
     */
    public function testSeasonStatusAtExactBoundaries(): void
    {
        $season = [
            'start_time'   => 1000,
            'end_time'     => 2000,
            'blackout_time' => 1800,
        ];

        // One tick before start → Scheduled
        $this->assertSame('Scheduled', GameTime::getSeasonStatus($season, 999));
        // Exact start → Active
        $this->assertSame('Active', GameTime::getSeasonStatus($season, 1000));
        // One tick before blackout → Active
        $this->assertSame('Active', GameTime::getSeasonStatus($season, 1799));
        // Exact blackout → Blackout
        $this->assertSame('Blackout', GameTime::getSeasonStatus($season, 1800));
        // One tick before end → Blackout
        $this->assertSame('Blackout', GameTime::getSeasonStatus($season, 1999));
        // Exact end → Expired
        $this->assertSame('Expired', GameTime::getSeasonStatus($season, 2000));
    }

    /**
     * seasonStartTime is 1-indexed: Season 1 starts at tick 1.
     */
    public function testSeasonStartTimeIsOneIndexed(): void
    {
        $this->assertSame(1, GameTime::seasonStartTime(1));
        $this->assertSame(1 + SEASON_CADENCE, GameTime::seasonStartTime(2));
        $this->assertSame(1 + 2 * SEASON_CADENCE, GameTime::seasonStartTime(3));
    }

    /**
     * blackoutStartTime must always be >= start_time for valid configurations.
     */
    public function testBlackoutStartTimeIsAfterSeasonStart(): void
    {
        $startTime = GameTime::seasonStartTime(1);
        $endTime = GameTime::seasonEndTime($startTime);
        $blackout = GameTime::blackoutStartTime($endTime);

        $this->assertGreaterThanOrEqual(
            $startTime,
            $blackout,
            'Blackout must start at or after season start for valid BLACKOUT_DURATION'
        );
    }

    // -----------------------------------------------------------------------
    // 4. Presence parity: drop activity state vs UBI economic presence
    // -----------------------------------------------------------------------

    /**
     * When economic_presence_state is explicitly set (e.g., by TickEngine),
     * both resolveSigilDropActivityState and resolveEconomicPresenceState
     * must honor it consistently.
     */
    public function testDropAndUBIPresenceHonorExplicitOverride(): void
    {
        $player = [
            'online_current'           => 1,
            'activity_state'           => 'Active',
            'economic_presence_state'  => 'Offline',
        ];

        $dropState = Economy::resolveSigilDropActivityState($player);
        $season = ['blackout_time' => 99999];
        $econState = Economy::resolveEconomicPresenceState($player, $season, 100);

        $this->assertSame('Offline', $dropState, 'Drop state must honor explicit override');
        $this->assertSame('Offline', $econState, 'Econ state must honor explicit override');
    }

    /**
     * Without explicit override, drop and UBI presence should resolve
     * consistently for Active and Idle players.
     */
    public function testDropAndUBIPresenceConsistentWithoutOverride(): void
    {
        // Active player
        $active = [
            'online_current'  => 1,
            'activity_state'  => 'Active',
        ];
        $this->assertSame('Active', Economy::resolveSigilDropActivityState($active));
        $this->assertSame('Active', Economy::resolveEconomicPresenceState($active, ['blackout_time' => 99999], 100));

        // Idle player (online)
        $idle = [
            'online_current'  => 1,
            'activity_state'  => 'Idle',
            'idle_since_tick' => 50,
            'last_seen_at'    => date('Y-m-d H:i:s', time()),
        ];
        $this->assertSame('Idle', Economy::resolveSigilDropActivityState($idle));
        $this->assertSame('Idle', Economy::resolveEconomicPresenceState($idle, ['blackout_time' => 99999], 100));
    }

    /**
     * Offline player (online_current=0): drop resolution and UBI resolution
     * should both treat as Offline.
     */
    public function testOfflinePlayerConsistentAcrossPresenceResolvers(): void
    {
        $offline = [
            'online_current'  => 0,
            'activity_state'  => 'Active',    // stale from before disconnect
            'idle_since_tick' => null,
            'last_seen_at'    => date('Y-m-d H:i:s', time() - 86400),
        ];

        $dropState = Economy::resolveSigilDropActivityState($offline);
        $this->assertSame('Offline', $dropState, 'Drop state: offline_current=0 → Offline');

        // UBI path: activity_state='Active' short-circuits to Active.
        // This is an intentional divergence: the UBI path trusts activity_state,
        // while the drop path trusts online_current first.
        $econState = Economy::resolveEconomicPresenceState($offline, ['blackout_time' => 99999], 100);
        $this->assertSame('Active', $econState,
            'Known divergence: UBI path trusts activity_state, drop path trusts online_current');
    }

    // -----------------------------------------------------------------------
    // 5. ParityLedger extended classifications from Milestone 5A
    // -----------------------------------------------------------------------

    public function testParityLedgerAcceptsExtended5AClassifications(): void
    {
        $ledger = new ParityLedger();

        $classifications = [
            ParityLedger::CLASS_SHARED_RUNTIME_BUG,
            ParityLedger::CLASS_SIMULATOR_ONLY_BUG,
            ParityLedger::CLASS_ARTIFACT_ONLY_BUG,
            ParityLedger::CLASS_PARITY_RISK_NO_FIX,
        ];

        foreach ($classifications as $cls) {
            $ledger->add([
                'parity_issue_id'   => 'TEST-' . strtoupper(str_replace('_', '-', $cls)),
                'severity'          => ParityLedger::SEVERITY_MINOR,
                'classification'    => $cls,
                'expected_behavior' => 'test',
                'observed_behavior' => 'test',
                'status'            => ParityLedger::STATUS_OPEN,
            ]);
        }

        $artifact = $ledger->buildArtifact();

        $this->assertSame(4, $artifact['total_issues']);
        foreach ($classifications as $cls) {
            $this->assertSame(1, $artifact['by_classification'][$cls],
                "Classification {$cls} should be counted");
        }
    }

    public function testParityLedgerValidationAcceptsExtendedClassifications(): void
    {
        foreach ([
            ParityLedger::CLASS_SHARED_RUNTIME_BUG,
            ParityLedger::CLASS_SIMULATOR_ONLY_BUG,
            ParityLedger::CLASS_ARTIFACT_ONLY_BUG,
            ParityLedger::CLASS_PARITY_RISK_NO_FIX,
        ] as $cls) {
            $entry = [
                'parity_issue_id'   => 'TEST-' . $cls,
                'severity'          => ParityLedger::SEVERITY_MINOR,
                'classification'    => $cls,
                'expected_behavior' => 'test',
                'observed_behavior' => 'test',
            ];
            $missing = ParityLedger::validateEntry($entry);
            $this->assertEmpty($missing, "Classification {$cls} should be valid");
        }
    }

    // -----------------------------------------------------------------------
    // 6. Blackout idle clamping
    // -----------------------------------------------------------------------

    /**
     * During blackout, idle players stay Idle (not Offline) even when
     * they would otherwise transition to Offline. This prevents late-season
     * punishment for long-idle players.
     */
    public function testBlackoutClampsIdleToPreventOffline(): void
    {
        $player = [
            'activity_state'  => 'Idle',
            'idle_since_tick' => 100,
            'online_current'  => 1,
            'last_seen_at'    => date('Y-m-d H:i:s', time() - TMC_PRESENCE_STALE_OFFLINE_SECONDS - 10),
        ];
        $season = ['blackout_time' => 500];

        // During blackout: should stay Idle
        $state = Economy::resolveEconomicPresenceState($player, $season, 500 + FORCED_OFFLINE_IDLE_HOLD_TICKS);
        $this->assertSame('Idle', $state, 'Blackout clamps idle → prevents Offline transition');

        // Before blackout with same conditions: should go Offline
        $season2 = ['blackout_time' => 99999];
        $state2 = Economy::resolveEconomicPresenceState($player, $season2, 100 + FORCED_OFFLINE_IDLE_HOLD_TICKS);
        $this->assertSame('Offline', $state2, 'Outside blackout, same conditions → Offline');
    }
}
