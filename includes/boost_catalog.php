<?php
/**
 * Canonical boost catalog definitions.
 *
 * Source of truth for boost lifecycle values (scope, duration, modifier, names)
 * used by activation and listing paths.
 */
require_once __DIR__ . '/config.php';

class BoostCatalog
{
    public const POWER_CAP_FP_PER_PRODUCT = 1000000; // 100%
    public const TOTAL_POWER_CAP_FP = 5000000; // 500%
    public const TIME_CAP_SECONDS_PER_PRODUCT = 48 * 60 * 60; // 48 hours

    // Unified sigil spend effects (active boost already exists).
    private const SPEND_EFFECTS_BY_TIER = [
        1 => ['power_fp' => 50000,   'time_seconds' => 30 * 60],
        2 => ['power_fp' => 100000,  'time_seconds' => 60 * 60],
        3 => ['power_fp' => 250000,  'time_seconds' => 3 * 60 * 60],
        4 => ['power_fp' => 500000,  'time_seconds' => 6 * 60 * 60],
        5 => ['power_fp' => 1000000, 'time_seconds' => 12 * 60 * 60],
    ];

    // Initial values when no boost is active.
    private const INITIAL_EFFECTS_BY_TIER = [
        1 => ['power_fp' => 50000,   'duration_seconds' => 24 * 60 * 60],
        2 => ['power_fp' => 100000,  'duration_seconds' => 12 * 60 * 60],
        3 => ['power_fp' => 250000,  'duration_seconds' => 6 * 60 * 60],
        4 => ['power_fp' => 500000,  'duration_seconds' => 3 * 60 * 60],
        5 => ['power_fp' => 1000000, 'duration_seconds' => 60 * 60],
    ];

    /**
     * Canonical boost definitions keyed by tier.
     * Durations are expressed in real seconds, then converted to ticks via
     * ticks_from_real_seconds() so behavior remains correct if tick cadence changes.
     *
     * time_extension_seconds is the flat amount added when a player purchases a time
     * extension from the Boost Catalog. It is the EXACT amount shown on the purchase
     * button and must NOT be multiplied by the player's current power stack.
     *   Tier 1 (Trickle): +30 min
     *   Tier 2 (Surge):   +60 min
     *   Tier 3 (Flow):    +180 min
     *   Tier 4 (Tide):    +360 min
     *   Tier 5 (Age):     +720 min
     */
    private const DEFINITIONS = [
        1 => [
            'name' => 'Boost',
            'scope' => 'SELF',
            'duration_seconds' => 24 * 60 * 60,
            'time_extension_seconds' => 30 * 60,
            'modifier_fp' => 50000,
            'max_stack' => 20,
            'icon' => 'boost',
            'sigil_cost' => 1,
            'vault_price_discount_fp' => 0,
            'vault_stock_leverage_fp' => 1000000,
        ],
        2 => [
            'name' => 'Boost',
            'scope' => 'SELF',
            'duration_seconds' => 12 * 60 * 60,
            'time_extension_seconds' => 60 * 60,
            'modifier_fp' => 100000,
            'max_stack' => 10,
            'icon' => 'boost',
            'sigil_cost' => 1,
            'vault_price_discount_fp' => 50000,
            'vault_stock_leverage_fp' => 1100000,
        ],
        3 => [
            'name' => 'Boost',
            'scope' => 'SELF',
            'duration_seconds' => 6 * 60 * 60,
            'time_extension_seconds' => 180 * 60,
            'modifier_fp' => 250000,
            'max_stack' => 4,
            'icon' => 'boost',
            'sigil_cost' => 1,
            'vault_price_discount_fp' => 100000,
            'vault_stock_leverage_fp' => 1250000,
        ],
        4 => [
            'name' => 'Boost',
            'scope' => 'SELF',
            'duration_seconds' => 3 * 60 * 60,
            'time_extension_seconds' => 360 * 60,
            'modifier_fp' => 500000,
            'max_stack' => 2,
            'icon' => 'boost',
            'sigil_cost' => 1,
            'vault_price_discount_fp' => 150000,
            'vault_stock_leverage_fp' => 1400000,
        ],
        5 => [
            'name' => 'Boost',
            'scope' => 'SELF',
            'duration_seconds' => 1 * 60 * 60,
            'time_extension_seconds' => 720 * 60,
            'modifier_fp' => 1000000,
            'max_stack' => 1,
            'icon' => 'boost',
            'sigil_cost' => 1,
            'vault_price_discount_fp' => 200000,
            'vault_stock_leverage_fp' => 1600000,
        ],
    ];

    public static function hasTier(int $tier): bool
    {
        return isset(self::DEFINITIONS[$tier]);
    }

    public static function canSpendSigilTier(int $tier): bool
    {
        return isset(self::SPEND_EFFECTS_BY_TIER[$tier]);
    }

    public static function getSpendPowerFpForTier(int $tier): int
    {
        if (!isset(self::SPEND_EFFECTS_BY_TIER[$tier])) {
            return 0;
        }
        return (int)self::SPEND_EFFECTS_BY_TIER[$tier]['power_fp'];
    }

    public static function getSpendTimeTicksForTier(int $tier): int
    {
        if (!isset(self::SPEND_EFFECTS_BY_TIER[$tier])) {
            return 0;
        }
        return ticks_from_real_seconds((int)self::SPEND_EFFECTS_BY_TIER[$tier]['time_seconds']);
    }

    public static function getSpendTimeRealSecondsForTier(int $tier): int
    {
        if (!isset(self::SPEND_EFFECTS_BY_TIER[$tier])) {
            return 0;
        }
        return (int)self::SPEND_EFFECTS_BY_TIER[$tier]['time_seconds'];
    }

    public static function getInitialPowerFpForTier(int $tier): int
    {
        if (!isset(self::INITIAL_EFFECTS_BY_TIER[$tier])) {
            return 0;
        }
        return (int)self::INITIAL_EFFECTS_BY_TIER[$tier]['power_fp'];
    }

    public static function getInitialDurationTicksForTier(int $tier): int
    {
        if (!isset(self::INITIAL_EFFECTS_BY_TIER[$tier])) {
            return 0;
        }
        return ticks_from_real_seconds((int)self::INITIAL_EFFECTS_BY_TIER[$tier]['duration_seconds']);
    }

    public static function getInitialDurationRealSecondsForTier(int $tier): int
    {
        if (!isset(self::INITIAL_EFFECTS_BY_TIER[$tier])) {
            return 0;
        }
        return (int)self::INITIAL_EFFECTS_BY_TIER[$tier]['duration_seconds'];
    }

    public static function getTimeExtensionTicksForTier(int $tier): int
    {
        if (!isset(self::DEFINITIONS[$tier])) {
            return 0;
        }
        return ticks_from_real_seconds((int)self::DEFINITIONS[$tier]['time_extension_seconds']);
    }

    public static function getTimeExtensionRealSecondsForTier(int $tier): int
    {
        if (!isset(self::DEFINITIONS[$tier])) {
            return 0;
        }
        return (int)self::DEFINITIONS[$tier]['time_extension_seconds'];
    }

    public static function normalize(array $boost): array
    {
        $tier = (int)($boost['tier_required'] ?? 0);
        if (!isset(self::DEFINITIONS[$tier])) {
            return $boost;
        }

        $canonical = self::DEFINITIONS[$tier];
        $boost['name'] = $canonical['name'];
        $boost['description'] = '';
        $boost['scope'] = $canonical['scope'];
        $boost['duration_real_seconds'] = (int)$canonical['duration_seconds'];
        $boost['time_extension_real_seconds'] = (int)$canonical['time_extension_seconds'];
        $boost['duration_ticks'] = ticks_from_real_seconds($canonical['duration_seconds']);
        $boost['time_extension_ticks'] = ticks_from_real_seconds($canonical['time_extension_seconds']);
        $boost['base_modifier_fp'] = $canonical['modifier_fp'];

        // Preserve runtime modifier value from active_boosts rows.
        if (array_key_exists('modifier_fp', $boost)) {
            $boost['modifier_fp'] = (int)$boost['modifier_fp'];
        } else {
            $boost['modifier_fp'] = $canonical['modifier_fp'];
        }

        $boost['max_stack'] = $canonical['max_stack'];
        $boost['icon'] = $canonical['icon'];
        $boost['sigil_cost'] = $canonical['sigil_cost'];
        $boost['vault_price_discount_fp'] = (int)$canonical['vault_price_discount_fp'];
        $boost['vault_stock_leverage_fp'] = (int)$canonical['vault_stock_leverage_fp'];
        $boost['power_cap_fp'] = self::POWER_CAP_FP_PER_PRODUCT;
        $boost['total_power_cap_fp'] = self::TOTAL_POWER_CAP_FP;
        $boost['time_cap_ticks'] = ticks_from_real_seconds(self::TIME_CAP_SECONDS_PER_PRODUCT);
        $boost['current_stack'] = max(0, min(
            (int)$boost['max_stack'],
            (int)ceil(max(0, (int)$boost['modifier_fp']) / max(1, (int)$boost['base_modifier_fp']))
        ));

        return $boost;
    }
}
