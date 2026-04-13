<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/economy.php';

class HoardingSinkPhaseGateTest extends TestCase
{
    private function makeSeason(array $overrides = []): array
    {
        return array_merge([
            'hoarding_sink_enabled'         => 1,
            'hoarding_safe_hours'           => 0,
            'hoarding_safe_min_coins'       => 0,
            'hoarding_tier1_excess_cap'     => 50000,
            'hoarding_tier2_excess_cap'     => 200000,
            'hoarding_tier1_rate_hourly_fp' => 200,
            'hoarding_tier2_rate_hourly_fp' => 500,
            'hoarding_tier3_rate_hourly_fp' => 1000,
            'hoarding_sink_cap_ratio_fp'    => FP_SCALE * 100, // uncapped for test
            'hoarding_idle_multiplier_fp'   => FP_SCALE,
            'blackout_started_tick'         => null,
            'blackout_time'                 => PHP_INT_MAX,
            'end_time'                      => PHP_INT_MAX,
            'total_coins_supply'            => 0,
            'inflation_table'               => json_encode([['x' => 0, 'factor_fp' => FP_SCALE]]),
            // Required for calculateRateBreakdown (gross rate must be > 0 for sink cap to be > 0)
            'base_ubi_active_per_tick'      => 30,
            'base_ubi_idle_factor_fp'       => 250000,
            'ubi_min_per_tick'              => 1,
            'hoarding_min_factor_fp'        => 100000,
            'target_spend_rate_per_tick'    => 50,
            'spend_window_total'            => 0,
        ], $overrides);
    }

    private function makePlayer(int $gameTime = 1000): array
    {
        return [
            'participation_enabled'   => 1,
            'activity_state'          => 'Active',
            'economic_presence_state' => 'Active',
            'current_game_time'       => $gameTime,
            'idle_since_tick'         => null,
            'online_current'          => 1,
            'last_seen_at'            => date('Y-m-d H:i:s'),
        ];
    }

    private function makeParticipation(int $coins = 300000): array
    {
        return ['coins' => $coins];
    }

    private function grossFp(): int
    {
        return Economy::toFixedPoint(100); // 100 coins/tick
    }

    public function testSinkAppliesInEarlyPhase(): void
    {
        $sink = Economy::calculateHoardingSinkCoinsPerTick(
            $this->makeSeason(),
            $this->makePlayer(),
            $this->makeParticipation(),
            $this->grossFp(),
            SIGIL_SEASON_PHASE_EARLY
        );
        $this->assertGreaterThan(0, $sink, 'Sink must fire in EARLY phase');
    }

    public function testSinkAppliesInMidPhase(): void
    {
        $sink = Economy::calculateHoardingSinkCoinsPerTick(
            $this->makeSeason(),
            $this->makePlayer(),
            $this->makeParticipation(),
            $this->grossFp(),
            SIGIL_SEASON_PHASE_MID
        );
        $this->assertGreaterThan(0, $sink, 'Sink must fire in MID phase');
    }

    public function testSinkSuppressedInLateActivePhase(): void
    {
        $sink = Economy::calculateHoardingSinkCoinsPerTick(
            $this->makeSeason(),
            $this->makePlayer(),
            $this->makeParticipation(),
            $this->grossFp(),
            SIGIL_SEASON_PHASE_LATE_ACTIVE
        );
        $this->assertSame(0, $sink, 'Sink must be 0 in LATE_ACTIVE phase (lock-in window)');
    }

    public function testSinkSuppressedInBlackoutPhase(): void
    {
        $sink = Economy::calculateHoardingSinkCoinsPerTick(
            $this->makeSeason(),
            $this->makePlayer(),
            $this->makeParticipation(),
            $this->grossFp(),
            SIGIL_SEASON_PHASE_BLACKOUT
        );
        $this->assertSame(0, $sink, 'Sink must be 0 in BLACKOUT phase');
    }

    public function testSinkAppliesWhenPhaseIsNull(): void
    {
        // No phase passed = legacy / unconstrained path; sink must still fire (backward compat)
        $sink = Economy::calculateHoardingSinkCoinsPerTick(
            $this->makeSeason(),
            $this->makePlayer(),
            $this->makeParticipation(),
            $this->grossFp(),
            null
        );
        $this->assertGreaterThan(0, $sink, 'Sink with null phase must fire for backward compat');
    }

    public function testRateBreakdownPassesPhaseToSink(): void
    {
        $season = $this->makeSeason();
        $player = $this->makePlayer();
        $part   = $this->makeParticipation();

        $ratesLate = Economy::calculateRateBreakdown(
            $season, $player, $part, 0, false, false, SIGIL_SEASON_PHASE_LATE_ACTIVE
        );
        $ratesEarly = Economy::calculateRateBreakdown(
            $season, $player, $part, 0, false, false, SIGIL_SEASON_PHASE_EARLY
        );

        $this->assertSame(0, $ratesLate['sink_per_tick'],
            'calculateRateBreakdown must pass phase; LATE_ACTIVE sink must be 0');
        $this->assertGreaterThan(0, $ratesEarly['sink_per_tick'],
            'calculateRateBreakdown must pass phase; EARLY sink must be > 0');
    }
}
