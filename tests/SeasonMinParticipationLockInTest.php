<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/config.php';

class SeasonMinParticipationLockInTest extends TestCase
{
    /**
     * Verify the constant is defined and is a positive integer consistent
     * with the 12-real-hour design intent (at 1 tick/sec = 43,200 ticks).
     */
    public function testMinSeasonalLockInTicksIsPositive(): void
    {
        $this->assertGreaterThan(0, MIN_SEASONAL_LOCK_IN_TICKS,
            'MIN_SEASONAL_LOCK_IN_TICKS must be > 0');
    }

    public function testMinSeasonalLockInTicksIsAtLeastOneRealHour(): void
    {
        // At minimum the threshold should represent 1 real hour of play.
        $oneHourTicks = ticks_from_real_seconds(3600);
        $this->assertGreaterThanOrEqual($oneHourTicks, MIN_SEASONAL_LOCK_IN_TICKS,
            'MIN_SEASONAL_LOCK_IN_TICKS should require at least 1 real hour of season play');
    }

    public function testMinSeasonalLockInTicksIsLessThanHalfBlackoutDuration(): void
    {
        // The threshold must be achievable by a player who joins and plays normally.
        // Capping at half the blackout duration is a sanity bound.
        $halfBlackout = (int)floor(SIGIL_BLACKOUT_DURATION_TICKS / 2);
        $this->assertLessThan($halfBlackout, MIN_SEASONAL_LOCK_IN_TICKS,
            'MIN_SEASONAL_LOCK_IN_TICKS must be achievable before late season');
    }
}
