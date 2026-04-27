<?php

use PHPUnit\Framework\TestCase;

putenv('TMC_TICK_REAL_SECONDS=60');

require_once __DIR__ . '/../includes/config.php';

class SigilAbilityFlowTest extends TestCase
{
    public function testFreezeSpendTiersExposeMidTierUtility(): void
    {
        $this->assertSame([4, 5, 6], array_values(SIGIL_FREEZE_SPEND_TIERS));
    }

    public function testFreezeDurationScalesBySpendTier(): void
    {
        $expectedTier4 = max(1, intdiv((int)ABILITY_UNIT_DURATION_TICKS, 2));

        $this->assertSame($expectedTier4, (int)SIGIL_FREEZE_DURATION_TICKS_BY_TIER[4]);
        $this->assertSame((int)ABILITY_UNIT_DURATION_TICKS, (int)SIGIL_FREEZE_DURATION_TICKS_BY_TIER[5]);
        $this->assertSame((int)FREEZE_BASE_DURATION_TICKS, (int)SIGIL_FREEZE_DURATION_TICKS_BY_TIER[6]);
        $this->assertSame((int)FREEZE_STACK_EXTENSION_TICKS, (int)SIGIL_FREEZE_STACK_EXTENSION_TICKS_BY_TIER[6]);
    }

    public function testBlackoutFreezeDurationsRemainHalfStrength(): void
    {
        foreach (SIGIL_FREEZE_SPEND_TIERS as $tier) {
            $this->assertSame(
                max(1, intdiv((int)SIGIL_FREEZE_DURATION_TICKS_BY_TIER[$tier], 2)),
                (int)SIGIL_FREEZE_BLACKOUT_DURATION_TICKS_BY_TIER[$tier]
            );
            $this->assertSame(
                max(1, intdiv((int)SIGIL_FREEZE_STACK_EXTENSION_TICKS_BY_TIER[$tier], 2)),
                (int)SIGIL_FREEZE_BLACKOUT_STACK_EXTENSION_TICKS_BY_TIER[$tier]
            );
        }
    }
}
