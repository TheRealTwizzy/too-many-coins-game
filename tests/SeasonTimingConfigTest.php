<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/game_time.php';

class SeasonTimingConfigTest extends TestCase
{
    public function testSeasonDurationIsFourteenDaysInTicks(): void
    {
        // Use TICK_REAL_SECONDS constant (fixed at bootstrap) instead of ticks_from_real_seconds()
        // to avoid test-isolation failures when other test files set TMC_TICK_REAL_SECONDS=3600
        // at file scope, causing the dynamic function to return a different value than the
        // bootstrap-defined SEASON_DURATION constant.
        $this->assertSame(
            intdiv(1209600, TICK_REAL_SECONDS),
            SEASON_DURATION,
            'SEASON_DURATION must represent 14 real days in ticks.'
        );
    }

    public function testSeasonCadenceIsSevenDaysInTicks(): void
    {
        $this->assertSame(
            intdiv(604800, TICK_REAL_SECONDS),
            SEASON_CADENCE,
            'SEASON_CADENCE must represent 7 real days in ticks.'
        );
    }

    public function testSeasonOverlapIsOneWeek(): void
    {
        $this->assertSame(
            SEASON_DURATION,
            SEASON_CADENCE * 2,
            'Duration should be exactly 2x cadence (14-day season, new every 7 days).'
        );
    }

    public function testInitialSeasonStartsAtTickOneAndSecondStartsAfterCadence(): void
    {
        $this->assertSame(1, GameTime::seasonStartTime(1));
        $this->assertSame(1 + SEASON_CADENCE, GameTime::seasonStartTime(2));
    }

    public function testSeasonWindowMathUsesDurationAndBlackoutOffsets(): void
    {
        $start = GameTime::seasonStartTime(1);
        $end = GameTime::seasonEndTime($start);
        $blackout = GameTime::blackoutStartTime($end);

        $this->assertSame(SEASON_DURATION, $end - $start);
        $this->assertSame(BLACKOUT_DURATION, $end - $blackout);
    }
}
