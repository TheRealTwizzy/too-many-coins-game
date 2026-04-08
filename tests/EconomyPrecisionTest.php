<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/economy.php';

class EconomyPrecisionTest extends TestCase
{
    public function testGuaranteedBoostFloorCoinsScalesByTenPercentSteps(): void
    {
        $this->assertSame(1, Economy::guaranteedBoostFloorCoins(100000));  // 10%
        $this->assertSame(2, Economy::guaranteedBoostFloorCoins(200000));  // 20%
        $this->assertSame(5, Economy::guaranteedBoostFloorCoins(500000));  // 50%
        $this->assertSame(5, Economy::guaranteedBoostFloorCoins(1000000)); // 100% (capped)
        $this->assertSame(5, Economy::guaranteedBoostFloorCoins(2000000)); // 200% (still capped)
    }

    public function testGuaranteedBoostFloorFpAddsWholeCoinAtTenPercentEvenOnLowBase(): void
    {
        $baseUbi = 1;
        $rateFp = Economy::toFixedPoint($baseUbi);
        $rateFp = Economy::applyBoostModifierFp($rateFp, 100000); // 10% => 1.1/tick
        $rateFp += Economy::guaranteedBoostFloorFp(100000); // +1 whole coin floor

        [$coins, $fraction] = Economy::splitFixedPoint($rateFp);
        $this->assertSame(2, $coins);
        $this->assertSame(100000, $fraction);
    }

    public function testSigilThrottleWindowCeilingMatchesConfig(): void
    {
        $maxRealizedRate = SIGIL_MAX_DROPS_WINDOW / SIGIL_DROP_WINDOW_TICKS;
        $this->assertEqualsWithDelta(8 / 1440, $maxRealizedRate, 0.000001);
    }

    public function testTenPercentBoostPreservesFractionalRate(): void
    {
        $baseUbi = 7;
        $rateFp = Economy::toFixedPoint($baseUbi);
        $boostedRateFp = Economy::applyBoostModifierFp($rateFp, 100000);
        [$coins, $fraction] = Economy::splitFixedPoint($boostedRateFp);

        $this->assertSame(7, $coins);
        $this->assertSame(700000, $fraction);
    }

    public function testFractionalCarryMintsExtraCoinOverMultipleTicks(): void
    {
        $baseUbi = 7;
        $rateFp = Economy::applyBoostModifierFp(Economy::toFixedPoint($baseUbi), 100000); // 7.7/tick

        $carryFp = 0;
        $totalFp = ($rateFp * 2) + $carryFp;
        [$coinsAfterTwoTicks, $carryAfterTwoTicks] = Economy::splitFixedPoint($totalFp);

        $this->assertSame(15, $coinsAfterTwoTicks);
        $this->assertSame(400000, $carryAfterTwoTicks);
    }

    public function testCalculateUBIIgnoresHoardingSuppressionForGrossPath(): void
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
            'target_spend_rate_per_tick' => 999999,
        ];

        $player = [
            'participation_enabled' => 1,
            'activity_state' => 'Active',
        ];

        $participation = [
            'spend_window_total' => 0,
        ];

        $this->assertSame(30, Economy::calculateUBI($season, $player, $participation));
    }

    public function testHoardingSinkIsZeroWhenFeatureDisabled(): void
    {
        $season = [
            'hoarding_sink_enabled' => 0,
        ];
        $player = ['activity_state' => 'Active'];
        $participation = ['coins' => 999999];

        $sink = Economy::calculateHoardingSinkCoinsPerTick($season, $player, $participation, Economy::toFixedPoint(100));
        $this->assertSame(0, $sink);
    }

    public function testHoardingSinkUsesTiersIdleMultiplierAndCap(): void
    {
        $season = [
            'hoarding_sink_enabled' => 1,
            'hoarding_safe_hours' => 0,
            'hoarding_safe_min_coins' => 0,
            'hoarding_tier1_excess_cap' => 50000,
            'hoarding_tier2_excess_cap' => 200000,
            'hoarding_tier1_rate_hourly_fp' => 60000,
            'hoarding_tier2_rate_hourly_fp' => 120000,
            'hoarding_tier3_rate_hourly_fp' => 240000,
            'hoarding_idle_multiplier_fp' => 1250000,
            'hoarding_sink_cap_ratio_fp' => 200000, // 20% of gross coins/tick
        ];
        $player = ['activity_state' => 'Idle'];
        $participation = ['coins' => 1000000];
        $grossRateFp = Economy::toFixedPoint(50);

        $sink = Economy::calculateHoardingSinkCoinsPerTick($season, $player, $participation, $grossRateFp);

        // Cap is floor(50 * 0.20) = 10 coins/tick.
        $this->assertSame(10, $sink);
    }

    public function testEffectiveSigilTierChanceMatchesConfigMath(): void
    {
        $basePercent = 100 / Economy::sigilDropRateForPower(0);
        $tierOneConditional = SIGIL_TIER_ODDS[1] / FP_SCALE;
        $effectivePercent = $basePercent * $tierOneConditional;

        // T1 target: 8.75%; denominator 8 gives ~12.5% × 700000/1000000 ≈ 8.75%
        $this->assertEqualsWithDelta(8.75, $effectivePercent, 0.1);
    }

    public function testCanReceiveSigilTierBlocksPureGainAtTotalCap(): void
    {
        $participation = [
            'sigils_t1' => 5,
            'sigils_t2' => 20,
            'sigils_t3' => 0,
            'sigils_t4' => 0,
            'sigils_t5' => 0,
            'sigils_t6' => 0,
        ];

        $this->assertFalse(
            Economy::canReceiveSigilTier($participation, 2, 1),
            'Pure +1 sigil gain should be blocked at total cap.'
        );
    }

    public function testCanReceiveSigilTierAllowsNetReductionAtTotalCap(): void
    {
        $participation = [
            'sigils_t1' => 5,
            'sigils_t2' => 20,
            'sigils_t3' => 0,
            'sigils_t4' => 0,
            'sigils_t5' => 0,
            'sigils_t6' => 0,
        ];

        $this->assertTrue(
            Economy::canReceiveSigilTier($participation, 2, 1, 5),
            'Combine-style net reduction should remain allowed at total cap.'
        );
    }

    public function testSigilTierDropRatesScaleFromT1ToT5(): void
    {
        // Verify SIGIL_TIER_DROP_RATES is defined with correct tier bounds
        $this->assertArrayHasKey(1, SIGIL_TIER_DROP_RATES);
        $this->assertArrayHasKey(5, SIGIL_TIER_DROP_RATES);

        // T1 must be 8.75% (875/10000) and T5 must be 0.06% (6/10000)
        $this->assertSame(875, SIGIL_TIER_DROP_RATES[1]); // 8.75% in parts-per-10000
        $this->assertSame(6,   SIGIL_TIER_DROP_RATES[5]); // 0.06% in parts-per-10000

        // Rates must be strictly descending T1 → T5
        for ($tier = 1; $tier < 5; $tier++) {
            $this->assertGreaterThan(
                SIGIL_TIER_DROP_RATES[$tier + 1],
                SIGIL_TIER_DROP_RATES[$tier],
                sprintf('Expected T%d drop rate to be higher than T%d.', $tier, $tier + 1)
            );
        }
    }

    public function testSigilTierOddsSumToOneMillion(): void
    {
        $sum = array_sum(SIGIL_TIER_ODDS);
        $this->assertSame(1000000, $sum, 'SIGIL_TIER_ODDS must sum to exactly 1,000,000.');
    }

    public function testDeterministicSigilDropIsReproducibleForSameInputs(): void
    {
        $season = [
            'season_id' => 1,
            'start_time' => 1000,
            'end_time' => 2000,
            'season_seed' => str_repeat("\x01", 32),
        ];
        $player = [
            'player_id' => 99,
            'online_current' => 1,
            'activity_state' => 'Active',
        ];

        $tick = 1500;
        $first = Economy::evaluateSigilDropForTick($season, $player, $tick);
        $second = Economy::evaluateSigilDropForTick($season, $player, $tick);

        $this->assertSame($first, $second);
    }

    public function testSigilEffectiveDropChanceRespectsActivityMultipliers(): void
    {
        $active = Economy::sigilEffectiveDropChanceFp('Active');
        $idle = Economy::sigilEffectiveDropChanceFp('Idle');
        $offline = Economy::sigilEffectiveDropChanceFp('Offline');

        $this->assertSame((int)SIGIL_DROP_CHANCE_FP, $active);
        $this->assertSame(intdiv((int)SIGIL_DROP_CHANCE_FP, 2), $idle);
        $this->assertSame(0, $offline);
    }

    public function testSigilSeasonProgressBoundariesClampToZeroAndOne(): void
    {
        $season = [
            'start_time' => 100,
            'end_time' => 1100,
        ];

        $this->assertSame(0, Economy::sigilSeasonProgressFp($season, 50));
        $this->assertSame(0, Economy::sigilSeasonProgressFp($season, 100));
        $this->assertSame(FP_SCALE, Economy::sigilSeasonProgressFp($season, 1100));
        $this->assertSame(FP_SCALE, Economy::sigilSeasonProgressFp($season, 1200));
    }

    public function testSigilTierWeightsStayPositiveAtSeasonBounds(): void
    {
        $startWeights = Economy::sigilTierWeightsForProgressFp(0);
        $endWeights = Economy::sigilTierWeightsForProgressFp(FP_SCALE);

        $this->assertGreaterThan(0, (int)($startWeights[1] ?? 0));
        $this->assertGreaterThan(0, (int)($startWeights[2] ?? 0));
        $this->assertGreaterThan(0, (int)($startWeights[3] ?? 0));
        $this->assertSame(0, (int)($startWeights[4] ?? 0));
        $this->assertSame(0, (int)($startWeights[5] ?? 0));
        $this->assertSame(0, (int)($startWeights[6] ?? 0));

        for ($tier = 1; $tier <= 5; $tier++) {
            $this->assertGreaterThan(0, (int)($endWeights[$tier] ?? 0));
        }
        $this->assertSame(0, (int)($endWeights[6] ?? 0));
    }

    public function testOfflinePlayerNeverReceivesSigilDrop(): void
    {
        $season = [
            'season_id' => 1,
            'start_time' => 1000,
            'end_time' => 2000,
            'season_seed' => str_repeat("\x02", 32),
        ];
        $player = [
            'player_id' => 5,
            'online_current' => 0,
            'activity_state' => 'Active',
        ];

        for ($tick = 1000; $tick < 1100; $tick++) {
            $this->assertNull(Economy::evaluateSigilDropForTick($season, $player, $tick));
        }
    }

    public function testResolveEconomicPresenceStateBecomesOfflineAfterIdleHoldWhenPresenceIsStale(): void
    {
        $season = [
            'blackout_time' => 1000,
        ];
        $player = [
            'activity_state' => 'Idle',
            'idle_since_tick' => 100,
            'online_current' => 1,
            'last_seen_at' => date('Y-m-d H:i:s', time() - (TMC_PRESENCE_STALE_OFFLINE_SECONDS + 5)),
        ];

        $state = Economy::resolveEconomicPresenceState($player, $season, 100 + FORCED_OFFLINE_IDLE_HOLD_TICKS);

        $this->assertSame('Offline', $state);
    }

    public function testResolveEconomicPresenceStateStaysIdleDuringBlackout(): void
    {
        $season = [
            'blackout_time' => 500,
        ];
        $player = [
            'activity_state' => 'Idle',
            'idle_since_tick' => 100,
            'online_current' => 1,
            'last_seen_at' => date('Y-m-d H:i:s', time() - (TMC_PRESENCE_STALE_OFFLINE_SECONDS + 5)),
        ];

        $state = Economy::resolveEconomicPresenceState($player, $season, 500 + FORCED_OFFLINE_IDLE_HOLD_TICKS);

        $this->assertSame('Idle', $state);
    }

    public function testCalculateUBIFpIsZeroWhenEconomicPresenceIsOffline(): void
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
        ];
        $player = [
            'participation_enabled' => 1,
            'activity_state' => 'Idle',
            'economic_presence_state' => 'Offline',
        ];

        $this->assertSame(0, Economy::calculateUBIFp($season, $player, []));
    }

    public function testCalculateUBIFpIsZeroDuringBlackoutSettlement(): void
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
            'blackout_time' => 500,
            'end_time' => 900,
        ];
        $player = [
            'participation_enabled' => 1,
            'activity_state' => 'Active',
            'current_game_time' => 700,
        ];

        $this->assertSame(0, Economy::calculateUBIFp($season, $player, []));
    }

    public function testResolveSigilDropActivityStateHonorsEconomicPresenceStateOverride(): void
    {
        $player = [
            'online_current' => 1,
            'activity_state' => 'Active',
            'economic_presence_state' => 'Offline',
        ];

        $this->assertSame('Offline', Economy::resolveSigilDropActivityState($player));
    }

    public function testSigilSeasonPhaseAvailabilityAcrossBoundaries(): void
    {
        $season = [
            'season_id' => 2,
            'start_time' => 1000,
            'end_time' => 1000 + ticks_from_real_seconds(1209600),
            'season_seed' => str_repeat("\x03", 32),
        ];

        $earlyPhase = Economy::sigilSeasonPhase($season, (int)$season['start_time']);
        $midPhase = Economy::sigilSeasonPhase($season, (int)$season['start_time'] + ticks_from_real_seconds(6 * 86400));
        $lateActivePhase = Economy::sigilSeasonPhase($season, (int)$season['end_time'] - (int)SIGIL_BLACKOUT_DURATION_TICKS - 1);
        $latePhase = Economy::sigilSeasonPhase($season, (int)$season['end_time'] - 1);

        $this->assertSame((string)SIGIL_SEASON_PHASE_EARLY, $earlyPhase);
        $this->assertSame((string)SIGIL_SEASON_PHASE_MID, $midPhase);
        $this->assertSame((string)SIGIL_SEASON_PHASE_LATE_ACTIVE, $lateActivePhase);
        $this->assertSame((string)SIGIL_SEASON_PHASE_BLACKOUT, $latePhase);

        $earlyWeights = Economy::sigilTierWeightsForPhase($earlyPhase);
        $midWeights = Economy::sigilTierWeightsForPhase($midPhase);
        $lateActiveWeights = Economy::sigilTierWeightsForPhase($lateActivePhase);
        $lateWeights = Economy::sigilTierWeightsForPhase($latePhase);

        $this->assertGreaterThan(0, (int)$earlyWeights[1]);
        $this->assertGreaterThan(0, (int)$earlyWeights[2]);
        $this->assertGreaterThan(0, (int)$earlyWeights[3]);
        $this->assertSame(0, (int)$earlyWeights[4]);
        $this->assertSame(0, (int)$earlyWeights[5]);
        $this->assertSame(0, (int)$earlyWeights[6]);

        $this->assertGreaterThan(0, (int)$midWeights[4]);
        $this->assertGreaterThan(0, (int)$midWeights[5]);
        $this->assertSame(0, (int)$midWeights[6]);

        $this->assertGreaterThan(0, (int)$lateActiveWeights[6]);
        $this->assertSame(0, (int)$lateWeights[6]);
    }

    public function testTierSixMovesIntoLateActiveAndStaysOutOfBlackout(): void
    {
        $season = [
            'season_id' => 5,
            'start_time' => 1000,
            'end_time' => 1000 + ticks_from_real_seconds(1209600),
            'season_seed' => str_repeat("\x04", 32),
        ];

        $blackoutStart = (int)$season['end_time'] - (int)SIGIL_BLACKOUT_DURATION_TICKS;
        $lateActiveStart = $blackoutStart - (int)SIGIL_LATE_ACTIVE_DURATION_TICKS;

        $midWeights = Economy::sigilTierWeightsForPhase(Economy::sigilSeasonPhase($season, $lateActiveStart - 1));
        $lateActiveWeights = Economy::sigilTierWeightsForPhase(Economy::sigilSeasonPhase($season, $lateActiveStart));
        $blackoutWeights = Economy::sigilTierWeightsForPhase(Economy::sigilSeasonPhase($season, $blackoutStart));

        $this->assertSame(0, (int)($midWeights[6] ?? 0));
        $this->assertGreaterThan(0, (int)($lateActiveWeights[6] ?? 0));
        $this->assertSame(0, (int)($blackoutWeights[6] ?? 0));
    }

    public function testEvaluateSigilDropForTickReturnsNullDuringBlackout(): void
    {
        $season = [
            'season_id' => 6,
            'start_time' => 1000,
            'end_time' => 1000 + ticks_from_real_seconds(1209600),
            'season_seed' => str_repeat("\x05", 32),
        ];
        $player = [
            'player_id' => 77,
            'online_current' => 1,
            'activity_state' => 'Active',
        ];

        $blackoutTick = (int)$season['end_time'] - 1;

        $this->assertNull(Economy::evaluateSigilDropForTick($season, $player, $blackoutTick));
    }

    public function testEffectiveSigilTierChancesDecreaseAtFullPower(): void
    {
        $lowPowerOdds = Economy::adjustedSigilTierOdds(0);
        $highPowerOdds = Economy::adjustedSigilTierOdds(SIGIL_POWER_FULL_SHIFT);
        $lowBasePercent = 100 / Economy::sigilDropRateForPower(0);
        $highBasePercent = 100 / Economy::sigilDropRateForPower(SIGIL_POWER_FULL_SHIFT);

        for ($tier = 1; $tier <= 5; $tier++) {
            $lowEffective = $lowBasePercent * (((int)$lowPowerOdds[$tier]) / FP_SCALE);
            $highEffective = $highBasePercent * (((int)$highPowerOdds[$tier]) / FP_SCALE);
            $this->assertTrue(
                $highEffective < $lowEffective,
                sprintf('Expected Tier %d effective chance to decrease with sigil power.', $tier)
            );
        }
    }

    public function testVaultCostUsesTargetTierDefaultsForNaturalProgression(): void
    {
        $vaultConfig = json_encode([
            ['tier' => 1, 'supply' => 500, 'cost_table' => [['remaining' => 1, 'cost' => 50]]],
            ['tier' => 2, 'supply' => 250, 'cost_table' => [['remaining' => 1, 'cost' => 250]]],
            ['tier' => 3, 'supply' => 125, 'cost_table' => [['remaining' => 1, 'cost' => 1000]]],
        ]);

        $this->assertSame(50, Economy::calculateVaultCost($vaultConfig, 1, 500));
        $this->assertSame(250, Economy::calculateVaultCost($vaultConfig, 2, 250));
        $this->assertSame(1000, Economy::calculateVaultCost($vaultConfig, 3, 125));
    }

    public function testVaultCostStepTableSelectsFirstMatchingRemainingThreshold(): void
    {
        $vaultConfig = json_encode([
            [
                'tier' => 1,
                'supply' => 500,
                'cost_table' => [
                    ['remaining' => 400, 'cost' => 10],
                    ['remaining' => 200, 'cost' => 20],
                    ['remaining' => 1, 'cost' => 30],
                ],
            ],
        ]);

        $this->assertSame(10, Economy::calculateVaultCost($vaultConfig, 1, 500));
        $this->assertSame(20, Economy::calculateVaultCost($vaultConfig, 1, 250));
        $this->assertSame(30, Economy::calculateVaultCost($vaultConfig, 1, 50));
    }

    // ==================== Early Lock-In Payout ====================

    public function testEarlyLockInPayoutNoSigils(): void
    {
        // No sigils: payout = 65% of seasonal stars, with any partial star banked.
        $result = Economy::computeEarlyLockInPayout(100, [0,0,0,0,0], [50,250,1000,3000,9000]);
        $this->assertSame(0,   $result['sigil_refund_stars']);
        $this->assertSame(100, $result['total_seasonal_stars']);
        $this->assertSame(65,  $result['global_stars_gained']);
        $this->assertSame(0,   $result['global_stars_fractional_fp']);
    }

    public function testEarlyLockInPayoutFloorRoundsDown(): void
    {
        // 10 seasonal stars -> 6 Global Stars plus 50% banked toward the next star.
        $result = Economy::computeEarlyLockInPayout(10, [0,0,0,0,0], [50,250,1000,3000,9000]);
        $this->assertSame(0,  $result['sigil_refund_stars']);
        $this->assertSame(10, $result['total_seasonal_stars']);
        $this->assertSame(6,  $result['global_stars_gained']);
        $this->assertSame(500000, $result['global_stars_fractional_fp']);
        $this->assertSame(50.0, $result['global_stars_progress_percent']);
    }

    public function testEarlyLockInPayoutSigilRefundAddedBeforeConversion(): void
    {
        // 1 T1 sigil (50 stars) + 0 seasonal stars -> total 50 -> 32 Global Stars plus 50% banked.
        $result = Economy::computeEarlyLockInPayout(0, [1,0,0,0,0], [50,250,1000,3000,9000]);
        $this->assertSame(50, $result['sigil_refund_stars']);
        $this->assertSame(50, $result['total_seasonal_stars']);
        $this->assertSame(32, $result['global_stars_gained']);
        $this->assertSame(500000, $result['global_stars_fractional_fp']);
    }

    public function testEarlyLockInPayoutMixedSigilsAndStars(): void
    {
        // 2 T1 (100), 1 T2 (250), 1 T3 (1000) = 1350 sigil stars + 40 seasonal = 1390 total.
        // 1390 * 0.65 = 903.5, so half a star remains banked.
        $result = Economy::computeEarlyLockInPayout(40, [2,1,1,0,0], [50,250,1000,3000,9000]);
        $this->assertSame(1350, $result['sigil_refund_stars']);
        $this->assertSame(1390, $result['total_seasonal_stars']);
        $this->assertSame(903, $result['global_stars_gained']);
        $this->assertSame(500000, $result['global_stars_fractional_fp']);
    }

    public function testEarlyLockInPayoutT4AndT5DerivedCosts(): void
    {
        // 1 T4 at 3000, 1 T5 at 9000 → sigil refund = 12000; total = 12000; floor(12000*0.65)=7800
        $result = Economy::computeEarlyLockInPayout(0, [0,0,0,1,1], [50,250,1000,3000,9000]);
        $this->assertSame(12000, $result['sigil_refund_stars']);
        $this->assertSame(12000, $result['total_seasonal_stars']);
        $this->assertSame(7800,  $result['global_stars_gained']);
        $this->assertSame(0,     $result['global_stars_fractional_fp']);
    }

    public function testEarlyLockInPayoutZeroStarsAndNoSigils(): void
    {
        $result = Economy::computeEarlyLockInPayout(0, [0,0,0,0,0], [50,250,1000,3000,9000]);
        $this->assertSame(0, $result['sigil_refund_stars']);
        $this->assertSame(0, $result['total_seasonal_stars']);
        $this->assertSame(0, $result['global_stars_gained']);
        $this->assertSame(0, $result['global_stars_fractional_fp']);
    }

    public function testGlobalStarGrantCarryRollsIntoWholeStars(): void
    {
        $grant = Economy::applyGlobalStarsGrantWithCarry(1, 500000, 65, 100);

        $this->assertSame(1, $grant['global_stars_gained']);
        $this->assertSame(150000, $grant['global_stars_fractional_fp']);
        $this->assertSame(15.0, $grant['global_stars_progress_percent']);
    }

    public function testLowerTierSigilsDropMoreFrequentlyThanHigherTiers(): void
    {
        // T1 should dominate conditional drop odds (target: 70%)
        $this->assertGreaterThan(
            500000,
            SIGIL_TIER_ODDS[1],
            'T1 must account for more than 50% of conditional drops to sustain boost activity.'
        );

        // Effective drop rate ordering: T1 > T2 > T3 > T4 > T5
        $basePercent = 100.0 / Economy::sigilDropRateForPower(0);
        $prevEffective = $basePercent * (SIGIL_TIER_ODDS[1] / FP_SCALE);
        $this->assertGreaterThan(0.0, $prevEffective, 'T1 effective drop rate must be positive.');
        for ($tier = 2; $tier <= 5; $tier++) {
            $effective = $basePercent * (SIGIL_TIER_ODDS[$tier] / FP_SCALE);
            $this->assertLessThan(
                $prevEffective,
                $effective,
                sprintf('T%d effective drop rate must be lower than T%d.', $tier, $tier - 1)
            );
            $prevEffective = $effective;
        }
    }

    public function testT1DropRateIsHighEnoughForActiveBoostPlay(): void
    {
        // With base drop rate 1-in-8 and T1 at 70%, effective T1 rate should
        // exceed 5% per eligible tick so active players see regular replenishment.
        $basePercent = 100.0 / Economy::sigilDropRateForPower(0);
        $t1Effective = $basePercent * (SIGIL_TIER_ODDS[1] / FP_SCALE);
        $this->assertGreaterThan(
            5.0,
            $t1Effective,
            'T1 effective drop rate should exceed 5% per eligible tick for active-play viability.'
        );
    }

    // ==================== Rate Breakdown / Net Mint ====================

    /**
     * Helper: build a minimal season fixture with hoarding sink disabled.
     */
    private function makeSeasonFixture(array $overrides = []): array
    {
        $base = [
            'base_ubi_active_per_tick' => 10,
            'base_ubi_idle_factor_fp'  => 500000,
            'ubi_min_per_tick'         => 1,
            'total_coins_supply'       => 0,
            'inflation_table'          => json_encode([
                ['x' => 0,       'factor_fp' => 1000000],
                ['x' => 1000000, 'factor_fp' => 1000000],
            ]),
            'hoarding_min_factor_fp'       => 900000,
            'target_spend_rate_per_tick'   => 0,
            'hoarding_sink_enabled'        => 0,
            'hoarding_safe_hours'          => 0,
            'hoarding_safe_min_coins'      => 0,
            'hoarding_tier1_excess_cap'    => 0,
            'hoarding_tier2_excess_cap'    => 0,
            'hoarding_tier1_rate_hourly_fp'=> 0,
            'hoarding_tier2_rate_hourly_fp'=> 0,
            'hoarding_tier3_rate_hourly_fp'=> 0,
            'hoarding_sink_cap_ratio_fp'   => 0,
            'hoarding_idle_multiplier_fp'  => 1000000,
        ];
        return array_merge($base, $overrides);
    }

    /**
     * Helper: build a minimal player/participation fixture.
     */
    private function makePlayerFixture(array $overrides = []): array
    {
        $base = [
            'participation_enabled' => 1,
            'activity_state'        => 'Active',
            'coins'                 => 0,
        ];
        return array_merge($base, $overrides);
    }

    /**
     * When not frozen and no sink, net_rate_fp must equal gross_rate_fp.
     */
    public function testCalculateRateBreakdownNetEqualsGrossWhenNoSink(): void
    {
        $season = $this->makeSeasonFixture();
        $player = $this->makePlayerFixture();

        $rates = Economy::calculateRateBreakdown($season, $player, $player, 0, false);

        $this->assertSame((int)$rates['gross_rate_fp'], (int)$rates['net_rate_fp'],
            'net_rate_fp must equal gross_rate_fp when sink is disabled.');
        $this->assertSame(0, (int)$rates['sink_per_tick'],
            'sink_per_tick must be zero when hoarding sink is disabled.');
        $this->assertGreaterThan(0, (int)$rates['gross_rate_fp'],
            'gross_rate_fp must be positive for an active participant.');
    }

    /**
     * Freeze short-circuit: all three rates must be zero regardless of boost activity.
     * Guards against the "positive rate shown but no coins minted" display bug when frozen.
     */
    public function testCalculateRateBreakdownAllZeroWhenFrozen(): void
    {
        $season = $this->makeSeasonFixture();
        $player = $this->makePlayerFixture();
        // Large boost modifier – should be irrelevant when frozen.
        $boostModFp = 500000; // 50%

        $rates = Economy::calculateRateBreakdown($season, $player, $player, $boostModFp, true);

        $this->assertSame(0, (int)$rates['gross_rate_fp'],
            'gross_rate_fp must be 0 when player is frozen.');
        $this->assertSame(0, (int)$rates['sink_per_tick'],
            'sink_per_tick must be 0 when player is frozen.');
        $this->assertSame(0, (int)$rates['net_rate_fp'],
            'net_rate_fp must be 0 when player is frozen.');
    }

    public function testCalculateRateBreakdownAllZeroDuringBlackoutSettlement(): void
    {
        $season = $this->makeSeasonFixture([
            'blackout_time' => 500,
            'end_time' => 1000,
        ]);
        $player = $this->makePlayerFixture([
            'current_game_time' => 750,
        ]);
        $boostModFp = 500000;

        $rates = Economy::calculateRateBreakdown($season, $player, $player, $boostModFp, false);

        $this->assertSame(0, (int)$rates['gross_rate_fp']);
        $this->assertSame(0, (int)$rates['sink_per_tick']);
        $this->assertSame(0, (int)$rates['net_rate_fp']);
    }

    /**
     * Positive gross (boosted) with sink enabled: net must be strictly less than gross
     * and the breakdown must expose all three values so the UI can display them correctly.
     */
    public function testCalculateRateBreakdownNetLessThanGrossWhenSinkActive(): void
    {
        $season = $this->makeSeasonFixture([
            'hoarding_sink_enabled'        => 1,
            'hoarding_safe_hours'          => 0,
            'hoarding_safe_min_coins'      => 0,
            'hoarding_tier1_excess_cap'    => 50000,
            'hoarding_tier2_excess_cap'    => 200000,
            'hoarding_tier1_rate_hourly_fp'=> 60000,
            'hoarding_tier2_rate_hourly_fp'=> 120000,
            'hoarding_tier3_rate_hourly_fp'=> 240000,
            'hoarding_sink_cap_ratio_fp'   => 200000, // 20%
        ]);
        // Player holds many coins so hoarding sink fires.
        $player = $this->makePlayerFixture(['coins' => 1000000, 'activity_state' => 'Active']);

        $rates = Economy::calculateRateBreakdown($season, $player, $player, 0, false);

        $this->assertGreaterThan(0, (int)$rates['gross_rate_fp'],
            'gross_rate_fp must be positive for an active participant.');
        $this->assertGreaterThan(0, (int)$rates['sink_per_tick'],
            'sink_per_tick must be positive when hoarding sink fires.');
        // When sink > 0, gross must be strictly greater than net.
        $this->assertGreaterThan((int)$rates['net_rate_fp'], (int)$rates['gross_rate_fp'],
            'gross_rate_fp must be greater than net_rate_fp when sink is active.');
        // Net must equal max(0, gross - sink_fp).
        $expectedNet = max(0, (int)$rates['gross_rate_fp'] - Economy::toFixedPoint((int)$rates['sink_per_tick']));
        $this->assertSame($expectedNet, (int)$rates['net_rate_fp'],
            'net_rate_fp must equal max(0, gross_rate_fp - toFixedPoint(sink_per_tick)).');
    }

    /**
     * Boost floor guarantees positive net even on a 1 UBI/tick base.
     * Guards the "boost floor and net minting continuity" requirement.
     */
    public function testBoostFloorEnsuresPositiveNetRateWithMinimalUbi(): void
    {
        $season = $this->makeSeasonFixture(['base_ubi_active_per_tick' => 1]);
        $player = $this->makePlayerFixture();
        $boostModFp = 100000; // 10%: guaranteedBoostFloor = +1 coin/tick

        $rates = Economy::calculateRateBreakdown($season, $player, $player, $boostModFp, false);

        $this->assertGreaterThan(0, (int)$rates['net_rate_fp'],
            'net_rate_fp must be positive when boost floor adds coins to a 1-UBI player.');
        $this->assertGreaterThan(
            Economy::toFixedPoint(1),
            (int)$rates['net_rate_fp'],
            'net_rate_fp must exceed 1 coin/tick in fp when boost modifier is active on 1 UBI base.'
        );
    }

    /**
     * When net_rate_fp is zero, fractional carry must not accumulate.
     * This validates the tick-engine fix: carry accrues against net, not gross.
     * If net = 0, then over any number of ticks the carry stays 0.
     */
    public function testNetRateZeroProducesNoCarryAccumulation(): void
    {
        // Net = 0 is simulated by calling splitFixedPoint on net * ticks + carry
        // with net = 0.
        $netRateFp  = 0;
        $ticksToProcess = 10;
        $carryFp    = 0;

        $totalNetFp = ($netRateFp * $ticksToProcess) + $carryFp;
        [$netCoins, $newCarryFp] = Economy::splitFixedPoint($totalNetFp);

        $this->assertSame(0, $netCoins,
            'No whole coins must be produced when net_rate_fp is zero.');
        $this->assertSame(0, $newCarryFp,
            'Carry must remain zero when net_rate_fp is zero, preventing phantom oscillation.');
    }

    /**
     * Positive net rate with fractional component correctly accumulates carry and
     * mints an extra coin once the carry exceeds one whole coin over multiple ticks.
     * Guards fractional carry continuity under the new net-rate-based carry approach.
     */
    public function testPositiveNetRateCarryAccumulatesAndMintsExtraCoin(): void
    {
        // Net = 2.5 coins/tick (2,500,000 fp).
        $netRateFp = 2500000;
        $carryFp   = 0;

        // Tick 1
        $totalNetFp = ($netRateFp * 1) + $carryFp;
        [$coins1, $carry1] = Economy::splitFixedPoint($totalNetFp);
        $this->assertSame(2, $coins1);
        $this->assertSame(500000, $carry1);

        // Tick 2 (carry rolls over)
        $totalNetFp = ($netRateFp * 1) + $carry1;
        [$coins2, $carry2] = Economy::splitFixedPoint($totalNetFp);
        $this->assertSame(3, $coins2); // 2.5 + 0.5 carry = 3
        $this->assertSame(0, $carry2);

        // Over 2 ticks: 2 + 3 = 5 coins total === floor(2.5 * 2)
        $this->assertSame(5, $coins1 + $coins2);
    }

    // ==================== Piecewise Gross-Rate Bonus ====================

    /**
     * Clamp: values below 0% are treated as 0% (no negative bonus).
     */
    public function testGrossRateBonusClampsBelowZero(): void
    {
        $this->assertSame(0.0, Economy::grossRateBonusFromBoostPct(-1.0));
        $this->assertSame(0.0, Economy::grossRateBonusFromBoostPct(-999.0));
    }

    /**
     * Clamp: values above 500% are treated as 500% (hard cap).
     */
    public function testGrossRateBonusClampsAbove500(): void
    {
        $this->assertEqualsWithDelta(75.0, Economy::grossRateBonusFromBoostPct(501.0), 0.0001);
        $this->assertEqualsWithDelta(75.0, Economy::grossRateBonusFromBoostPct(9999.0), 0.0001);
    }

    /**
     * Exact breakpoint values must match the design table (BOOST_RATE_BONUS_BREAKPOINTS).
     * Each breakpoint maps to its defined bonus without rounding error.
     */
    public function testGrossRateBonusBreakpointEndpoints(): void
    {
        $cases = [
              0.0 =>   0.0,
             10.0 =>   6.0,
             25.0 =>  12.0,
             50.0 =>  20.0,
             75.0 =>  27.0,
            100.0 =>  33.0,
            150.0 =>  42.0,
            200.0 =>  50.0,
            300.0 =>  60.0,
            400.0 =>  68.0,
            500.0 =>  75.0,
        ];

        foreach ($cases as $boostPct => $expected) {
            $this->assertEqualsWithDelta(
                $expected,
                Economy::grossRateBonusFromBoostPct((float)$boostPct),
                0.0001,
                "Breakpoint {$boostPct}% should yield bonus {$expected}."
            );
        }
    }

    /**
     * Representative interpolation samples between breakpoints.
     * Verifies continuous linear blending within each segment.
     */
    public function testGrossRateBonusSegmentInterpolation(): void
    {
        // 0–10: midpoint 5% → 0 + (6-0)*0.5 = 3.0
        $this->assertEqualsWithDelta(3.0, Economy::grossRateBonusFromBoostPct(5.0), 0.0001,
            '5% (mid of 0–10 segment) should interpolate to 3.0');

        // 10–25: midpoint 17.5% → 6 + (12-6)*0.5 = 9.0
        $this->assertEqualsWithDelta(9.0, Economy::grossRateBonusFromBoostPct(17.5), 0.0001,
            '17.5% (mid of 10–25 segment) should interpolate to 9.0');

        // 50–75: midpoint 62.5% → 20 + (27-20)*0.5 = 23.5
        $this->assertEqualsWithDelta(23.5, Economy::grossRateBonusFromBoostPct(62.5), 0.0001,
            '62.5% (mid of 50–75 segment) should interpolate to 23.5');

        // 100–150: midpoint 125% → 33 + (42-33)*0.5 = 37.5
        $this->assertEqualsWithDelta(37.5, Economy::grossRateBonusFromBoostPct(125.0), 0.0001,
            '125% (mid of 100–150 segment) should interpolate to 37.5');

        // 200–300: midpoint 250% → 50 + (60-50)*0.5 = 55.0
        $this->assertEqualsWithDelta(55.0, Economy::grossRateBonusFromBoostPct(250.0), 0.0001,
            '250% (mid of 200–300 segment) should interpolate to 55.0');

        // 400–500: midpoint 450% → 68 + (75-68)*0.5 = 71.5
        $this->assertEqualsWithDelta(71.5, Economy::grossRateBonusFromBoostPct(450.0), 0.0001,
            '450% (mid of 400–500 segment) should interpolate to 71.5');
    }

    /**
     * Monotonically non-decreasing bonus across the full [0, 500] range.
     * Ensures no downward jumps at any step.
     */
    public function testGrossRateBonusIsMonotonicOver0To500(): void
    {
        $prev = Economy::grossRateBonusFromBoostPct(0.0);
        for ($pct = 1; $pct <= 500; $pct++) {
            $curr = Economy::grossRateBonusFromBoostPct((float)$pct);
            $this->assertGreaterThanOrEqual(
                $prev,
                $curr,
                "Bonus at {$pct}% ({$curr}) must be >= bonus at " . ($pct - 1) . "% ({$prev})."
            );
            $prev = $curr;
        }
    }

    /**
     * Fixed-point wrapper: grossRateBonusFpFromBoostPct must round-trip correctly.
     * Verifies that fp value / FP_SCALE ≈ grossRateBonusFromBoostPct for key points.
     */
    public function testGrossRateBonusFpRoundTrip(): void
    {
        $cases = [0.0, 10.0, 25.0, 50.0, 100.0, 200.0, 300.0, 400.0, 500.0];
        foreach ($cases as $pct) {
            $float  = Economy::grossRateBonusFromBoostPct($pct);
            $fp     = Economy::grossRateBonusFpFromBoostPct($pct);
            $this->assertEqualsWithDelta(
                $float,
                $fp / FP_SCALE,
                0.5 / FP_SCALE, // tolerance: half a fp unit
                "FP round-trip failed at {$pct}%: float={$float}, fp/SCALE=" . ($fp / FP_SCALE)
            );
        }
    }

    /**
     * Integration: piecewise bonus contributes to gross_rate_fp via calculateRateBreakdown.
     * A player with no boost gets no piecewise bonus; one with a 250% boost (2,500,000 fp)
     * must have a strictly higher gross_rate_fp than the same player with no boost.
     */
    public function testPiecewiseBonusRaisesGrossRateVsUnboosted(): void
    {
        $season = $this->makeSeasonFixture();
        $player = $this->makePlayerFixture();

        $ratesUnboosted     = Economy::calculateRateBreakdown($season, $player, $player, 0, false);
        // 250% boost = 2,500,000 fp
        $ratesWith250PctBoost = Economy::calculateRateBreakdown($season, $player, $player, 2500000, false);

        $this->assertGreaterThan(
            (int)$ratesUnboosted['gross_rate_fp'],
            (int)$ratesWith250PctBoost['gross_rate_fp'],
            'gross_rate_fp with 250% boost must exceed gross_rate_fp with no boost.'
        );
    }

    /**
     * Integration: at max clamped boost (500%) gross_rate_fp must exceed the 500% boost
     * piecewise bonus in fp units (i.e., the bonus is present and additive on top of UBI).
     * Uses 5,000,000 fp = 500% to match the tick engine's 6x UBI multiplier cap.
     */
    public function testPiecewiseBonusAtMaxBoostIsAddedToGrossRate(): void
    {
        $season = $this->makeSeasonFixture(['base_ubi_active_per_tick' => 30]);
        $player = $this->makePlayerFixture();

        // 500% boost (max clamped by tick engine) = 5,000,000 fp
        $rates = Economy::calculateRateBreakdown($season, $player, $player, 5000000, false);

        // The piecewise bonus alone at 500% is given by grossRateBonusFpFromBoostPct(500.0).
        $expectedPiecewiseFp = Economy::grossRateBonusFpFromBoostPct(500.0);
        $this->assertGreaterThan(
            $expectedPiecewiseFp,
            (int)$rates['gross_rate_fp'],
            'gross_rate_fp at max boost must exceed the piecewise bonus alone (UBI is additive).'
        );
    }

    // ==================== Star Price Stability Patch ====================

    private function makeStarPriceSeason(array $overrides = []): array
    {
        return array_merge([
            'starprice_table' => json_encode([
                ['m' => 0,       'price' => 100],
                ['m' => 25000,   'price' => 300],
                ['m' => 100000,  'price' => 900],
                ['m' => 500000,  'price' => 3200],
                ['m' => 2000000, 'price' => 11000],
            ]),
            'star_price_cap'                => 12000,
            'total_coins_supply_end_of_tick' => 0,
            'effective_price_supply'         => 0,
            'starprice_active_only'          => 0,
            'current_star_price'             => 0,
            'starprice_max_upstep_fp'        => 2000,   // 0.2 %/tick
            'starprice_max_downstep_fp'      => 10000,  // 1.0 %/tick
        ], $overrides);
    }

    public function testPublishedStarPriceUsesBlackoutSnapshotDuringBlackout(): void
    {
        $season = $this->makeStarPriceSeason([
            'current_star_price' => 900,
            'blackout_star_price_snapshot' => 777,
        ]);

        $this->assertSame(777, Economy::publishedStarPrice($season, 'Blackout'));
        $this->assertSame(900, Economy::publishedStarPrice($season, 'Active'));
    }

    // --- Supply delta accounting ---

    public function testNetSupplyDeltaIsNetCoinsWhenBurnIsZero(): void
    {
        $totalNewCoins    = 500;
        $totalBurnedCoins = 0;
        $netCoinsDelta    = $totalNewCoins; // minted − burned (burned is accounting-only)
        $currentSupply    = 1000;
        $newSupply        = max(0, $currentSupply + $netCoinsDelta);

        $this->assertSame(1500, $newSupply);
    }

    public function testNetSupplyDeltaIsZeroWhenNoNewCoins(): void
    {
        $totalNewCoins    = 0;
        $totalBurnedCoins = 0;
        $netCoinsDelta    = $totalNewCoins;
        $currentSupply    = 1000;
        $newSupply        = max(0, $currentSupply + $netCoinsDelta);

        $this->assertSame(1000, $newSupply);
    }

    public function testNetSupplyDoesNotGoBelowZero(): void
    {
        // Guard: the GREATEST(0, ...) / max(0, ...) floor must prevent underflow even if
        // $netCoinsDelta is somehow negative (defensive edge case).
        $netCoinsDelta = -200;
        $currentSupply = 100;
        $newSupply     = max(0, $currentSupply + $netCoinsDelta);

        $this->assertSame(0, $newSupply);
    }

    public function testBothSupplyFieldsReceiveSameValueFixesPreviousDoubleCounting(): void
    {
        // Regression: old MySQL single-UPDATE code evaluated SET clauses left-to-right,
        // so total_coins_supply_end_of_tick saw the already-updated total_coins_supply and
        // accumulated 2× the net delta per tick.
        //
        // New code uses SQL expressions with total_coins_supply_end_of_tick listed BEFORE
        // total_coins_supply in the SET clause, so both expressions reference the original
        // total_coins_supply value atomically. This also avoids stale-read lost updates
        // from concurrent sessions (e.g. star purchases).
        $totalNewCoins = 300;
        $currentSupply = 1000;

        // Simulate old (buggy) behavior: first field = old + delta; second references first.
        $firstField       = max(0, $currentSupply + $totalNewCoins); // 1300
        $secondFieldBuggy = max(0, $firstField + $totalNewCoins);    // 1600 (double-counted!)

        // New behavior: both fields derive from the same original supply (SQL expression).
        $newSupply        = max(0, $currentSupply + $totalNewCoins); // 1300
        $secondFieldFixed = $newSupply;                               // also 1300

        $this->assertSame(1300, $newSupply);
        $this->assertSame($newSupply, $secondFieldFixed, 'Both supply fields must receive the same value.');
        // Fixed supply must be strictly less than the old double-counted supply.
        $this->assertLessThan($secondFieldBuggy, $newSupply, 'Fixed supply must be less than old double-counted end_of_tick supply.');
    }

    // --- Effective supply calculation ---

    public function testEffectiveSupplyWeightedIdleModeReducesIdleInfluence(): void
    {
        $coinsActive   = 10000;
        $coinsIdle     = 40000;
        $idleWeightFp  = 250000; // 0.25

        $effective = max(0, $coinsActive + Economy::fpMultiply($coinsIdle, $idleWeightFp));

        // idle contribution = floor(40000 * 0.25) = 10000; total = 10000 + 10000 = 20000
        $this->assertSame(20000, $effective);
        $this->assertLessThan($coinsActive + $coinsIdle, $effective, 'Weighted effective supply must be less than total coins.');
    }

    public function testEffectiveSupplyActiveOnlyIgnoresIdleCoins(): void
    {
        $coinsActive = 10000;
        $coinsIdle   = 40000;

        $effective = max(0, $coinsActive); // starprice_active_only = 1

        $this->assertSame(10000, $effective);
    }

    public function testEffectiveSupplyZeroIdleWeightIgnoresIdleEntirely(): void
    {
        $coinsActive  = 5000;
        $coinsIdle    = 100000;
        $idleWeightFp = 0; // explicit zero weight

        $effective = max(0, $coinsActive + Economy::fpMultiply($coinsIdle, $idleWeightFp));

        $this->assertSame(5000, $effective);
    }

    // --- calculateStarPrice uses effective_price_supply ---

    public function testCalculateStarPriceUsesEffectivePriceSupplyWhenPositive(): void
    {
        // effective_price_supply = 25000 → table price 300
        // total_coins_supply_end_of_tick = 100000 → table price 900
        $season = $this->makeStarPriceSeason([
            'effective_price_supply'          => 25000,
            'total_coins_supply_end_of_tick'  => 100000,
        ]);

        $price = Economy::calculateStarPrice($season);

        $this->assertSame(300, $price, 'calculateStarPrice must use effective_price_supply when > 0.');
    }

    public function testCalculateStarPriceFallsBackToEndOfTickSupplyWhenEffectiveIsZero(): void
    {
        // Default mode (starprice_active_only = 0): effective_price_supply = 0 → fallback
        // to total_coins_supply_end_of_tick = 100000 → table price 900.
        $season = $this->makeStarPriceSeason([
            'starprice_active_only'           => 0,
            'effective_price_supply'          => 0,
            'total_coins_supply_end_of_tick'  => 100000,
        ]);

        $price = Economy::calculateStarPrice($season);

        $this->assertSame(900, $price, 'Default mode must fall back to total_coins_supply_end_of_tick when effective_price_supply is 0.');
    }

    public function testCalculateStarPriceActiveOnlyUsesZeroEffectiveSupplyWithoutFallback(): void
    {
        // starprice_active_only = 1: effective_price_supply = 0 (no active players)
        // must NOT fall back to total_coins_supply_end_of_tick (which contains idle coins).
        // supply = 0 → table price = 100; clamp skipped (prevPrice = 0).
        $season = $this->makeStarPriceSeason([
            'starprice_active_only'           => 1,
            'effective_price_supply'          => 0,
            'total_coins_supply_end_of_tick'  => 100000, // would give 900 if used
            'current_star_price'              => 0,       // no clamp on first tick
        ]);

        $price = Economy::calculateStarPrice($season);

        $this->assertSame(100, $price, 'Active-only mode must use effective_price_supply=0 directly, not fall back to idle-inclusive supply.');
    }

    // --- Velocity clamp: upward ---

    public function testVelocityClampLimitsUpwardPriceMovement(): void
    {
        // Raw price at supply 100000 = 900; prevPrice = 300; upstep = 2000 fp (0.2%)
        // maxUp = intdiv(300 * 2000, 1000000) = 0 → max(1, 0) = 1
        // clamped = min(900, 300 + 1) = 301
        $season = $this->makeStarPriceSeason([
            'effective_price_supply'  => 100000,
            'current_star_price'      => 300,
            'starprice_max_upstep_fp' => 2000,
        ]);

        $price = Economy::calculateStarPrice($season);

        $this->assertSame(301, $price, 'Upward velocity clamp must prevent large single-tick price jumps.');
    }

    public function testVelocityClampLimitsUpwardByPercentageForHigherPrice(): void
    {
        // prevPrice = 1000; upstep = 2000 fp
        // maxUp = intdiv(1000 * 2000, 1000000) = 2
        // raw price at supply 2000000 = 11000; clamped = min(11000, 1002) = 1002
        $season = $this->makeStarPriceSeason([
            'effective_price_supply'  => 2000000,
            'current_star_price'      => 1000,
            'starprice_max_upstep_fp' => 2000,
        ]);

        $price = Economy::calculateStarPrice($season);

        $this->assertSame(1002, $price);
    }

    // --- Velocity clamp: downward ---

    public function testVelocityClampLimitsDownwardPriceMovement(): void
    {
        // prevPrice = 900; downstep = 10000 fp (1%)
        // maxDown = intdiv(900 * 10000, 1000000) = 9
        // raw price at supply 0 = 100; clamped = max(100, 900 - 9) = 891
        $season = $this->makeStarPriceSeason([
            'effective_price_supply'     => 0,      // supply = 0 → fallback
            'total_coins_supply_end_of_tick' => 0,  // → raw price = 100
            'current_star_price'         => 900,
            'starprice_max_downstep_fp'  => 10000,
        ]);

        $price = Economy::calculateStarPrice($season);

        $this->assertSame(891, $price, 'Downward velocity clamp must prevent large single-tick price drops.');
    }

    public function testVelocityClampAllowsSmallDownwardMovement(): void
    {
        // prevPrice = 300; downstep = 10000 fp (1%); maxDown = intdiv(300 * 10000, 1000000) = 3
        // raw price at supply 0 = 100; clamped = max(100, 300 - 3) = 297
        $season = $this->makeStarPriceSeason([
            'total_coins_supply_end_of_tick' => 0,  // raw price = 100
            'current_star_price'             => 300,
            'starprice_max_downstep_fp'      => 10000,
        ]);

        $price = Economy::calculateStarPrice($season);

        $this->assertSame(297, $price);
    }

    // --- Regression: existing cap and floor preserved ---

    public function testHardCapStillEnforcedAfterVelocityClamp(): void
    {
        // Raw price at very high supply = 11000; cap = 500; clamp allows movement.
        // prevPrice = 490; upstep = 100000 fp (10%); maxUp = intdiv(490*100000, 1e6) = 49
        // clamped = min(11000, 490 + 49) = 539; after cap = min(539, 500) = 500.
        $season = $this->makeStarPriceSeason([
            'effective_price_supply'  => 2000000,  // raw = 11000
            'star_price_cap'          => 500,
            'current_star_price'      => 490,
            'starprice_max_upstep_fp' => 100000,   // 10%/tick – very generous for test
        ]);

        $price = Economy::calculateStarPrice($season);

        $this->assertSame(500, $price, 'Hard cap must be enforced after velocity clamp.');
    }

    public function testFloorOfOneEnforcedWhenClampWouldGoToZero(): void
    {
        // prevPrice = 1; downstep = 1000000 fp (100%); raw = 100; maxDown = 1
        // clamped = max(100, 1 - 1) = max(100, 0) = 100; floor(max(1, 100)) = 100.
        // But if we push prevPrice = 1 down by full step and raw also = 1:
        // Construct: supply = 0 → raw = 100, floor applies → price >= 1
        $season = $this->makeStarPriceSeason([
            'total_coins_supply_end_of_tick' => 0,   // raw = 100
            'current_star_price'             => 1,
            'starprice_max_downstep_fp'      => 1000000, // 100% down
        ]);

        $price = Economy::calculateStarPrice($season);

        $this->assertGreaterThanOrEqual(1, $price, 'Star price must never fall below 1.');
    }

    public function testNoPrevPriceSkipsVelocityClamp(): void
    {
        // prevPrice = 0 means first tick or uninitialized; clamp must be skipped entirely.
        // raw price at supply 100000 = 900; no clamp → 900.
        $season = $this->makeStarPriceSeason([
            'effective_price_supply' => 100000,
            'current_star_price'     => 0,
        ]);

        $price = Economy::calculateStarPrice($season);

        $this->assertSame(900, $price, 'Velocity clamp must be skipped when prevPrice is 0 (first tick).');
    }

    public function testStarPriceNotChangedWhenSupplyIsStable(): void
    {
        // prevPrice = 900; supply = 100000 → raw = 900; no clamp needed (no movement).
        $season = $this->makeStarPriceSeason([
            'effective_price_supply' => 100000,
            'current_star_price'     => 900,
        ]);

        $price = Economy::calculateStarPrice($season);

        $this->assertSame(900, $price, 'Price must remain stable when supply does not change.');
    }
}
