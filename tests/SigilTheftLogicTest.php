<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/config.php';

class SigilTheftLogic
{
    public static function computeValue(array $counts): int
    {
        $total = 0;
        for ($tier = 1; $tier <= 6; $tier++) {
            $total += max(0, (int)($counts[$tier - 1] ?? 0)) * max(0, (int)(SIGIL_UTILITY_VALUE_BY_TIER[$tier] ?? 0));
        }
        return $total;
    }

    public static function computeChanceFp(int $spendValue, int $requestedValue): int
    {
        if ($spendValue <= 0 || $requestedValue <= 0) {
            return 0;
        }

        $denominator = $spendValue + ((int)SIGIL_THEFT_VALUE_PRESSURE_MULTIPLIER * $requestedValue);
        return min((int)SIGIL_THEFT_SUCCESS_CAP_FP, intdiv($spendValue * FP_SCALE, max(1, $denominator)));
    }
}

class SigilTheftLogicTest extends TestCase
{
    public function testOnlyTierFourAndFiveAreValidSpendTiers(): void
    {
        $this->assertSame([4, 5], array_values(SIGIL_THEFT_SPEND_TIERS));
    }

    public function testTierSixIsValidTheftTargetTier(): void
    {
        $this->assertContains(6, SIGIL_THEFT_TARGET_TIERS);
    }

    public function testEqualSpendAndRequestedValueProducesTwentyFivePercentChance(): void
    {
        $this->assertSame(250000, SigilTheftLogic::computeChanceFp(3000, 3000));
    }

    public function testTheftChanceIsCappedAtSixtyPercent(): void
    {
        $this->assertSame(600000, SigilTheftLogic::computeChanceFp(100000, 100));
    }

    public function testTierSixLootRequiresMoreThanOneTierFiveSpend(): void
    {
        $singleTierFiveSpend = SigilTheftLogic::computeValue([0, 0, 0, 0, 1, 0]);
        $singleTierSixLoot = SigilTheftLogic::computeValue([0, 0, 0, 0, 0, 1]);

        $this->assertLessThanOrEqual($singleTierFiveSpend * 2, $singleTierSixLoot);
        $this->assertGreaterThan($singleTierFiveSpend, $singleTierSixLoot);
    }
}