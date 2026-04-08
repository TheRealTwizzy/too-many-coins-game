<?php

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/boost_catalog.php';
require_once __DIR__ . '/../../includes/economy.php';
require_once __DIR__ . '/SimulationRandom.php';

class PolicyBehavior
{
    public const UNMODELED_MECHANICS = [
        'idle_ack_reactivation_snapshot' => 'Simulation uses direct phase-aware presence selection and does not emulate manual idle acknowledgements.',
        'vault_market_spending' => 'Vault supply/cost interactions are excluded in Phase 1 to keep seasonal economy focus on coins, stars, sigils, boosts, theft, freeze, and settlement.',
        'live_api_concurrency_ordering' => 'Actions are resolved in a deterministic per-tick order after accrual instead of concurrent API timing.',
    ];

    public static function phaseProfile(array $archetype, string $phase): array
    {
        return (array)($archetype['phases'][$phase] ?? $archetype['phases']['MID'] ?? []);
    }

    public static function resolvePresenceState(array $archetype, string $phase, string $seed, int $playerId, int $tick): string
    {
        $profile = self::phaseProfile($archetype, $phase);
        $presence = (array)($profile['presence'] ?? []);
        $roll = SimulationRandom::float01($seed, ['presence', $playerId, $tick, $phase]);
        $active = (float)($presence['Active'] ?? 0.0);
        $idle = (float)($presence['Idle'] ?? 0.0);

        if ($roll < $active) {
            return 'Active';
        }
        if ($roll < ($active + $idle)) {
            return 'Idle';
        }
        return 'Offline';
    }

    public static function decideStarPurchase(array $archetype, array $playerState, array $season, string $phase, string $seed, int $tick): int
    {
        if (!in_array((string)$season['status'], ['Active', 'Blackout'], true)) {
            return 0;
        }

        $profile = self::phaseProfile($archetype, $phase);
        $behavior = self::behaviorProfile($archetype, $seed, (int)$playerState['player_id']);
        $reserveRatio = (float)($archetype['coin_reserve_ratio'] ?? 0.25);
        $price = Economy::publishedStarPrice($season, (string)$season['status']);
        if ($price <= 0) {
            return 0;
        }

        $coins = max(0, (int)($playerState['participation']['coins'] ?? 0));
        $availableCoins = max(0, $coins - (int)floor($coins * $reserveRatio));
        if ($availableCoins < $price) {
            return 0;
        }

        $affordable = intdiv($availableCoins, $price);
        $deploymentUrgency = self::deploymentUrgency($behavior, $phase, $season, $tick);
        $probability = (float)($profile['buy_stars_probability'] ?? 0.0);
        $probability += 0.10 * $behavior['star_bias'];
        $probability += 0.18 * $deploymentUrgency;
        $probability += min(0.18, $affordable / 18);
        $probability -= 0.16 * $behavior['hoard_bias'] * self::phaseWeight($phase, ['EARLY' => 1.0, 'MID' => 0.55, 'LATE_ACTIVE' => 0.08, 'BLACKOUT' => 0.0]);
        $probability -= 0.12 * $behavior['late_conversion'] * self::phaseWeight($phase, ['EARLY' => 1.0, 'MID' => 0.40, 'LATE_ACTIVE' => 0.0, 'BLACKOUT' => 0.0]);
        if ($phase === 'BLACKOUT') {
            $probability += 0.12;
        }
        $probability += self::noise($seed, ['buy-stars-noise', $playerState['player_id'], $tick, $phase], 0.08 + (0.08 * (1.0 - $behavior['discipline'])));
        $probability = self::clamp01($probability);
        if (!SimulationRandom::chance($seed, $probability, ['buy-stars', $playerState['player_id'], $tick, $phase])) {
            return 0;
        }

        $requested = max(1, (int)($profile['stars_per_purchase'] ?? 1));
        $extraUpper = min(4, max(0, $affordable - $requested));
        $extraCap = min($extraUpper, (int)floor($deploymentUrgency * (2 + $behavior['star_bias'] * 3)));
        $extra = ($extraCap > 0)
            ? SimulationRandom::intRange($seed, 0, $extraCap, ['buy-stars-batch', $playerState['player_id'], $tick, $phase])
            : 0;

        return max(0, min($requested + $extra, $affordable));
    }

    public static function decideBoostPurchase(array $archetype, array $playerState, string $phase, string $seed, int $tick): ?array
    {
        $profile = self::phaseProfile($archetype, $phase);
        $behavior = self::behaviorProfile($archetype, $seed, (int)$playerState['player_id']);
        $probability = (float)($profile['buy_boost_probability'] ?? 0.0);
        $probability += 0.14 * $behavior['boost_bias'];
        $probability += 0.10 * self::phaseWeight($phase, ['EARLY' => 0.10, 'MID' => 0.45, 'LATE_ACTIVE' => 1.0, 'BLACKOUT' => 0.55]);
        $probability += 0.06 * self::deploymentUrgency($behavior, $phase, null, $tick);
        $probability -= 0.08 * $behavior['hoard_bias'] * self::phaseWeight($phase, ['EARLY' => 1.0, 'MID' => 0.40, 'LATE_ACTIVE' => 0.0, 'BLACKOUT' => 0.0]);
        $probability += self::noise($seed, ['buy-boost-noise', $playerState['player_id'], $tick, $phase], 0.05 + (0.08 * (1.0 - $behavior['discipline'])));
        if (!SimulationRandom::chance($seed, self::clamp01($probability), ['buy-boost', $playerState['player_id'], $tick, $phase])) {
            return null;
        }

        $preferredTier = max(1, min(5, (int)($profile['preferred_boost_tier'] ?? 1)));
        if (in_array($phase, ['LATE_ACTIVE', 'BLACKOUT'], true) && $behavior['boost_bias'] > 0.6) {
            $preferredTier = min(5, $preferredTier + 1);
        }

        for ($tier = $preferredTier; $tier >= 1; $tier--) {
            if ((int)($playerState['participation']['sigils_t' . $tier] ?? 0) > 0 && BoostCatalog::canSpendSigilTier($tier)) {
                $expiresSoon = !empty($playerState['boost']['is_active'])
                    && ((int)($playerState['boost']['expires_tick'] ?? 0) <= ($tick + max(1, (int)floor(120 * (1.0 - $behavior['discipline'])))));
                $kind = ($expiresSoon || (!empty($playerState['boost']['is_active']) && ((int)$playerState['boost']['modifier_fp'] >= 1000000)))
                    ? 'time'
                    : 'power';
                return ['tier' => $tier, 'kind' => $kind];
            }
        }

        return null;
    }

    public static function decideCombineTier(array $archetype, array $playerState, string $phase, string $seed, int $tick): ?int
    {
        $profile = self::phaseProfile($archetype, $phase);
        $behavior = self::behaviorProfile($archetype, $seed, (int)$playerState['player_id']);
        $readyTiers = [];
        for ($tier = SIGIL_MAX_TIER - 1; $tier >= 1; $tier--) {
            $required = (int)(SIGIL_COMBINE_RECIPES[$tier] ?? 0);
            if ($required > 0 && (int)($playerState['participation']['sigils_t' . $tier] ?? 0) >= $required) {
                $readyTiers[] = $tier;
            }
        }

        if ($readyTiers === []) {
            return null;
        }

        $probability = (float)($profile['combine_probability'] ?? 0.0);
        $probability += min(0.22, count($readyTiers) * 0.08);
        $probability += 0.08 * self::phaseWeight($phase, ['EARLY' => 0.0, 'MID' => 0.35, 'LATE_ACTIVE' => 1.0, 'BLACKOUT' => 0.0]);
        $probability += 0.08 * $behavior['aggression'];
        $probability -= 0.12 * $behavior['hoard_bias'] * self::phaseWeight($phase, ['EARLY' => 1.0, 'MID' => 0.45, 'LATE_ACTIVE' => 0.0, 'BLACKOUT' => 0.0]);
        $probability += self::noise($seed, ['combine-noise', $playerState['player_id'], $tick, $phase], 0.06 + (0.08 * (1.0 - $behavior['discipline'])));
        if (!SimulationRandom::chance($seed, self::clamp01($probability), ['combine', $playerState['player_id'], $tick, $phase])) {
            return null;
        }

        return (int)$readyTiers[0];
    }

    public static function shouldLockIn(array $archetype, array $playerState, array $season, string $phase, string $seed, int $tick): bool
    {
        if (!in_array($phase, ['MID', 'LATE_ACTIVE', 'BLACKOUT'], true)) {
            return false;
        }

        $profile = self::phaseProfile($archetype, $phase);
        if ((int)($playerState['participation']['participation_ticks_since_join'] ?? 0) < MIN_PARTICIPATION_TICKS) {
            return false;
        }

        $playerId = (int)$playerState['player_id'];
        $behavior = self::behaviorProfile($archetype, $seed, $playerId);
        $activeProgress = self::activeProgressFraction($season, $tick);
        if ($phase === 'MID') {
            $midGate = 0.36 + (0.12 * $behavior['patience']) - (0.10 * self::exitBias($behavior, 'MID'));
            if ($activeProgress < $midGate) {
                return false;
            }

            $stayIntent = (0.35 * $behavior['late_conversion'])
                + (0.30 * $behavior['expiry_bias'])
                + (0.20 * $behavior['risk_tolerance'])
                + (0.15 * $behavior['star_bias']);
            if ($stayIntent > (0.34 + (0.28 * self::exitBias($behavior, 'MID')))) {
                return false;
            }
        }

        $lockInValue = (float)self::lockInSnapshotStars($playerState['participation']);
        if ($lockInValue <= 0.0) {
            return false;
        }

        $continueValue = self::estimateContinueValue($archetype, $playerState, $season, $phase, $seed, $tick, $behavior);
        if ($phase !== 'BLACKOUT' && $continueValue > ($lockInValue * (1.10 - (0.20 * $behavior['risk_tolerance'])))) {
            return false;
        }

        if ($phase === 'BLACKOUT') {
            $holdForExpiry = 0.25
                + (0.45 * $behavior['expiry_bias'])
                + (0.20 * $behavior['risk_tolerance'])
                + (0.10 * $behavior['late_conversion'])
                + self::noise($seed, ['expiry-hold', $playerId, $tick], 0.12);
            if ($holdForExpiry > 0.40 && $lockInValue < ($continueValue * (1.35 + (0.25 * $behavior['risk_tolerance'])))) {
                return false;
            }
        }

        $valueEdge = ($lockInValue - $continueValue) / max(1.0, max($lockInValue, $continueValue));
        $probability = self::exitBias($behavior, $phase) + (0.35 * (float)($profile['lock_in_probability'] ?? 0.0));
        $probability += self::lockInUrgency($phase, $season, $tick);
        $probability += max(0.0, $valueEdge) * (0.24 + (0.22 * (1.0 - $behavior['risk_tolerance'])));
        $probability -= max(0.0, -$valueEdge) * (0.18 + (0.22 * $behavior['patience']));
        $probability -= 0.18 * $behavior['expiry_bias'];
        $probability -= 0.12 * $behavior['late_conversion'] * self::phaseWeight($phase, ['MID' => 1.0, 'LATE_ACTIVE' => 0.45, 'BLACKOUT' => 0.0]);
        $probability += 0.06 * self::sigilRefundRatio($playerState['participation']);
        if ($phase === 'BLACKOUT') {
            $probability += 0.04;
        }
        $probability += self::noise($seed, ['lock-in-noise', $playerId, $tick, $phase], 0.08 + (0.12 * (1.0 - $behavior['discipline'])));
        $probability = self::clamp01($probability, 0.0, 0.88);

        return SimulationRandom::chance($seed, $probability, ['lock-in', $playerId, $tick, $phase]);
    }

    public static function scheduleNextLockReview(array $archetype, array $season, string $phase, string $seed, int $playerId, int $tick): int
    {
        if (!in_array($phase, ['MID', 'LATE_ACTIVE', 'BLACKOUT'], true)) {
            return $tick + 1;
        }

        $behavior = self::behaviorProfile($archetype, $seed, $playerId);
        [$phaseStart, $phaseEnd] = self::phaseBounds($season, $phase);
        $phaseSpan = max(1, $phaseEnd - $phaseStart);
        $remaining = max(1, $phaseEnd - $tick);
        $targetReviews = match ($phase) {
            'MID' => 5,
            'LATE_ACTIVE' => 7,
            'BLACKOUT' => 4,
            default => 4,
        };
        $targetReviews += (int)round((1.0 - $behavior['discipline']) * 2);

        $baseInterval = max(1, intdiv($phaseSpan, max(1, $targetReviews)));
        $jitter = 1.0 + self::noise($seed, ['lock-review-jitter', $playerId, $tick, $phase], 0.35);
        $interval = (int)max(1, floor($baseInterval * $jitter));
        $interval = min($interval, $remaining);

        return $tick + max(1, $interval);
    }

    public static function chooseFreezeTarget(array $archetype, array $playerState, array $candidates, string $phase, string $seed, int $tick): ?int
    {
        $profile = self::phaseProfile($archetype, $phase);
        $behavior = self::behaviorProfile($archetype, $seed, (int)$playerState['player_id']);
        if ((int)($playerState['participation']['sigils_t6'] ?? 0) < 1) {
            return null;
        }
        $probability = (float)($profile['freeze_probability'] ?? 0.0);
        $probability += 0.10 * $behavior['aggression'];
        $probability += 0.08 * self::phaseWeight($phase, ['EARLY' => 0.0, 'MID' => 0.15, 'LATE_ACTIVE' => 1.0, 'BLACKOUT' => 0.70]);
        $probability += self::noise($seed, ['freeze-noise', $playerState['player_id'], $tick, $phase], 0.05 + (0.06 * (1.0 - $behavior['discipline'])));
        if (!SimulationRandom::chance($seed, self::clamp01($probability), ['freeze', $playerState['player_id'], $tick, $phase])) {
            return null;
        }

        return self::pickHighestValueTarget($playerState, $candidates);
    }

    public static function chooseTheftTarget(array $archetype, array $playerState, array $candidates, string $phase, string $seed, int $tick): ?int
    {
        $profile = self::phaseProfile($archetype, $phase);
        $behavior = self::behaviorProfile($archetype, $seed, (int)$playerState['player_id']);
        if (((int)($playerState['participation']['sigils_t4'] ?? 0) + (int)($playerState['participation']['sigils_t5'] ?? 0)) < 1) {
            return null;
        }
        $probability = (float)($profile['theft_probability'] ?? 0.0);
        $probability += 0.12 * $behavior['aggression'];
        $probability += 0.08 * self::phaseWeight($phase, ['EARLY' => 0.0, 'MID' => 0.20, 'LATE_ACTIVE' => 1.0, 'BLACKOUT' => 0.75]);
        $probability += self::noise($seed, ['theft-noise', $playerState['player_id'], $tick, $phase], 0.05 + (0.07 * (1.0 - $behavior['discipline'])));
        if (!SimulationRandom::chance($seed, self::clamp01($probability), ['theft', $playerState['player_id'], $tick, $phase])) {
            return null;
        }

        return self::pickHighestValueTarget($playerState, $candidates);
    }

    private static function pickHighestValueTarget(array $playerState, array $candidates): ?int
    {
        $bestId = null;
        $bestScore = -1;
        foreach ($candidates as $candidate) {
            if ((int)$candidate['player_id'] === (int)$playerState['player_id']) {
                continue;
            }
            if (!empty($candidate['locked_out'])) {
                continue;
            }
            $score = Economy::getSigilTotal($candidate['participation']) + max(0, (int)($candidate['participation']['seasonal_stars'] ?? 0));
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestId = (int)$candidate['player_id'];
            }
        }

        return $bestId;
    }

    private static function behaviorProfile(array $archetype, string $seed, int $playerId): array
    {
        $traits = (array)($archetype['traits'] ?? []);
        $discipline = self::clamp01((float)($traits['discipline'] ?? 0.5));
        $variance = 0.08 + (0.18 * (1.0 - $discipline));

        $profile = [
            'discipline' => $discipline,
            'risk_tolerance' => self::varyTrait((float)($traits['risk_tolerance'] ?? 0.5), $variance, $seed, $playerId, 'risk'),
            'patience' => self::varyTrait((float)($traits['patience'] ?? 0.5), $variance, $seed, $playerId, 'patience'),
            'late_conversion' => self::varyTrait((float)($traits['late_conversion'] ?? 0.5), $variance, $seed, $playerId, 'late-conversion'),
            'hoard_bias' => self::varyTrait((float)($traits['hoard_bias'] ?? 0.25), $variance, $seed, $playerId, 'hoard-bias'),
            'aggression' => self::varyTrait((float)($traits['aggression'] ?? 0.1), $variance, $seed, $playerId, 'aggression'),
            'boost_bias' => self::varyTrait((float)($traits['boost_bias'] ?? 0.2), $variance, $seed, $playerId, 'boost-bias'),
            'star_bias' => self::varyTrait((float)($traits['star_bias'] ?? 0.4), $variance, $seed, $playerId, 'star-bias'),
            'expiry_bias' => self::varyTrait((float)($traits['expiry_bias'] ?? 0.2), $variance, $seed, $playerId, 'expiry-bias'),
            'exit_by_phase' => [],
        ];

        foreach (['MID', 'LATE_ACTIVE', 'BLACKOUT'] as $phase) {
            $base = (float)($traits['exit_by_phase'][$phase] ?? 0.0);
            $profile['exit_by_phase'][$phase] = self::varyTrait($base, $variance, $seed, $playerId, 'exit-' . $phase);
        }

        return $profile;
    }

    private static function varyTrait(float $base, float $variance, string $seed, int $playerId, string $tag): float
    {
        $delta = self::noise($seed, ['trait', $tag, $playerId], $variance);
        return self::clamp01($base + $delta);
    }

    private static function deploymentUrgency(array $behavior, string $phase, ?array $season, int $tick): float
    {
        $urgency = 0.18 + (0.22 * $behavior['star_bias']) + (0.18 * $behavior['boost_bias']);
        $urgency += 0.30 * self::phaseWeight($phase, ['EARLY' => 0.0, 'MID' => 0.30, 'LATE_ACTIVE' => 1.0, 'BLACKOUT' => 0.75]);
        $urgency += 0.26 * $behavior['late_conversion'] * self::phaseWeight($phase, ['EARLY' => 0.0, 'MID' => 0.20, 'LATE_ACTIVE' => 1.0, 'BLACKOUT' => 0.55]);
        $urgency -= 0.22 * $behavior['hoard_bias'] * self::phaseWeight($phase, ['EARLY' => 1.0, 'MID' => 0.60, 'LATE_ACTIVE' => 0.08, 'BLACKOUT' => 0.0]);
        if ($season !== null) {
            $urgency += 0.16 * (1.0 - self::remainingActiveFraction($season, $tick));
        }

        return self::clamp01($urgency);
    }

    private static function estimateContinueValue(array $archetype, array $playerState, array $season, string $phase, string $seed, int $tick, array $behavior): float
    {
        $profile = self::phaseProfile($archetype, $phase);
        $presence = (array)($profile['presence'] ?? []);
        $player = (array)($playerState['player'] ?? []);
        $participation = (array)($playerState['participation'] ?? []);
        $boostModifier = !empty($playerState['boost']['is_active']) ? (int)($playerState['boost']['modifier_fp'] ?? 0) : 0;
        $isFrozen = !empty($playerState['freeze']['is_active']);

        $activePlayer = $player;
        $activePlayer['economic_presence_state'] = 'Active';
        $activePlayer['activity_state'] = 'Active';
        $idlePlayer = $player;
        $idlePlayer['economic_presence_state'] = 'Idle';
        $idlePlayer['activity_state'] = 'Idle';

        $activeRate = Economy::calculateRateBreakdown($season, $activePlayer, $participation, $boostModifier, $isFrozen);
        $idleRate = Economy::calculateRateBreakdown($season, $idlePlayer, $participation, $boostModifier, $isFrozen);

        $expectedNetCoinsPerTick = (((float)($presence['Active'] ?? 0.0)) * ((float)$activeRate['net_rate_fp'] / FP_SCALE))
            + (((float)($presence['Idle'] ?? 0.0)) * ((float)$idleRate['net_rate_fp'] / FP_SCALE));
        $ticksRemainingActive = max(0, (int)$season['blackout_time'] - $tick);
        $currentCoins = max(0, (int)($participation['coins'] ?? 0));
        $price = max(1, Economy::publishedStarPrice($season, (string)$season['status']));
        $reserveRatio = (float)($archetype['coin_reserve_ratio'] ?? 0.25);
        $reserveCoins = (int)floor($currentCoins * $reserveRatio);
        $convertibleCoins = max(0, $currentCoins - $reserveCoins);
        $deploymentUrgency = self::deploymentUrgency($behavior, $phase, $season, $tick);

        $futureCoins = max(0.0, $expectedNetCoinsPerTick) * max(0, $ticksRemainingActive);
        $expectedStarConversions = floor((($convertibleCoins * (0.35 + (0.65 * $deploymentUrgency))) + ($futureCoins * max(0.15, $deploymentUrgency))) / $price);
        $expectedActiveTicks = (int)floor(max(0, (float)($presence['Active'] ?? 0.0)) * max(0, (int)$season['end_time'] - $tick));
        $expectedExpiryBonus = Economy::calculateParticipationBonus((int)($participation['active_ticks_total'] ?? 0) + $expectedActiveTicks);

        return max(0.0, (float)($participation['seasonal_stars'] ?? 0) + $expectedStarConversions + $expectedExpiryBonus);
    }

    private static function lockInSnapshotStars(array $participation): int
    {
        $tierCosts = [
            (int)(SIGIL_REFERENCE_STARS_BY_TIER[1] ?? 0),
            (int)(SIGIL_REFERENCE_STARS_BY_TIER[2] ?? 0),
            (int)(SIGIL_REFERENCE_STARS_BY_TIER[3] ?? 0),
            (int)(SIGIL_REFERENCE_STARS_BY_TIER[4] ?? 0),
            (int)(SIGIL_REFERENCE_STARS_BY_TIER[5] ?? 0),
        ];
        $sigilCounts = [
            (int)($participation['sigils_t1'] ?? 0),
            (int)($participation['sigils_t2'] ?? 0),
            (int)($participation['sigils_t3'] ?? 0),
            (int)($participation['sigils_t4'] ?? 0),
            (int)($participation['sigils_t5'] ?? 0),
        ];

        return (int)Economy::computeEarlyLockInPayout((int)($participation['seasonal_stars'] ?? 0), $sigilCounts, $tierCosts)['total_seasonal_stars'];
    }

    private static function sigilRefundRatio(array $participation): float
    {
        $seasonalStars = max(1, (int)($participation['seasonal_stars'] ?? 0));
        $lockValue = self::lockInSnapshotStars($participation);
        return min(1.0, max(0.0, ($lockValue - $seasonalStars) / $seasonalStars));
    }

    private static function lockInUrgency(string $phase, array $season, int $tick): float
    {
        return match ($phase) {
            'MID' => 0.02 + (0.06 * self::activeProgressFraction($season, $tick)),
            'LATE_ACTIVE' => 0.08 + (0.16 * (1.0 - self::remainingActiveFraction($season, $tick))),
            'BLACKOUT' => 0.12 + (0.26 * (1.0 - self::remainingSeasonFraction($season, $tick))),
            default => 0.0,
        };
    }

    private static function remainingActiveFraction(array $season, int $tick): float
    {
        $activeSpan = max(1, (int)$season['blackout_time'] - (int)$season['start_time']);
        return self::clamp01(max(0, (int)$season['blackout_time'] - $tick) / $activeSpan);
    }

    private static function remainingSeasonFraction(array $season, int $tick): float
    {
        $seasonSpan = max(1, (int)$season['end_time'] - (int)$season['start_time']);
        return self::clamp01(max(0, (int)$season['end_time'] - $tick) / $seasonSpan);
    }

    private static function activeProgressFraction(array $season, int $tick): float
    {
        $activeSpan = max(1, (int)$season['blackout_time'] - (int)$season['start_time']);
        return self::clamp01(max(0, $tick - (int)$season['start_time']) / $activeSpan);
    }

    private static function phaseBounds(array $season, string $phase): array
    {
        $startTick = (int)($season['start_time'] ?? 0);
        $endTick = (int)($season['end_time'] ?? $startTick + 1);
        $blackoutStartTick = (int)($season['blackout_time'] ?? $endTick - 1);
        $lateActiveTicks = max(1, min(max(1, $blackoutStartTick - $startTick), (int)SIGIL_LATE_ACTIVE_DURATION_TICKS));
        $lateActiveStartTick = $blackoutStartTick - $lateActiveTicks;
        $preLateActiveSpan = max(1, $lateActiveStartTick - $startTick);
        $earlyCutoff = max(1, intdiv($preLateActiveSpan * (int)SIGIL_EARLY_PHASE_FRACTION_FP, FP_SCALE));
        $midStartTick = $startTick + $earlyCutoff;

        return match ($phase) {
            'EARLY' => [$startTick, $midStartTick],
            'MID' => [$midStartTick, $lateActiveStartTick],
            'LATE_ACTIVE' => [$lateActiveStartTick, $blackoutStartTick],
            'BLACKOUT' => [$blackoutStartTick, $endTick],
            default => [$startTick, $endTick],
        };
    }

    private static function exitBias(array $behavior, string $phase): float
    {
        return (float)($behavior['exit_by_phase'][$phase] ?? 0.0);
    }

    private static function phaseWeight(string $phase, array $weights): float
    {
        return (float)($weights[$phase] ?? 0.0);
    }

    private static function noise(string $seed, array $parts, float $amplitude): float
    {
        return (SimulationRandom::float01($seed, $parts) - 0.5) * 2.0 * max(0.0, $amplitude);
    }

    private static function clamp01(float $value, float $min = 0.0, float $max = 1.0): float
    {
        return max($min, min($max, $value));
    }
}
