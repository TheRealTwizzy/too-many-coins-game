<?php
/**
 * Too Many Coins - Economy Engine
 * Handles UBI calculation, star pricing, and general economy math
 * Uses int64 arithmetic with floor-after-each-step as per canon
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

class Economy {

    public static function getSigilTierCap($tier) {
        $tier = (int)$tier;
        $caps = (array)SIGIL_INVENTORY_TIER_CAPS;
        return max(0, (int)($caps[$tier] ?? 0));
    }

    public static function getSigilTotalCap() {
        return max(0, (int)SIGIL_INVENTORY_TOTAL_CAP);
    }

    public static function getSigilTotal($participation) {
        if (!$participation || !is_array($participation)) {
            return 0;
        }
        $total = 0;
        for ($tier = 1; $tier <= SIGIL_MAX_TIER; $tier++) {
            $total += max(0, (int)($participation['sigils_t' . $tier] ?? 0));
        }
        return (int)$total;
    }

    public static function canReceiveSigilTier($participation, $tier, $amount = 1, $consumedAmount = 0) {
        if (!$participation || !is_array($participation)) {
            return false;
        }

        $tier = (int)$tier;
        $amount = max(0, (int)$amount);
        $consumedAmount = max(0, (int)$consumedAmount);
        if ($tier < 1 || $tier > SIGIL_MAX_TIER || $amount <= 0) {
            return false;
        }

        $col = 'sigils_t' . $tier;
        $currentTier = max(0, (int)($participation[$col] ?? 0));
        $currentTotal = self::getSigilTotal($participation);
        $tierCap = self::getSigilTierCap($tier);
        $totalCap = self::getSigilTotalCap();

        if ($tierCap > 0 && ($currentTier + $amount) > $tierCap) {
            return false;
        }
        $netTotalAfter = $currentTotal + $amount - $consumedAmount;
        if ($totalCap > 0 && $netTotalAfter > $totalCap) {
            return false;
        }
        return true;
    }

    /**
     * Estimate sigil power from tiered sigil inventory.
     */
    public static function calculateSigilPower($participation) {
        if (!$participation || !is_array($participation)) return 0;

        $weights = [1 => 1, 2 => 2, 3 => 3, 4 => 5, 5 => 8, 6 => 13];
        $power = 0;
        foreach ($weights as $tier => $weight) {
            $col = 'sigils_t' . $tier;
            $count = (int)($participation[$col] ?? 0);
            if ($count > 0) {
                $power += $count * $weight;
            }
        }

        return max(0, (int)$power);
    }

    /**
     * Blend baseline and max-power tier odds using a linear ramp.
     */
    public static function adjustedSigilTierOdds($sigilPower) {
        $base = SIGIL_TIER_ODDS;
        $target = SIGIL_TIER_ODDS_MAX_POWER;
        $fullShift = max(1, (int)SIGIL_POWER_FULL_SHIFT);
        $power = max(0, (int)$sigilPower);
        $ratioFp = min(FP_SCALE, intdiv($power * FP_SCALE, $fullShift));

        $tiers = [];
        $sum = 0;
        foreach ($base as $tier => $odds) {
            $from = (int)$odds;
            $to = (int)($target[$tier] ?? $from);
            $blended = intdiv(($from * (FP_SCALE - $ratioFp)) + ($to * $ratioFp), FP_SCALE);
            $tiers[(int)$tier] = max(0, (int)$blended);
            $sum += $tiers[(int)$tier];
        }

        if ($sum <= 0) {
            return $base;
        }

        // Normalize to exactly 1,000,000 without reordering odds.
        $delta = 1000000 - $sum;
        $tiers[1] = max(0, (int)$tiers[1] + $delta);
        return $tiers;
    }

    /**
     * Scale base sigil drop Bernoulli denominator by sigil power.
     * Higher denominator means lower base drop chance.
     */
    public static function sigilDropRateForPower($sigilPower) {
        $baseRate = max(1, (int)SIGIL_DROP_RATE);
        $maxRate = max($baseRate, (int)(defined('SIGIL_DROP_RATE_MAX_POWER') ? SIGIL_DROP_RATE_MAX_POWER : $baseRate));
        $fullShift = max(1, (int)SIGIL_POWER_FULL_SHIFT);
        $power = max(0, (int)$sigilPower);
        $ratioFp = min(FP_SCALE, intdiv($power * FP_SCALE, $fullShift));

        return max(1, intdiv(($baseRate * (FP_SCALE - $ratioFp)) + ($maxRate * $ratioFp), FP_SCALE));
    }
    
    /**
     * Fixed-point multiply with floor: floor(base * mult_fp / 1_000_000)
     */
    public static function fpMultiply($base, $multFp) {
        // PHP handles big integers natively (GMP-like for 64-bit)
        return intdiv($base * $multFp, FP_SCALE);
    }

    /**
     * Convert whole-coin value into fixed-point units.
     */
    public static function toFixedPoint($coins) {
        return max(0, (int)$coins) * FP_SCALE;
    }

    /**
     * Apply a boost modifier to a fixed-point amount.
     */
    public static function applyBoostModifierFp($amountFp, $boostModFp) {
        $baseFp = max(0, (int)$amountFp);
        $modFp = max(0, (int)$boostModFp);
        if ($modFp <= 0) return $baseFp;

        return intdiv($baseFp * (FP_SCALE + $modFp), FP_SCALE);
    }

    /**
     * Canonical season score used for rank and leaderboard display.
     */
    public static function effectiveSeasonalStars($participation) {
        if (!$participation || !is_array($participation)) {
            return 0;
        }

        if (!empty($participation['lock_in_effect_tick'])) {
            return max(0, (int)($participation['lock_in_snapshot_seasonal_stars'] ?? 0));
        }

        if (!empty($participation['end_membership']) && array_key_exists('final_seasonal_stars', $participation)) {
            return max(0, (int)($participation['final_seasonal_stars'] ?? 0));
        }

        return max(0, (int)($participation['seasonal_stars'] ?? 0));
    }

    /**
     * Canonical seasonal-star base used to settle a player into Global Stars.
     *
     * Ranking score and settlement base intentionally diverge for natural-expiry
     * rows once participation/placement bonuses are added. Consumers should use
     * this helper instead of inferring settlement from leaderboard score fields.
     */
    public static function settlementPayoutSeasonalStars($participation): int {
        if (!$participation || !is_array($participation)) {
            return 0;
        }

        if (!empty($participation['lock_in_effect_tick'])) {
            return max(0, (int)($participation['lock_in_snapshot_seasonal_stars'] ?? 0));
        }

        if (!empty($participation['end_membership']) && array_key_exists('final_seasonal_stars', $participation)) {
            return max(0, (int)($participation['final_seasonal_stars'] ?? 0));
        }

        return max(0, (int)($participation['seasonal_stars'] ?? 0));
    }

    public static function calculateParticipationBonus(int $activeTicks): int {
        return min(intdiv(max(0, $activeTicks), PARTICIPATION_BONUS_DIVISOR), PARTICIPATION_BONUS_CAP);
    }

    public static function calculatePlacementBonus(int $placementRank, bool $awardBadgesAndPlacement = true): int {
        if (!$awardBadgesAndPlacement) {
            return 0;
        }

        return (int)(PLACEMENT_BONUS[$placementRank] ?? 0);
    }

    /**
     * Compute natural-expiry settlement using final seasonal stars plus end-of-season bonuses.
     */
    public static function computeNaturalExpiryPayout(
        int $seasonalStars,
        int $activeTicks,
        int $placementRank,
        int $carryFp = 0,
        bool $awardBadgesAndPlacement = true
    ): array {
        $payoutSeasonalStars = max(0, $seasonalStars);
        $participationBonus = self::calculateParticipationBonus($activeTicks);
        $placementBonus = self::calculatePlacementBonus($placementRank, $awardBadgesAndPlacement);
        $totalSourceStars = $payoutSeasonalStars + $participationBonus + $placementBonus;
        $grant = self::applyGlobalStarsGrantWithCarry($totalSourceStars, $carryFp);

        return [
            'payout_seasonal_stars' => $payoutSeasonalStars,
            'participation_bonus' => $participationBonus,
            'placement_bonus' => $placementBonus,
            'payout_bonus_stars' => $participationBonus + $placementBonus,
            'total_source_stars' => $totalSourceStars,
            'source_stars' => $grant['source_stars'],
            'grant_fp' => $grant['grant_fp'],
            'carry_in_fp' => $grant['carry_in_fp'],
            'total_fp' => $grant['total_fp'],
            'global_stars_gained' => $grant['global_stars_gained'],
            'global_stars_fractional_fp' => $grant['global_stars_fractional_fp'],
            'global_stars_progress_percent' => $grant['global_stars_progress_percent'],
        ];
    }

    public static function settlementPayoutSourceStars($participation): int {
        if (!$participation || !is_array($participation)) {
            return 0;
        }

        $payoutSeasonalStars = self::settlementPayoutSeasonalStars($participation);
        $participationBonus = max(0, (int)($participation['participation_bonus'] ?? 0));
        $placementBonus = max(0, (int)($participation['placement_bonus'] ?? 0));

        return $payoutSeasonalStars + $participationBonus + $placementBonus;
    }

    /**
     * Published star price, using the blackout settlement snapshot when present.
     */
    public static function publishedStarPrice($season, $status = null) {
        if (!$season || !is_array($season)) {
            return 0;
        }

        $currentPrice = max(0, (int)($season['current_star_price'] ?? 0));
        $snapshotPrice = max(0, (int)($season['blackout_star_price_snapshot'] ?? 0));
        if ((string)$status === 'Blackout' && $snapshotPrice > 0) {
            return $snapshotPrice;
        }

        return $currentPrice;
    }

    /**
     * Blackout is settlement-only: no new accrual or drops should occur.
     */
    public static function isBlackoutSettlementPhase($season, $gameTime = null) {
        if (!$season || !is_array($season)) {
            return false;
        }

        $status = (string)($season['computed_status'] ?? $season['status'] ?? '');
        if ($status === 'Blackout') {
            return true;
        }

        if ($gameTime === null) {
            return false;
        }

        $blackoutTime = (int)($season['blackout_time'] ?? 0);
        $endTime = (int)($season['end_time'] ?? PHP_INT_MAX);
        if ($blackoutTime <= 0) {
            return false;
        }

        $gameTime = (int)$gameTime;
        return $gameTime >= $blackoutTime && $gameTime < $endTime;
    }

    /**
     * Guaranteed whole-coin floor from effective boost modifier.
     * Example default policy: +1 coin/tick per 10% boost (100,000 fp).
     */
    public static function guaranteedBoostFloorCoins($boostModFp, $capCoins = null) {
        $modFp = max(0, (int)$boostModFp);
        $stepPercent = max(1, (int)BOOST_GUARANTEED_FLOOR_STEP_PERCENT);
        $stepCoins = max(1, (int)BOOST_GUARANTEED_FLOOR_STEP_COINS);
        $fpPerStep = intdiv($stepPercent * FP_SCALE, 100);
        if ($fpPerStep <= 0) return 0;

        $coins = intdiv($modFp, $fpPerStep) * $stepCoins;
        $effectiveCap = ($capCoins === null) ? (int)BOOST_GUARANTEED_FLOOR_CAP_COINS : (int)$capCoins;
        if ($effectiveCap > 0) {
            $coins = min($coins, $effectiveCap);
        }

        return max(0, $coins);
    }

    /**
     * Fixed-point wrapper for guaranteedBoostFloorCoins().
     */
    public static function guaranteedBoostFloorFp($boostModFp, $capCoins = null) {
        $cadenceDivisor = self::ticksPerRealMinute();
        return intdiv(self::toFixedPoint(self::guaranteedBoostFloorCoins($boostModFp, $capCoins)), $cadenceDivisor);
    }

    /**
     * Breakpoint curve for diminishing-returns gross-rate bonus (coins/tick) vs boost %.
     *
     * Each entry is [boostPct, rateBonus]. The curve is piecewise-linear between
     * adjacent points, giving strong early gains that taper off at high boost levels.
     * To rebalance, edit only these values — the interpolation logic is unchanged.
     *
     * boostPct must be monotonically increasing; rateBonus must be non-decreasing.
     */
    private const BOOST_RATE_BONUS_BREAKPOINTS = [
        [   0.0,   0.0],
        [  10.0,   6.0],
        [  25.0,  12.0],
        [  50.0,  20.0],
        [  75.0,  27.0],
        [ 100.0,  33.0],
        [ 150.0,  42.0],
        [ 200.0,  50.0],
        [ 300.0,  60.0],
        [ 400.0,  68.0],
        [ 500.0,  75.0],
    ];

    /**
     * Piecewise linear interpolation of gross-rate bonus (coins/tick) from effective boost %.
     *
     * Uses BOOST_RATE_BONUS_BREAKPOINTS for a configurable diminishing-returns curve.
     * boostPct below the minimum breakpoint returns the minimum bonus (clamp low).
     * boostPct above the maximum breakpoint returns the maximum bonus (clamp high).
     * Values between breakpoints are linearly interpolated.
     */
    public static function grossRateBonusFromBoostPct(float $boostPct): float
    {
        $pts = self::BOOST_RATE_BONUS_BREAKPOINTS;
        $x   = (float)$boostPct;

        // Clamp below minimum
        if ($x <= $pts[0][0]) {
            return (float)$pts[0][1];
        }

        // Clamp above maximum
        $last = count($pts) - 1;
        if ($x >= $pts[$last][0]) {
            return (float)$pts[$last][1];
        }

        // Piecewise linear interpolation between bracketing breakpoints
        for ($i = 0; $i < $last; $i++) {
            [$x1, $y1] = $pts[$i];
            [$x2, $y2] = $pts[$i + 1];
            if ($x <= $x2) {
                $width = $x2 - $x1;
                if ($width <= 0.0) {
                    return (float)$y2;
                }
                $t = ($x - $x1) / $width;
                return $y1 + $t * ($y2 - $y1);
            }
        }

        return (float)$pts[$last][1]; // unreachable
    }

    /**
     * Fixed-point wrapper: converts effective boost % to a gross-rate bonus in fp units.
     * Uses FP_SCALE (1,000,000) consistent with the rest of the economy pipeline.
     * Floor is applied after scaling, matching the "floor-after-each-step" convention.
     */
    public static function grossRateBonusFpFromBoostPct(float $boostPct): int
    {
        $cadenceDivisor = self::ticksPerRealMinute();
        $legacyRateFp = (int)floor(self::grossRateBonusFromBoostPct($boostPct) * FP_SCALE);
        return intdiv($legacyRateFp, $cadenceDivisor);
    }

    /**
     * Split fixed-point amount into whole coins and residual fractional fp.
     */
    public static function splitFixedPoint($amountFp) {
        $value = max(0, (int)$amountFp);
        return [
            intdiv($value, FP_SCALE),
            $value % FP_SCALE,
        ];
    }

    /**
     * Convert whole seasonal-star input into fixed-point global-star value.
     */
    public static function convertWholeStarsToGlobalStarsFp(int $wholeStars, int $numerator = 100, int $denominator = 100): int {
        $stars = max(0, (int)$wholeStars);
        $safeNumerator = max(0, (int)$numerator);
        $safeDenominator = max(1, (int)$denominator);
        if ($stars <= 0 || $safeNumerator <= 0) {
            return 0;
        }

        return intdiv($stars * FP_SCALE * $safeNumerator, $safeDenominator);
    }

    /**
     * Apply a global-star grant while preserving fractional carry between awards.
     *
     * @return array{
     *     source_stars: int,
     *     grant_fp: int,
     *     carry_in_fp: int,
     *     total_fp: int,
     *     global_stars_gained: int,
     *     global_stars_fractional_fp: int,
     *     global_stars_progress_percent: float
     * }
     */
    public static function applyGlobalStarsGrantWithCarry(int $sourceStars, int $carryFp = 0, int $numerator = 100, int $denominator = 100): array {
        $carryInFp = max(0, (int)$carryFp);
        $grantFp = self::convertWholeStarsToGlobalStarsFp($sourceStars, $numerator, $denominator);
        $totalFp = $carryInFp + $grantFp;
        [$wholeStars, $remainderFp] = self::splitFixedPoint($totalFp);

        return [
            'source_stars' => max(0, (int)$sourceStars),
            'grant_fp' => $grantFp,
            'carry_in_fp' => $carryInFp,
            'total_fp' => $totalFp,
            'global_stars_gained' => $wholeStars,
            'global_stars_fractional_fp' => $remainderFp,
            'global_stars_progress_percent' => round(($remainderFp / FP_SCALE) * 100, 1),
        ];
    }
    
    /**
     * Clamp value between min and max
     */
    public static function clamp($value, $min, $max) {
        return max($min, min($max, $value));
    }
    
    /**
     * Piecewise linear interpolation with floor
     */
    public static function piecewiseLinear($x, $table, $xKey, $yKey) {
        if (empty($table)) return 0;
        
        // Below first point
        if ($x <= $table[0][$xKey]) return $table[0][$yKey];
        
        // Above last point
        $last = count($table) - 1;
        if ($x >= $table[$last][$xKey]) return $table[$last][$yKey];
        
        // Find segment
        for ($i = 0; $i < $last; $i++) {
            $x0 = $table[$i][$xKey];
            $x1 = $table[$i + 1][$xKey];
            if ($x >= $x0 && $x < $x1) {
                $y0 = $table[$i][$yKey];
                $y1 = $table[$i + 1][$yKey];
                // Linear interpolation with floor
                if ($x1 == $x0) return $y0;
                return intdiv($y0 * ($x1 - $x) + $y1 * ($x - $x0), $x1 - $x0);
            }
        }
        
        return $table[$last][$yKey];
    }
    
    /**
     * Calculate inflation dampening factor
     */
    public static function inflationFactor($season, $totalCoinsSupply) {
        $table = json_decode($season['inflation_table'], true);
        $factorFp = self::piecewiseLinear($totalCoinsSupply, $table, 'x', 'factor_fp');
        return self::clamp($factorFp, (int)$season['hoarding_min_factor_fp'], FP_SCALE);
    }
    
    /**
     * Calculate hoarding suppression factor for a player
     */
    public static function hoardingFactor($season, $playerSpendWindowAvg) {
        $target = (int)$season['target_spend_rate_per_tick'];
        if ($target <= 0) return FP_SCALE;
        
        $rawFp = intdiv($playerSpendWindowAvg * FP_SCALE, $target);
        return self::clamp($rawFp, (int)$season['hoarding_min_factor_fp'], FP_SCALE);
    }

    /**
     * Game ticks that elapse in one real-world hour.
     */
    public static function ticksPerRealHour() {
        return max(1, (int)ceil((3600 * TIME_SCALE) / max(1, TICK_REAL_SECONDS)));
    }

    /**
     * Runtime ticks that elapse in one real minute.
     * Economy coefficients were authored against a 1-minute baseline cadence.
     */
    public static function ticksPerRealMinute() {
        return max(1, (int)ceil((60 * TIME_SCALE) / max(1, TICK_REAL_SECONDS)));
    }

    /**
     * Whether explicit hoarding sink is enabled for the season.
     */
    public static function hoardingSinkEnabled($season) {
        return ((int)($season['hoarding_sink_enabled'] ?? 0)) === 1;
    }

    /**
     * Whether a player's presence is stale enough to be treated as absent.
     *
     * In production, compares wall-clock time() against last_seen_at.
     * In simulation (GameTime::isSimulationClockActive()), uses the simulated
     * wall-clock derived from the current simulation tick so that fast-forwarded
     * game time still ages last_seen_at correctly.
     */
    public static function presenceIsStale($player, $staleAfterSeconds = null) {
        if (!is_array($player)) {
            return true;
        }

        if (empty($player['online_current'])) {
            return true;
        }

        $threshold = max(1, ($staleAfterSeconds === null)
            ? (int)TMC_PRESENCE_STALE_OFFLINE_SECONDS
            : (int)$staleAfterSeconds);

        $lastSeenAt = $player['last_seen_at'] ?? null;
        if (!$lastSeenAt) {
            return true;
        }

        $lastSeenTs = strtotime((string)$lastSeenAt);
        if ($lastSeenTs === false) {
            return true;
        }

        // In simulation mode, derive "now" from the simulation tick's real-time
        // equivalent so that presence ages correctly in fast-forwarded time.
        $now = (GameTime::isSimulationClockActive())
            ? GameTime::tickStartRealUnix(GameTime::now())
            : time();

        return ($now - $lastSeenTs) >= $threshold;
    }

    /**
     * Resolve the player's economic presence state used by UBI and liquidity math.
     */
    public static function resolveEconomicPresenceState($player, $season = null, $gameTime = null) {
        $explicitState = (string)($player['economic_presence_state'] ?? '');
        if (in_array($explicitState, ['Active', 'Idle', 'Offline'], true)) {
            return $explicitState;
        }

        $activityState = (string)($player['activity_state'] ?? 'Idle');
        if ($activityState === 'Active') {
            return 'Active';
        }

        if ($season !== null && $gameTime !== null) {
            $blackoutTime = (int)($season['blackout_time'] ?? 0);
            if ($blackoutTime > 0 && (int)$gameTime >= $blackoutTime) {
                return 'Idle';
            }

            $idleSinceTick = isset($player['idle_since_tick']) ? (int)$player['idle_since_tick'] : null;
            if ($idleSinceTick !== null) {
                $idleHeldTicks = max(0, (int)$gameTime - $idleSinceTick);
                if ($idleHeldTicks >= (int)FORCED_OFFLINE_IDLE_HOLD_TICKS && self::presenceIsStale($player)) {
                    return 'Offline';
                }
            }
        }

        return 'Idle';
    }
    
    /**
     * Calculate UBI for a player on a given tick
     */
    public static function calculateUBI($season, $player, $participation, $isLockInTick = false) {
        $ubiFp = self::calculateUBIFp($season, $player, $participation, $isLockInTick);
        return intdiv($ubiFp, FP_SCALE);
    }

    /**
     * Calculate UBI in fixed-point for cadence-safe per-tick valuation.
     */
    public static function calculateUBIFp($season, $player, $participation, $isLockInTick = false) {
        // Lock-In suppression
        if ($isLockInTick) return 0;

        if (self::isBlackoutSettlementPhase($season, $player['current_game_time'] ?? null)) return 0;
        
        // Not participating
        if (!$player['participation_enabled']) return 0;

        $presenceState = self::resolveEconomicPresenceState($player, $season, $player['current_game_time'] ?? null);
        if ($presenceState === 'Offline') {
            return 0;
        }

        $cadenceDivisor = self::ticksPerRealMinute();
        
        $baseActive = (int)$season['base_ubi_active_per_tick'];
        $idleFactorFp = (int)$season['base_ubi_idle_factor_fp'];
        $baseActiveFp = intdiv(self::toFixedPoint($baseActive), $cadenceDivisor);
        
        // Branch selection based on activity state
        if ($presenceState === 'Active') {
            $ubiFp = $baseActiveFp;
        } else {
            $ubiFp = intdiv($baseActiveFp * $idleFactorFp, FP_SCALE);
        }
        
        // Apply inflation dampening
        $totalSupply = (int)$season['total_coins_supply'];
        $inflationFp = self::inflationFactor($season, $totalSupply);
        $ubiFp = intdiv($ubiFp * $inflationFp, FP_SCALE);
        
        // Apply minimum floor
        $minUbi = (int)$season['ubi_min_per_tick'];
        $minUbiFp = intdiv(self::toFixedPoint($minUbi), $cadenceDivisor);
        $ubiFp = max($ubiFp, $minUbiFp);
        
        // Ensure non-negative
        return max(0, (int)$ubiFp);
    }

    /**
     * Gross per-tick rate in fixed point after boost modifier, guaranteed boost floor,
     * and piecewise interpolated gross-rate bonus.
     */
    public static function calculateGrossRatePerTickFp($season, $player, $participation, $boostModFp, $isLockInTick = false) {
        $ratePerTickFp = self::calculateUBIFp($season, $player, $participation, $isLockInTick);
        $ratePerTickFp = self::applyBoostModifierFp($ratePerTickFp, (int)$boostModFp);
        $ratePerTickFp += self::guaranteedBoostFloorFp((int)$boostModFp);
        // Piecewise interpolated bonus: converts boostModFp to boost% (1% = FP_SCALE/100)
        // and applies the smooth tiered bonus on top of the UBI-derived rate.
        $fpPerPercent = FP_SCALE / 100.0;
        $boostPct = (float)(int)$boostModFp / $fpPerPercent;
        $ratePerTickFp += self::grossRateBonusFpFromBoostPct($boostPct);
        return max(0, (int)$ratePerTickFp);
    }

    /**
     * Calculate explicit hoarding sink in whole coins per tick.
     */
    public static function calculateHoardingSinkCoinsPerTick($season, $player, $participation, $grossRatePerTickFp) {
        if (!self::hoardingSinkEnabled($season)) return 0;
        if (!$participation) return 0;
        if (self::isBlackoutSettlementPhase($season, $player['current_game_time'] ?? null)) return 0;

        $presenceState = self::resolveEconomicPresenceState($player, $season, $player['current_game_time'] ?? null);
        if ($presenceState === 'Offline') return 0;

        $coinsHeld = max(0, (int)($participation['coins'] ?? 0));
        if ($coinsHeld <= 0) return 0;

        $ticksPerHour = self::ticksPerRealHour();
        $safeHours = max(0, (int)($season['hoarding_safe_hours'] ?? 12));
        $safeMinCoins = max(0, (int)($season['hoarding_safe_min_coins'] ?? 20000));
        $grossCoinsPerTick = max(0, intdiv(max(0, (int)$grossRatePerTickFp), FP_SCALE));
        $dynamicSafeCoins = $safeHours * $grossCoinsPerTick * $ticksPerHour;
        $safeBufferCoins = max($safeMinCoins, $dynamicSafeCoins);

        $excess = max(0, $coinsHeld - $safeBufferCoins);
        if ($excess <= 0) return 0;

        $tier1Cap = max(0, (int)($season['hoarding_tier1_excess_cap'] ?? 50000));
        $tier2Cap = max(0, (int)($season['hoarding_tier2_excess_cap'] ?? 200000));
        $tier1RateFp = max(0, (int)($season['hoarding_tier1_rate_hourly_fp'] ?? 200));
        $tier2RateFp = max(0, (int)($season['hoarding_tier2_rate_hourly_fp'] ?? 500));
        $tier3RateFp = max(0, (int)($season['hoarding_tier3_rate_hourly_fp'] ?? 1000));

        $tier1Excess = ($tier1Cap > 0) ? min($excess, $tier1Cap) : 0;
        $remaining = max(0, $excess - $tier1Excess);
        $tier2Excess = ($tier2Cap > 0) ? min($remaining, $tier2Cap) : 0;
        $tier3Excess = max(0, $remaining - $tier2Excess);

        $denominator = FP_SCALE * $ticksPerHour;
        $sinkPerTick = 0;
        if ($tier1Excess > 0 && $tier1RateFp > 0) {
            $sinkPerTick += intdiv($tier1Excess * $tier1RateFp, $denominator);
        }
        if ($tier2Excess > 0 && $tier2RateFp > 0) {
            $sinkPerTick += intdiv($tier2Excess * $tier2RateFp, $denominator);
        }
        if ($tier3Excess > 0 && $tier3RateFp > 0) {
            $sinkPerTick += intdiv($tier3Excess * $tier3RateFp, $denominator);
        }

        if ($sinkPerTick <= 0) return 0;

        if ($presenceState !== 'Active') {
            $idleMultFp = max(0, (int)($season['hoarding_idle_multiplier_fp'] ?? FP_SCALE));
            $sinkPerTick = intdiv($sinkPerTick * $idleMultFp, FP_SCALE);
        }

        $capRatioFp = max(0, (int)($season['hoarding_sink_cap_ratio_fp'] ?? 350000));
        if ($capRatioFp > 0) {
            $capCoinsPerTick = intdiv(max(0, (int)$grossRatePerTickFp) * $capRatioFp, FP_SCALE * FP_SCALE);
            $sinkPerTick = min($sinkPerTick, $capCoinsPerTick);
        }

        return max(0, min((int)$sinkPerTick, $coinsHeld));
    }

    /**
     * Compute gross/sink/net rates used by tick processing and API presentation.
     */
    public static function calculateRateBreakdown($season, $player, $participation, $boostModFp, $isFrozen = false, $isLockInTick = false) {
        if ($isFrozen) {
            return [
                'gross_rate_fp' => 0,
                'sink_per_tick' => 0,
                'net_rate_fp' => 0,
            ];
        }

        if (self::isBlackoutSettlementPhase($season, $player['current_game_time'] ?? null)) {
            return [
                'gross_rate_fp' => 0,
                'sink_per_tick' => 0,
                'net_rate_fp' => 0,
            ];
        }

        $grossRateFp = self::calculateGrossRatePerTickFp($season, $player, $participation, $boostModFp, $isLockInTick);
        $sinkPerTick = self::calculateHoardingSinkCoinsPerTick($season, $player, $participation, $grossRateFp);
        $netRateFp = max(0, $grossRateFp - self::toFixedPoint($sinkPerTick));

        return [
            'gross_rate_fp' => $grossRateFp,
            'sink_per_tick' => $sinkPerTick,
            'net_rate_fp' => $netRateFp,
        ];
    }
    
    /**
     * Calculate star price based on effective coin supply with velocity clamps.
     *
     * Supply selection (when $totalCoinsEndOfTick is not provided):
     *   - Uses season.effective_price_supply if > 0 (active-weighted or active-only mode).
     *   - Falls back to total_coins_supply_end_of_tick for backward compatibility.
     *
     * Velocity clamp (applied after raw table lookup, before hard cap/floor):
     *   - season.starprice_max_upstep_fp: max upward movement per tick (fp, vs previous price).
     *   - season.starprice_max_downstep_fp: max downward movement per tick (fp, vs previous price).
     *   - Hard cap (star_price_cap) and floor (1) are preserved as final guardrails.
     */
    public static function calculateStarPrice($season, $totalCoinsEndOfTick = null) {
        if ($totalCoinsEndOfTick === null) {
            $activeOnly = (int)($season['starprice_active_only'] ?? 0);
            if ($activeOnly) {
                // starprice_active_only = 1: use effective_price_supply unconditionally,
                // even when it is 0 (no active players = 0 pricing pressure — do not fall
                // back to total_coins_supply_end_of_tick which would re-introduce idle influence).
                $totalCoinsEndOfTick = (int)($season['effective_price_supply'] ?? 0);
            } else {
                // Default (weighted-idle) mode: use effective_price_supply when > 0
                // (i.e. after the first tick has populated it); fall back to
                // total_coins_supply_end_of_tick for fresh seasons or backward compatibility.
                $effectiveSupply = (int)($season['effective_price_supply'] ?? 0);
                $totalCoinsEndOfTick = ($effectiveSupply > 0)
                    ? $effectiveSupply
                    : (int)$season['total_coins_supply_end_of_tick'];
            }
        }

        $table = json_decode($season['starprice_table'], true);
        $price = self::piecewiseLinear($totalCoinsEndOfTick, $table, 'm', 'price');

        // Apply per-tick velocity clamp relative to previous price.
        $prevPrice = (int)($season['current_star_price'] ?? 0);
        if ($prevPrice > 0) {
            $cadenceDivisor = self::ticksPerRealMinute();
            $maxUpstepFp   = max(1, intdiv((int)($season['starprice_max_upstep_fp']   ?? 2000), $cadenceDivisor));
            $maxDownstepFp = max(1, intdiv((int)($season['starprice_max_downstep_fp'] ?? 10000), $cadenceDivisor));
            // At least 1 coin of movement headroom in each direction.
            // Note: for very small prevPrice values (< 500), intdiv truncates to 0 and
            // max(1,...) ensures at least 1 coin of movement is always allowed; this
            // means the effective percentage can exceed the configured step for low prices,
            // which is intentional to prevent the price from freezing near zero.
            $maxUp   = max(1, intdiv($prevPrice * $maxUpstepFp,   FP_SCALE));
            $maxDown = max(1, intdiv($prevPrice * $maxDownstepFp, FP_SCALE));
            $price   = min($price, $prevPrice + $maxUp);
            $price   = max($price, $prevPrice - $maxDown);
        }

        // Hard cap and floor (preserved as final guardrails).
        $price = min($price, (int)$season['star_price_cap']);
        return max(1, $price);
    }
    
    /**
     * Compute Early Lock-In payout.
     *
     * Conversion order (per canon):
     *  1. T1–T5 sigils are refunded at their per-tier star values and added to
     *     the player's seasonal star total.
     *  2. The combined seasonal star total is then converted to global stars at
     *     65% (floor).
     *
     * T6 sigils are NOT refunded and must be handled separately by the caller
     * (they are destroyed with no compensation).
     *
     * @param int   $seasonalStars  Player's current seasonal star balance.
     * @param int[] $sigilCounts    Indexed array [0..4] = counts for T1–T5.
     * @param int[] $tierCosts      Indexed array [0..4] = star refund value per sigil for T1–T5.
     * @return array {
     *     sigil_refund_stars: int,
     *     total_seasonal_stars: int,
     *     global_stars_gained: int
     * }
     */
    public static function computeEarlyLockInPayout(int $seasonalStars, array $sigilCounts, array $tierCosts): array {
        $sigilRefundStars = 0;
        for ($i = 0; $i < 5; $i++) {
            $count = (int)($sigilCounts[$i] ?? 0);
            $cost  = (int)($tierCosts[$i] ?? 0);
            $sigilRefundStars += $count * $cost;
        }
        $totalSeasonalStars = $seasonalStars + $sigilRefundStars;
        $grant = self::applyGlobalStarsGrantWithCarry($totalSeasonalStars, 0, 65, 100);
        return [
            'sigil_refund_stars'   => $sigilRefundStars,
            'total_seasonal_stars' => $totalSeasonalStars,
            'global_stars_gained'  => $grant['global_stars_gained'],
            'global_stars_grant_fp' => $grant['grant_fp'],
            'global_stars_fractional_fp' => $grant['global_stars_fractional_fp'],
            'global_stars_progress_percent' => $grant['global_stars_progress_percent'],
        ];
    }

    /**
     * Calculate vault cost for a tier based on remaining supply
     */
    public static function calculateVaultCost($vaultConfig, $tier, $remaining) {
        $config = json_decode($vaultConfig, true);
        $tierConfig = null;
        foreach ($config as $tc) {
            if ($tc['tier'] == $tier) {
                $tierConfig = $tc;
                break;
            }
        }
        
        if (!$tierConfig || $remaining <= 0) return null;
        
        $costTable = $tierConfig['cost_table'];
        // Step-based pricing: pick the FIRST entry where remaining >= entry's remaining_inclusive_min
        foreach ($costTable as $entry) {
            if ($remaining >= $entry['remaining']) {
                return $entry['cost'];
            }
        }
        
        // Fallback to last entry
        return end($costTable)['cost'];
    }
    
    /**
     * Compute sigil drop configuration.
     *
     * Sigil drops are fixed and no longer affected by player sigil inventory,
     * sigil power, or active boost modifier. The function keeps a stable shape
     * for existing call sites.
     *
     * @return array{drop_rate: int, tier_odds: array<int,int>}
     */
    public static function computePerPlayerSigilDropConfig($player, $boostModFp = 0) {
        return [
            'drop_rate' => max(1, (int)SIGIL_DROP_RATE),
            'tier_odds' => SIGIL_TIER_ODDS,
        ];
    }

    /**
     * Process Sigil drop for a player.
     * Returns tier number (1-5) or 0 for no drop.
     * Tier odds are shifted by sigil power, but Tier 6 is never randomly dropped.
     *
     * @param array      $season     Season row (must include season_id and season_seed).
     * @param int        $playerId   Player identifier (used as RNG input).
     * @param int        $seasonTick Absolute game-tick (used as RNG input).
     * @param int        $sigilPower Legacy sigil-power scalar; ignored when $dropConfig provided.
     * @param array|null $dropConfig Pre-computed per-player config from computePerPlayerSigilDropConfig().
     *                               When supplied, $sigilPower is not used.
     */
    public static function processSigilDrop($season, $playerId, $seasonTick, $sigilPower = 0, array $dropConfig = null) {
        // Deterministic RNG using SHA-256.
        // Use 'J' (unsigned 64-bit big-endian) instead of 'P' (machine byte-order) so
        // the hash input is identical on every platform/PHP build.
        $seed = $season['season_seed'];
        $input = pack('J', $season['season_id']) . pack('J', $seasonTick) . $seed . pack('J', $playerId);
        $hash = hash('sha256', $input, true);

        // Use 'N' (unsigned 32-bit big-endian) for both extractions: portable across all
        // PHP platforms unlike 'P' (machine byte-order 64-bit). A 32-bit range
        // (0–4,294,967,295) is far larger than SIGIL_DROP_RATE and 1,000,000,
        // so the modulo distribution is effectively uniform.

        // Resolve drop rate and tier odds from per-player config or legacy sigil power.
        if ($dropConfig !== null) {
            $dropRate = (int)$dropConfig['drop_rate'];
            $tierOdds = $dropConfig['tier_odds'];
        } else {
            $dropRate = self::sigilDropRateForPower($sigilPower);
            $tierOdds = self::adjustedSigilTierOdds($sigilPower);
        }

        // Bernoulli trial: bytes 0-3 mod drop-rate denominator (power/boost-scaled)
        $trial = unpack('N', substr($hash, 0, 4))[1] % max(1, $dropRate);

        if ($trial !== 0) return 0; // No drop

        // Tier selection: bytes 4-7 mod 1,000,000 (matches SIGIL_TIER_ODDS fixed-point scale)
        $tierRoll = unpack('N', substr($hash, 4, 4))[1] % 1000000;

        $cumulative = 0;
        foreach ($tierOdds as $tier => $odds) {
            $cumulative += $odds;
            if ($tierRoll < $cumulative) return $tier;
        }

        return 1; // Fallback
    }

    /**
     * Deterministically sample a sigil tier (1-5) from per-player odds without
     * running the Bernoulli no-drop trial. Used for paced delivery of queued RNG drops.
     */
    public static function sampleSigilTier($season, $playerId, $seasonTick, array $dropConfig = null, $sigilPower = 0) {
        $seed = $season['season_seed'];
        $input = pack('J', $season['season_id']) . pack('J', $seasonTick) . $seed . pack('J', $playerId);
        $hash = hash('sha256', $input, true);

        if ($dropConfig !== null) {
            $tierOdds = $dropConfig['tier_odds'];
        } else {
            $tierOdds = self::adjustedSigilTierOdds($sigilPower);
        }

        $tierRoll = unpack('N', substr($hash, 4, 4))[1] % 1000000;
        $cumulative = 0;
        foreach ($tierOdds as $tier => $odds) {
            $cumulative += (int)$odds;
            if ($tierRoll < $cumulative) return (int)$tier;
        }

        return 1;
    }

    /**
     * Resolve player drop activity state from online + activity flags.
     */
    public static function resolveSigilDropActivityState($player) {
        $presenceState = (string)($player['economic_presence_state'] ?? '');
        if (in_array($presenceState, ['Active', 'Idle', 'Offline'], true)) {
            return $presenceState;
        }

        $isOnline = !empty($player['online_current']);
        if (!$isOnline) {
            return 'Offline';
        }

        return (($player['activity_state'] ?? 'Idle') === 'Active') ? 'Active' : 'Idle';
    }

    /**
     * Deterministically evaluate one sigil drop attempt for one tick.
     * Returns null (no drop) or a payload with tier and metadata.
     */
    public static function evaluateSigilDropForTick($season, $player, $tickIndex) {
        $activityState = self::resolveSigilDropActivityState($player);
        $activityMultiplierFp = self::sigilActivityMultiplierFp($activityState);

        // Offline short-circuit: no RNG draws and no drop.
        if ($activityMultiplierFp <= 0) {
            return null;
        }

        $effectiveDropChanceFp = self::sigilEffectiveDropChanceFp($activityState);
        if ($effectiveDropChanceFp <= 0) {
            return null;
        }

        $seasonPhase = self::sigilSeasonPhase($season, $tickIndex);
        if ($seasonPhase === (string)SIGIL_SEASON_PHASE_BLACKOUT) {
            return null;
        }

        $seasonId = (int)$season['season_id'];
        $playerId = (int)$player['player_id'];
        $tickIndex = (int)$tickIndex;
        $gateRoll = self::deterministicSigilRollU32($seasonId, $playerId, $tickIndex, 'sigil_gate', $season['season_seed']);

        $u32Range = 4294967296;
        $gateThreshold = intdiv($effectiveDropChanceFp * $u32Range, FP_SCALE);
        if ($gateRoll >= $gateThreshold) {
            return null;
        }

        $seasonProgressFp = self::sigilSeasonProgressFp($season, $tickIndex);
        $tierWeights = self::sigilTierWeightsForPhase($seasonPhase);
        $tierRoll = self::deterministicSigilRollU32($seasonId, $playerId, $tickIndex, 'sigil_tier', $season['season_seed']);
        $tier = self::pickWeightedTier($tierWeights, $tierRoll);

        return [
            'sigil_id' => null,
            'tier' => (int)$tier,
            'tick_index' => $tickIndex,
            'activity_state' => $activityState,
            'season_phase' => $seasonPhase,
            'season_progress' => $seasonProgressFp / FP_SCALE,
            'metadata' => [
                'algorithm_version' => (string)SIGIL_DROP_ALGORITHM_VERSION,
                'effective_drop_chance_fp' => $effectiveDropChanceFp,
                'activity_multiplier_fp' => $activityMultiplierFp,
                'season_phase' => $seasonPhase,
                'season_progress_fp' => $seasonProgressFp,
                'gate_roll_u32' => $gateRoll,
                'tier_roll_u32' => $tierRoll,
                'tier_weights' => $tierWeights,
                'available_tiers' => self::sigilAvailableTiersForPhase($seasonPhase),
            ],
        ];
    }

    public static function sigilActivityMultiplierFp($activityState) {
        $multiplierMap = (array)SIGIL_ACTIVITY_MULTIPLIER_FP;
        return max(0, (int)($multiplierMap[(string)$activityState] ?? 0));
    }

    public static function sigilEffectiveDropChanceFp($activityState) {
        $baseChanceFp = max(0, min(FP_SCALE, (int)SIGIL_DROP_CHANCE_FP));
        $activityMultiplierFp = self::sigilActivityMultiplierFp($activityState);
        return intdiv($baseChanceFp * $activityMultiplierFp, FP_SCALE);
    }

    public static function sigilSeasonProgressFp($season, $tickIndex) {
        return self::seasonProgressFp($season, $tickIndex);
    }

    public static function sigilSeasonPhase($season, $tickIndex) {
        $startTick = (int)($season['start_time'] ?? 0);
        $endTick = (int)($season['end_time'] ?? $startTick + 1);
        $duration = max(1, $endTick - $startTick);

        $blackoutTicks = max(1, min($duration, (int)SIGIL_BLACKOUT_DURATION_TICKS));
        $blackoutStartTick = $endTick - $blackoutTicks;
        $lateActiveTicks = max(1, min(max(1, $blackoutStartTick - $startTick), (int)SIGIL_LATE_ACTIVE_DURATION_TICKS));
        $lateActiveStartTick = $blackoutStartTick - $lateActiveTicks;
        if ((int)$tickIndex >= $blackoutStartTick) {
            return (string)SIGIL_SEASON_PHASE_BLACKOUT;
        }

        if ((int)$tickIndex >= $lateActiveStartTick) {
            return (string)SIGIL_SEASON_PHASE_LATE_ACTIVE;
        }

        $preLateActiveSpan = max(1, $lateActiveStartTick - $startTick);
        $elapsedPreLateActive = max(0, min($preLateActiveSpan, (int)$tickIndex - $startTick));
        $earlyCutoff = max(1, intdiv($preLateActiveSpan * (int)SIGIL_EARLY_PHASE_FRACTION_FP, FP_SCALE));

        if ($elapsedPreLateActive < $earlyCutoff) {
            return (string)SIGIL_SEASON_PHASE_EARLY;
        }

        return (string)SIGIL_SEASON_PHASE_MID;
    }

    public static function sigilTierWeightsForProgressFp($seasonProgressFp) {
        $progress = max(0, min(FP_SCALE, (int)$seasonProgressFp));
        $blackoutStartFp = FP_SCALE - min(FP_SCALE, (int)intdiv((int)SIGIL_BLACKOUT_DURATION_TICKS * FP_SCALE, max(1, (int)SEASON_DURATION)));
        $lateActiveStartFp = max(0, $blackoutStartFp - min(FP_SCALE, (int)intdiv((int)SIGIL_LATE_ACTIVE_DURATION_TICKS * FP_SCALE, max(1, (int)SEASON_DURATION))));

        if ($progress >= $blackoutStartFp) {
            return self::sigilTierWeightsForPhase((string)SIGIL_SEASON_PHASE_BLACKOUT);
        }
        if ($progress >= $lateActiveStartFp) {
            return self::sigilTierWeightsForPhase((string)SIGIL_SEASON_PHASE_LATE_ACTIVE);
        }
        if ($progress < (int)SIGIL_EARLY_PHASE_FRACTION_FP) {
            return self::sigilTierWeightsForPhase((string)SIGIL_SEASON_PHASE_EARLY);
        }

        return self::sigilTierWeightsForPhase((string)SIGIL_SEASON_PHASE_MID);
    }

    public static function sigilAvailableTiersForPhase($phase) {
        $phase = (string)$phase;
        $map = (array)SIGIL_PHASE_AVAILABLE_TIERS;
        $tiers = $map[$phase] ?? $map[(string)SIGIL_SEASON_PHASE_EARLY] ?? [1, 2, 3];

        $normalized = [];
        foreach ((array)$tiers as $tier) {
            $tier = (int)$tier;
            if ($tier >= 1 && $tier <= SIGIL_MAX_TIER) {
                $normalized[] = $tier;
            }
        }

        sort($normalized);
        return array_values(array_unique($normalized));
    }

    public static function sigilTierWeightsForPhase($phase) {
        $phase = (string)$phase;
        $phaseWeights = (array)SIGIL_PHASE_TIER_WEIGHTS;
        $rawWeights = (array)($phaseWeights[$phase] ?? []);
        $available = self::sigilAvailableTiersForPhase($phase);
        $allowed = array_fill_keys($available, true);

        $weights = [];
        for ($tier = 1; $tier <= SIGIL_MAX_TIER; $tier++) {
            if (!isset($allowed[$tier])) {
                $weights[$tier] = 0;
                continue;
            }
            $weights[$tier] = max(1, (int)($rawWeights[$tier] ?? 1));
        }

        return $weights;
    }

    private static function deterministicSigilRollU32($seasonId, $playerId, $tickIndex, $streamTag, $seasonSeed) {
        $input =
            pack('J', (int)$seasonId) .
            pack('J', (int)$playerId) .
            pack('J', (int)$tickIndex) .
            (string)$seasonSeed .
            (string)$streamTag;

        $hash = hash('sha256', $input, true);
        return (int)unpack('N', substr($hash, 0, 4))[1];
    }

    private static function seasonProgressFp($season, $tickIndex) {
        $startTick = (int)($season['start_time'] ?? 0);
        $endTick = (int)($season['end_time'] ?? $startTick + 1);
        $duration = max(1, $endTick - $startTick);
        $elapsed = max(0, min($duration, (int)$tickIndex - $startTick));
        return max(0, min(FP_SCALE, intdiv($elapsed * FP_SCALE, $duration)));
    }

    private static function pickWeightedTier(array $weights, $rollU32) {
        $total = 0;
        foreach ($weights as $weight) {
            $total += max(0, (int)$weight);
        }
        if ($total <= 0) {
            return 1;
        }

        $target = ((int)$rollU32 % $total);
        $cumulative = 0;
        foreach ($weights as $tier => $weight) {
            $cumulative += max(0, (int)$weight);
            if ($target < $cumulative) {
                return (int)$tier;
            }
        }

        return 1;
    }
}
