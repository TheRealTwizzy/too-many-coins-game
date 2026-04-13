<?php

require_once __DIR__ . '/SimulationSeason.php';

class PolicyScenarioCatalog
{
    public const SCENARIO_SCHEMA_VERSION = 'tmc-sim-scenarios.v1';

    /** @var array Extra scenarios registered at runtime (e.g. from tuning candidates). */
    private static array $extraScenarios = [];

    /**
     * Register additional scenarios at runtime so get() and all() include them.
     */
    public static function registerExtra(array $scenarios): void
    {
        foreach ($scenarios as $name => $scenario) {
            self::assertValidScenario($scenario);
            self::$extraScenarios[$name] = $scenario;
        }
    }

    private const CATEGORY_KEY_ALLOWLIST = [
        'star_conversion_pricing' => [
            'starprice_table',
            'star_price_cap',
            'starprice_idle_weight_fp',
            'starprice_active_only',
            'starprice_max_upstep_fp',
            'starprice_max_downstep_fp',
            'starprice_reactivation_window_ticks',
            'starprice_demand_table',
            'market_affordability_bias_fp',
        ],
        'boost_related' => [
            'target_spend_rate_per_tick',
            'base_ubi_active_per_tick',
            'base_ubi_idle_factor_fp',
            'ubi_min_per_tick',
            'inflation_table',
        ],
        'sigil_drop_tier_combine' => [
            'vault_config',
        ],
        'lock_in_expiry_incentives' => [
            'market_affordability_bias_fp',
            'starprice_reactivation_window_ticks',
            'target_spend_rate_per_tick',
            'hoarding_min_factor_fp',
        ],
        'hoarding_preservation_pressure' => [
            'hoarding_window_ticks',
            'hoarding_min_factor_fp',
            'hoarding_sink_enabled',
            'hoarding_safe_hours',
            'hoarding_safe_min_coins',
            'hoarding_tier1_excess_cap',
            'hoarding_tier2_excess_cap',
            'hoarding_tier1_rate_hourly_fp',
            'hoarding_tier2_rate_hourly_fp',
            'hoarding_tier3_rate_hourly_fp',
            'hoarding_sink_cap_ratio_fp',
            'hoarding_idle_multiplier_fp',
        ],
        'phase_timing' => [
            'hoarding_window_ticks',
            'starprice_reactivation_window_ticks',
        ],
    ];

    private const DISALLOWED_KEYS = [
        'season_id',
        'start_time',
        'end_time',
        'blackout_time',
        'season_seed',
        'status',
        'season_expired',
        'expiration_finalized',
        'current_star_price',
        'market_anchor_price',
        'blackout_star_price_snapshot',
        'blackout_started_tick',
        'pending_star_burn_coins',
        'star_burn_ema_fp',
        'net_mint_ema_fp',
        'market_pressure_fp',
        'total_coins_supply',
        'total_coins_supply_end_of_tick',
        'coins_active_total',
        'coins_idle_total',
        'coins_offline_total',
        'effective_price_supply',
        'last_processed_tick',
    ];

    public static function all(): array
    {
        $scenarios = [
            [
                'name' => 'mostly-idle-pressure-v1',
                'description' => 'Reduce idle-price leverage and idle hoarding protection to pressure Mostly Idle long-run conversions.',
                'categories' => ['star_conversion_pricing', 'hoarding_preservation_pressure'],
                'overrides' => [
                    'starprice_idle_weight_fp' => 100000,
                    'hoarding_idle_multiplier_fp' => 1050000,
                    'hoarding_safe_min_coins' => 15000,
                ],
            ],
            [
                'name' => 'star-focused-friction-v1',
                'description' => 'Raise star-conversion friction for persistent star deployment paths.',
                'categories' => ['star_conversion_pricing', 'lock_in_expiry_incentives'],
                'overrides' => [
                    'market_affordability_bias_fp' => 1180000,
                    'starprice_reactivation_window_ticks' => 140,
                    'starprice_max_upstep_fp' => 1500,
                ],
            ],
            [
                'name' => 'boost-payoff-relief-v1',
                'description' => 'Lightly ease spend pressure so boost-heavy paths can convert value more reliably.',
                'categories' => ['boost_related', 'lock_in_expiry_incentives', 'hoarding_preservation_pressure'],
                'overrides' => [
                    'target_spend_rate_per_tick' => 38,
                    'hoarding_min_factor_fp' => 180000,
                    'hoarding_safe_hours' => 8,
                ],
            ],
            [
                'name' => 'hoarder-pressure-v1',
                'description' => 'Increase excess-holding pressure to reduce Hoarder over-conversion over long horizons.',
                'categories' => ['hoarding_preservation_pressure', 'lock_in_expiry_incentives'],
                'overrides' => [
                    'hoarding_tier1_rate_hourly_fp' => 350,
                    'hoarding_tier2_rate_hourly_fp' => 850,
                    'hoarding_tier3_rate_hourly_fp' => 1500,
                    'hoarding_sink_cap_ratio_fp' => 500000,
                ],
            ],
            [
                'name' => 'hoarding-sink-minimal-v1',
                'description' => 'Enable hoarding sink (previously disabled in live config) with unchanged rates and extended reactivation window to isolate the sink-enable effect on hoarder dominance.',
                'categories' => ['hoarding_preservation_pressure', 'lock_in_expiry_incentives'],
                'overrides' => [
                    'hoarding_sink_enabled' => 1,
                    'starprice_reactivation_window_ticks' => 100,
                    'market_affordability_bias_fp' => 980000,
                ],
            ],
            [
                'name' => 'hoarding-sink-conservative-v1',
                'description' => 'Enable hoarding sink with conservative tier2/tier3 rate increases and a strong lock-in counterweight to prevent expiry regression.',
                'categories' => ['hoarding_preservation_pressure', 'lock_in_expiry_incentives'],
                'overrides' => [
                    'hoarding_sink_enabled' => 1,
                    'hoarding_tier2_rate_hourly_fp' => 520,
                    'hoarding_tier3_rate_hourly_fp' => 1050,
                    'starprice_reactivation_window_ticks' => 100,
                    'market_affordability_bias_fp' => 960000,
                ],
            ],
            [
                'name' => 'active-ubi-buff-v1',
                'description' => 'Increase active-play UBI and reduce idle UBI factor to improve Hardcore and Boost-Focused archetype viability without touching hoarding or star pricing.',
                'categories' => ['boost_related'],
                'overrides' => [
                    'base_ubi_active_per_tick' => 42,
                    'base_ubi_idle_factor_fp' => 200000,
                ],
            ],
            [
                'name' => 'combined-sink-ubi-v1',
                'description' => 'Enable hoarding sink with conservative rates AND a moderate active-UBI buff so multiple archetypes become more competitive, preventing Star-Focused from concentrating advantage.',
                'categories' => ['hoarding_preservation_pressure', 'lock_in_expiry_incentives', 'boost_related'],
                'overrides' => [
                    'hoarding_sink_enabled' => 1,
                    'hoarding_tier2_rate_hourly_fp' => 520,
                    'hoarding_tier3_rate_hourly_fp' => 1050,
                    'starprice_reactivation_window_ticks' => 100,
                    'market_affordability_bias_fp' => 970000,
                    'base_ubi_active_per_tick' => 38,
                ],
            ],
            [
                'name' => 'hoarding-ubi-squeeze-v1',
                'description' => 'Suppress hoarder UBI accumulation (not existing coin balance) by raising spend target and lowering min UBI factor. Avoids lock-in regression by not draining coin reserves.',
                'categories' => ['lock_in_expiry_incentives', 'boost_related'],
                'overrides' => [
                    'target_spend_rate_per_tick' => 22,
                    'hoarding_min_factor_fp' => 75000,
                    'base_ubi_active_per_tick' => 36,
                    'base_ubi_idle_factor_fp' => 220000,
                ],
            ],
            [
                'name' => 'hoarding-sink-ultra-gentle-v1',
                'description' => 'Enable hoarding sink at half the current rates (100/250/500 fp/hour) with extended reactivation window. Minimal drain may not trigger lock-in regression while still pressuring excess hoarding.',
                'categories' => ['hoarding_preservation_pressure', 'lock_in_expiry_incentives'],
                'overrides' => [
                    'hoarding_sink_enabled' => 1,
                    'hoarding_tier1_rate_hourly_fp' => 100,
                    'hoarding_tier2_rate_hourly_fp' => 250,
                    'hoarding_tier3_rate_hourly_fp' => 500,
                    'starprice_reactivation_window_ticks' => 120,
                    'market_affordability_bias_fp' => 975000,
                ],
            ],
            [
                'name' => 'inflation-tighten-v1',
                'description' => 'Tighten inflation table at mid-to-high supply to reduce hoarder UBI via market pressure (not coin drain). Derived from agentic v4 tier3 candidate. No lock-in or skip-rejoin coupling.',
                'categories' => ['boost_related'],
                'overrides' => [
                    'inflation_table' => '[{"x": 0, "factor_fp": 1000000}, {"x": 50000, "factor_fp": 620000}, {"x": 200000, "factor_fp": 280000}, {"x": 800000, "factor_fp": 110000}, {"x": 3000000, "factor_fp": 50000}]',
                ],
            ],
            [
                'name' => 'inflation-tighten-plus-ubi-v1',
                'description' => 'Tighten inflation table AND buff active UBI to simultaneously reduce hoarder advantage and improve Hardcore/Boost-Focused viability.',
                'categories' => ['boost_related'],
                'overrides' => [
                    'inflation_table' => '[{"x": 0, "factor_fp": 1000000}, {"x": 50000, "factor_fp": 620000}, {"x": 200000, "factor_fp": 280000}, {"x": 800000, "factor_fp": 110000}, {"x": 3000000, "factor_fp": 50000}]',
                    'base_ubi_active_per_tick' => 36,
                    'base_ubi_idle_factor_fp' => 220000,
                ],
            ],
        ];

        $indexed = [];
        foreach ($scenarios as $scenario) {
            self::assertValidScenario($scenario);
            $indexed[$scenario['name']] = $scenario;
        }

        return array_merge($indexed, self::$extraScenarios);
    }

    public static function get(string $name): array
    {
        $all = self::all();
        if (!isset($all[$name])) {
            throw new InvalidArgumentException('Unknown scenario name: ' . $name);
        }

        return $all[$name];
    }

    public static function baselineScenario(): array
    {
        return [
            'name' => 'baseline',
            'description' => 'No simulation override values applied.',
            'categories' => [],
            'overrides' => [],
        ];
    }

    public static function categoryAllowlist(): array
    {
        return self::CATEGORY_KEY_ALLOWLIST;
    }

    /**
     * Load tuning scenarios from a Phase C tuning_candidates.json file.
     * Returns indexed array of validated scenario entries.
     *
     * @param string $candidatesJsonPath Path to tuning_candidates.json
     * @return array Indexed by scenario name
     */
    public static function loadTuningScenarios(string $candidatesJsonPath): array
    {
        if (!is_file($candidatesJsonPath)) {
            throw new InvalidArgumentException('Tuning candidates file not found: ' . $candidatesJsonPath);
        }

        $data = json_decode(file_get_contents($candidatesJsonPath), true);
        $schemaVersion = $data['schema_version'] ?? '';
        if (!is_array($data) || !str_starts_with($schemaVersion, 'tmc-tuning-candidates.v')) {
            throw new InvalidArgumentException('Invalid tuning candidates format: ' . $candidatesJsonPath);
        }

        $scenarios = [];
        foreach (($data['scenarios'] ?? []) as $scenario) {
            if (!is_array($scenario) || empty($scenario['name'])) {
                continue;
            }
            self::assertValidScenario($scenario);
            $scenarios[$scenario['name']] = $scenario;
        }

        return $scenarios;
    }

    /**
     * Return all built-in scenarios merged with tuning scenarios from a candidates file.
     * Phase D sweep runners should use this when tuning scenarios are available.
     *
     * @param string $candidatesJsonPath Path to tuning_candidates.json
     * @return array Indexed by scenario name
     */
    public static function allWithTuning(string $candidatesJsonPath): array
    {
        $all = self::all();
        $tuning = self::loadTuningScenarios($candidatesJsonPath);
        return array_merge($all, $tuning);
    }

    public static function normalizeScenarioNames(array $names): array
    {
        $normalized = [];
        foreach ($names as $name) {
            $trimmed = trim((string)$name);
            if ($trimmed === '') {
                continue;
            }
            $normalized[$trimmed] = true;
        }

        return array_keys($normalized);
    }

    private static function assertValidScenario(array $scenario): void
    {
        $name = (string)($scenario['name'] ?? '');
        if ($name === '') {
            throw new InvalidArgumentException('Scenario name is required');
        }

        $categories = (array)($scenario['categories'] ?? []);
        if ($categories === []) {
            throw new InvalidArgumentException('Scenario must include at least one category: ' . $name);
        }

        $overrides = (array)($scenario['overrides'] ?? []);
        if ($overrides === []) {
            throw new InvalidArgumentException('Scenario must include overrides: ' . $name);
        }

        $allowedSeasonKeys = array_flip(SimulationSeason::SEASON_ECONOMY_COLUMNS);
        $categoryAllowlist = self::CATEGORY_KEY_ALLOWLIST;

        $categoryKeys = [];
        foreach ($categories as $category) {
            $categoryName = (string)$category;
            if (!isset($categoryAllowlist[$categoryName])) {
                throw new InvalidArgumentException('Unknown scenario category: ' . $categoryName);
            }
            foreach ($categoryAllowlist[$categoryName] as $key) {
                $categoryKeys[$key] = true;
            }
        }

        foreach ($overrides as $key => $_value) {
            if (in_array($key, self::DISALLOWED_KEYS, true)) {
                throw new InvalidArgumentException('Override key disallowed for policy sweep: ' . $key);
            }
            if (!isset($allowedSeasonKeys[$key])) {
                throw new InvalidArgumentException('Override key not in shared season schema: ' . $key);
            }
            if (!isset($categoryKeys[$key])) {
                throw new InvalidArgumentException('Override key is not allowed by scenario categories: ' . $key);
            }
        }
    }
}
