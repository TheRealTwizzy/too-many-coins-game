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
        if (!is_array($data) || ($data['schema_version'] ?? '') !== 'tmc-tuning-candidates.v1') {
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
