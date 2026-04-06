<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/game_time.php';
require_once __DIR__ . '/../includes/economy.php';

class SeasonJoinAccrualSmokeTest extends TestCase
{
    /**
     * Mirrors Actions::seasonJoin status + late-join gate logic without DB coupling.
     */
    private function canJoinSeasonAtTick(array $season, int $gameTime): bool
    {
        $status = GameTime::getSeasonStatus($season, $gameTime);
        if ($status === 'Scheduled' || $status === 'Expired') {
            return false;
        }

        if ($gameTime >= ((int)$season['end_time'] - 1)) {
            return false;
        }

        return true;
    }

    private function baselineSeasonWindow(int $seasonSeq): array
    {
        $start = GameTime::seasonStartTime($seasonSeq);
        $end = GameTime::seasonEndTime($start);
        $blackout = GameTime::blackoutStartTime($end);

        return [
            'season_id' => $seasonSeq,
            'start_time' => $start,
            'end_time' => $end,
            'blackout_time' => $blackout,
            'status' => 'Scheduled',
        ];
    }

    public function testInitialSeasonRunsBeforeNextCadenceBoundary(): void
    {
        $season1 = $this->baselineSeasonWindow(1);
        $season2 = $this->baselineSeasonWindow(2);

        $this->assertSame(SEASON_CADENCE, $season2['start_time'] - $season1['start_time']);
        $this->assertSame(SEASON_DURATION, $season1['end_time'] - $season1['start_time']);

        $tickBeforeSeason2 = $season2['start_time'] - 1;
        $this->assertSame('Active', GameTime::getSeasonStatus($season1, $tickBeforeSeason2));
        $this->assertSame('Scheduled', GameTime::getSeasonStatus($season2, $tickBeforeSeason2));
    }

    public function testJoinGateAllowsActiveAndBlackoutButBlocksScheduledExpiredAndLastTick(): void
    {
        $season = $this->baselineSeasonWindow(1);

        $activeTick = (int)$season['start_time'];
        $blackoutTick = (int)$season['blackout_time'];
        $scheduledTick = (int)$season['start_time'] - 1;
        $expiredTick = (int)$season['end_time'];
        $tooLateTick = (int)$season['end_time'] - 1;

        $this->assertTrue($this->canJoinSeasonAtTick($season, $activeTick));
        $this->assertTrue($this->canJoinSeasonAtTick($season, $blackoutTick));
        $this->assertFalse($this->canJoinSeasonAtTick($season, $scheduledTick));
        $this->assertFalse($this->canJoinSeasonAtTick($season, $expiredTick));
        $this->assertFalse($this->canJoinSeasonAtTick($season, $tooLateTick));
    }

    public function testFirstTickAccrualProducesPositiveNetCoinsForActiveParticipant(): void
    {
        $season = [
            'base_ubi_active_per_tick' => 30,
            'base_ubi_idle_factor_fp' => 250000,
            'ubi_min_per_tick' => 1,
            'total_coins_supply' => 0,
            'inflation_table' => json_encode([
                ['x' => 0, 'factor_fp' => 1000000],
                ['x' => 1000000, 'factor_fp' => 1000000],
            ]),
            'hoarding_min_factor_fp' => 90000,
            'target_spend_rate_per_tick' => 18,
            'hoarding_sink_enabled' => 0,
        ];

        $player = [
            'participation_enabled' => 1,
            'activity_state' => 'Active',
        ];

        $participation = [
            'coins' => 0,
            'coins_fractional_fp' => 0,
            'spend_window_total' => 0,
        ];

        $rates = Economy::calculateRateBreakdown($season, $player, $participation, 0, false, false);
        $this->assertGreaterThan(0, (int)$rates['net_rate_fp']);

        $ticksToProcess = 1;
        $totalNetFp = ((int)$rates['net_rate_fp'] * $ticksToProcess) + (int)$participation['coins_fractional_fp'];
        [$coinsMinted, $carryFp] = Economy::splitFixedPoint($totalNetFp);

        $this->assertGreaterThan(0, $coinsMinted, 'First processed tick should mint at least one whole coin for an active player.');
        $this->assertGreaterThanOrEqual(0, $carryFp);
    }

    public function testDefaultReactivationWindowConstantIsPositive(): void
    {
        $this->assertGreaterThan(0, STARPRICE_REACTIVATION_WINDOW_TICKS_DEFAULT);
        $this->assertGreaterThan(IDLE_TIMEOUT_TICKS, STARPRICE_REACTIVATION_WINDOW_TICKS_DEFAULT);
    }
}
