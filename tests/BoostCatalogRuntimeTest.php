<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/boost_catalog.php';

class BoostCatalogRuntimeTest extends TestCase
{
    public function testBoostTimeCapIsTacticalFourHourWindow(): void
    {
        $this->assertSame(4 * 60 * 60, BoostCatalog::TIME_CAP_SECONDS_PER_PRODUCT);
        $this->assertSame(
            ticks_from_real_seconds(4 * 60 * 60),
            ticks_from_real_seconds(BoostCatalog::TIME_CAP_SECONDS_PER_PRODUCT)
        );
    }

    public function testPerProductPowerCapStaysBelowCombinedTotalCap(): void
    {
        $this->assertSame(1000000, BoostCatalog::POWER_CAP_FP_PER_PRODUCT);
        $this->assertSame(5000000, BoostCatalog::TOTAL_POWER_CAP_FP);
        $this->assertLessThan(BoostCatalog::TOTAL_POWER_CAP_FP, BoostCatalog::POWER_CAP_FP_PER_PRODUCT);
    }

    public function testTierOneInitialDurationIsCappedToFourHourWindow(): void
    {
        $this->assertSame(
            BoostCatalog::TIME_CAP_SECONDS_PER_PRODUCT,
            BoostCatalog::getInitialDurationRealSecondsForTier(1)
        );
        $this->assertSame(
            ticks_from_real_seconds(BoostCatalog::TIME_CAP_SECONDS_PER_PRODUCT),
            BoostCatalog::getInitialDurationTicksForTier(1)
        );
    }

    public function testInitialDurationsNeverExceedConfiguredTimeCap(): void
    {
        $capSeconds = BoostCatalog::TIME_CAP_SECONDS_PER_PRODUCT;
        $capTicks = ticks_from_real_seconds($capSeconds);

        for ($tier = 1; $tier <= 5; $tier++) {
            $this->assertLessThanOrEqual($capSeconds, BoostCatalog::getInitialDurationRealSecondsForTier($tier));
            $this->assertLessThanOrEqual($capTicks, BoostCatalog::getInitialDurationTicksForTier($tier));
        }
    }

    public function testNormalizedCatalogDurationUsesCappedInitialWindow(): void
    {
        $boost = BoostCatalog::normalize([
            'boost_id' => 1,
            'tier_required' => 1,
            'modifier_fp' => 50000,
        ]);

        $this->assertSame(BoostCatalog::TIME_CAP_SECONDS_PER_PRODUCT, $boost['duration_real_seconds']);
        $this->assertSame(
            ticks_from_real_seconds(BoostCatalog::TIME_CAP_SECONDS_PER_PRODUCT),
            $boost['duration_ticks']
        );
    }

    public function testEffectiveExpiresTickHonorsOriginalSessionCap(): void
    {
        $activatedTick = 10;
        $sessionMax = BoostCatalog::getSessionMaxExpiresTick($activatedTick);

        $this->assertSame(
            $sessionMax,
            BoostCatalog::getEffectiveExpiresTick($sessionMax + 100, $activatedTick)
        );
        $this->assertSame(
            $sessionMax - 5,
            BoostCatalog::getEffectiveExpiresTick($sessionMax - 5, $activatedTick)
        );
    }

    public function testTierFiveInitialDurationRemainsBurstWindow(): void
    {
        $this->assertSame(30 * 60, BoostCatalog::getInitialDurationRealSecondsForTier(5));
        $this->assertSame(
            ticks_from_real_seconds(30 * 60),
            BoostCatalog::getInitialDurationTicksForTier(5)
        );
    }

    public function testInitialDurationsFollowBurstWindowByTier(): void
    {
        $expectedSeconds = [
            1 => 4 * 60 * 60,
            2 => 3 * 60 * 60,
            3 => 2 * 60 * 60,
            4 => 60 * 60,
            5 => 30 * 60,
        ];

        foreach ($expectedSeconds as $tier => $seconds) {
            $this->assertSame($seconds, BoostCatalog::getInitialDurationRealSecondsForTier($tier));
            $this->assertSame(ticks_from_real_seconds($seconds), BoostCatalog::getInitialDurationTicksForTier($tier));
        }
    }

    public function testTimeExtensionsFollowBurstWindowByTier(): void
    {
        $expectedSeconds = [
            1 => 5 * 60,
            2 => 10 * 60,
            3 => 30 * 60,
            4 => 60 * 60,
            5 => 2 * 60 * 60,
        ];

        foreach ($expectedSeconds as $tier => $seconds) {
            $this->assertSame($seconds, BoostCatalog::getTimeExtensionRealSecondsForTier($tier));
            $this->assertSame(ticks_from_real_seconds($seconds), BoostCatalog::getTimeExtensionTicksForTier($tier));
            $this->assertSame($seconds, BoostCatalog::getSpendTimeRealSecondsForTier($tier));
            $this->assertSame(ticks_from_real_seconds($seconds), BoostCatalog::getSpendTimeTicksForTier($tier));
        }
    }
}
