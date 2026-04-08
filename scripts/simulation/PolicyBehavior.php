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
        $probability = (float)($profile['buy_stars_probability'] ?? 0.0);
        if (!SimulationRandom::chance($seed, $probability, ['buy-stars', $playerState['player_id'], $tick, $phase])) {
            return 0;
        }

        $requested = max(1, (int)($profile['stars_per_purchase'] ?? 1));
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

        return max(0, min($requested, intdiv($availableCoins, $price)));
    }

    public static function decideBoostPurchase(array $archetype, array $playerState, string $phase, string $seed, int $tick): ?array
    {
        $profile = self::phaseProfile($archetype, $phase);
        if (!SimulationRandom::chance($seed, (float)($profile['buy_boost_probability'] ?? 0.0), ['buy-boost', $playerState['player_id'], $tick, $phase])) {
            return null;
        }

        $preferredTier = max(1, min(5, (int)($profile['preferred_boost_tier'] ?? 1)));
        for ($tier = $preferredTier; $tier >= 1; $tier--) {
            if ((int)($playerState['participation']['sigils_t' . $tier] ?? 0) > 0 && BoostCatalog::canSpendSigilTier($tier)) {
                $kind = (!empty($playerState['boost']['is_active']) && ((int)$playerState['boost']['modifier_fp'] >= 1000000)) ? 'time' : 'power';
                return ['tier' => $tier, 'kind' => $kind];
            }
        }

        return null;
    }

    public static function decideCombineTier(array $archetype, array $playerState, string $phase, string $seed, int $tick): ?int
    {
        $profile = self::phaseProfile($archetype, $phase);
        if (!SimulationRandom::chance($seed, (float)($profile['combine_probability'] ?? 0.0), ['combine', $playerState['player_id'], $tick, $phase])) {
            return null;
        }

        for ($tier = SIGIL_MAX_TIER - 1; $tier >= 1; $tier--) {
            $required = (int)(SIGIL_COMBINE_RECIPES[$tier] ?? 0);
            if ($required > 0 && (int)($playerState['participation']['sigils_t' . $tier] ?? 0) >= $required) {
                return $tier;
            }
        }

        return null;
    }

    public static function shouldLockIn(array $archetype, array $playerState, string $phase, string $seed, int $tick): bool
    {
        if (!in_array($phase, ['LATE_ACTIVE', 'BLACKOUT'], true)) {
            return false;
        }

        $profile = self::phaseProfile($archetype, $phase);
        if ((int)($playerState['participation']['participation_ticks_since_join'] ?? 0) < MIN_PARTICIPATION_TICKS) {
            return false;
        }

        return SimulationRandom::chance($seed, (float)($profile['lock_in_probability'] ?? 0.0), ['lock-in', $playerState['player_id'], $tick, $phase]);
    }

    public static function chooseFreezeTarget(array $archetype, array $playerState, array $candidates, string $phase, string $seed, int $tick): ?int
    {
        $profile = self::phaseProfile($archetype, $phase);
        if ((int)($playerState['participation']['sigils_t6'] ?? 0) < 1) {
            return null;
        }
        if (!SimulationRandom::chance($seed, (float)($profile['freeze_probability'] ?? 0.0), ['freeze', $playerState['player_id'], $tick, $phase])) {
            return null;
        }

        return self::pickHighestValueTarget($playerState, $candidates);
    }

    public static function chooseTheftTarget(array $archetype, array $playerState, array $candidates, string $phase, string $seed, int $tick): ?int
    {
        $profile = self::phaseProfile($archetype, $phase);
        if (((int)($playerState['participation']['sigils_t4'] ?? 0) + (int)($playerState['participation']['sigils_t5'] ?? 0)) < 1) {
            return null;
        }
        if (!SimulationRandom::chance($seed, (float)($profile['theft_probability'] ?? 0.0), ['theft', $playerState['player_id'], $tick, $phase])) {
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
}
