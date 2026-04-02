<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/game_time.php';

class SeasonTimingConfigTest extends TestCase
{
    public function testSeasonDurationIsFourteenDaysInTicks(): void
    {
        $this->assertSame(
            ticks_from_real_seconds(1209600),
            SEASON_DURATION,
            'SEASON_DURATION must represent 14 real days in ticks.'
        );
    }

    public function testSeasonCadenceIsSevenDaysInTicks(): void
    {
        $this->assertSame(
            ticks_from_real_seconds(604800),
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
