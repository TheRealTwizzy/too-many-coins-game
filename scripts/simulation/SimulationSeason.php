<?php

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/game_time.php';

class SimulationSeason
{
    public const SCHEMA_VERSION = 'tmc-sim-phase1.v1';

    public const SEASON_ECONOMY_COLUMNS = [
        'season_id',
        'start_time',
        'end_time',
        'blackout_time',
        'season_seed',
        'status',
        'season_expired',
        'expiration_finalized',
        'base_ubi_active_per_tick',
        'base_ubi_idle_factor_fp',
        'ubi_min_per_tick',
        'inflation_table',
        'hoarding_window_ticks',
        'target_spend_rate_per_tick',
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
        'starprice_table',
        'star_price_cap',
        'starprice_idle_weight_fp',
        'starprice_active_only',
        'starprice_max_upstep_fp',
        'starprice_max_downstep_fp',
        'starprice_model_version',
        'starprice_reactivation_window_ticks',
        'starprice_demand_table',
        'market_affordability_bias_fp',
        'star_price_minimum_absolute',
        'vault_config',
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

    public static function build(int $seasonId = 1, string $seed = 'phase1', array $overrides = []): array
    {
        $startTime = GameTime::seasonStartTime($seasonId);
        $endTime = GameTime::seasonEndTime($startTime);
        $blackoutTime = GameTime::blackoutStartTime($endTime);

        $inflationTable = json_encode([
            ['x' => 0, 'factor_fp' => 1000000],
            ['x' => 50000, 'factor_fp' => 620000],
            ['x' => 200000, 'factor_fp' => 280000],
            ['x' => 800000, 'factor_fp' => 110000],
            ['x' => 3000000, 'factor_fp' => 50000],
        ], JSON_UNESCAPED_SLASHES);

        $starPriceTable = json_encode([
            ['m' => 0, 'price' => 100],
            ['m' => 25000, 'price' => 220],
            ['m' => 100000, 'price' => 520],
            ['m' => 500000, 'price' => 1600],
            ['m' => 2000000, 'price' => 4200],
        ], JSON_UNESCAPED_SLASHES);

        $starPriceDemandTable = json_encode([
            ['ratio_fp' => 850000, 'multiplier_fp' => 900000],
            ['ratio_fp' => 1000000, 'multiplier_fp' => 1000000],
            ['ratio_fp' => 1150000, 'multiplier_fp' => 1080000],
            ['ratio_fp' => 1300000, 'multiplier_fp' => 1120000],
        ], JSON_UNESCAPED_SLASHES);

        $vaultConfig = json_encode([
            ['tier' => 1, 'supply' => 500, 'cost_table' => [['remaining' => 1, 'cost' => 50]]],
            ['tier' => 2, 'supply' => 250, 'cost_table' => [['remaining' => 1, 'cost' => 250]]],
            ['tier' => 3, 'supply' => 125, 'cost_table' => [['remaining' => 1, 'cost' => 1000]]],
        ], JSON_UNESCAPED_SLASHES);

        $season = [
            'season_id' => $seasonId,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'blackout_time' => $blackoutTime,
            'season_seed' => hash('sha256', $seed . '|season|' . $seasonId, true),
            'status' => 'Scheduled',
            'season_expired' => 0,
            'expiration_finalized' => 0,
            'base_ubi_active_per_tick' => 30,
            'base_ubi_idle_factor_fp' => 250000,
            'ubi_min_per_tick' => 1,
            'inflation_table' => $inflationTable,
            'hoarding_window_ticks' => ticks_from_real_seconds(86400),
            'target_spend_rate_per_tick' => 50,
            'hoarding_min_factor_fp' => 100000,
            'hoarding_sink_enabled' => 1,
            'hoarding_safe_hours' => 12,
            'hoarding_safe_min_coins' => 20000,
            'hoarding_tier1_excess_cap' => 50000,
            'hoarding_tier2_excess_cap' => 200000,
            'hoarding_tier1_rate_hourly_fp' => 200,
            'hoarding_tier2_rate_hourly_fp' => 500,
            'hoarding_tier3_rate_hourly_fp' => 1000,
            'hoarding_sink_cap_ratio_fp' => 350000,
            'hoarding_idle_multiplier_fp' => 1250000,
            'starprice_table' => $starPriceTable,
            'star_price_cap' => 10000,
            'starprice_idle_weight_fp' => 250000,
            'starprice_active_only' => 0,
            'starprice_max_upstep_fp' => 2000,
            'starprice_max_downstep_fp' => 10000,
            'starprice_model_version' => 1,
            'starprice_reactivation_window_ticks' => 75,
            'starprice_demand_table' => $starPriceDemandTable,
            'market_affordability_bias_fp' => 970000,
            'star_price_minimum_absolute' => 1,
            'vault_config' => $vaultConfig,
            'current_star_price' => 100,
            'market_anchor_price' => 100,
            'blackout_star_price_snapshot' => null,
            'blackout_started_tick' => null,
            'pending_star_burn_coins' => 0,
            'star_burn_ema_fp' => 0,
            'net_mint_ema_fp' => 0,
            'market_pressure_fp' => 1000000,
            'total_coins_supply' => 0,
            'total_coins_supply_end_of_tick' => 0,
            'coins_active_total' => 0,
            'coins_idle_total' => 0,
            'coins_offline_total' => 0,
            'effective_price_supply' => 0,
            'last_processed_tick' => $startTime,
        ];

        $season = array_replace($season, self::normalizeImportedRow($overrides));
        self::assertArrayShape($season);
        return $season;
    }

    public static function fromJsonFile(string $path, int $fallbackSeasonId = 1, string $fallbackSeed = 'phase1'): array
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException('Season config file not found: ' . $path);
        }

        $decoded = json_decode((string)file_get_contents($path), true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Season config JSON must decode to an object');
        }

        return self::build($fallbackSeasonId, $fallbackSeed, $decoded);
    }

    public static function normalizeImportedRow(array $row): array
    {
        if (isset($row['season_seed_hex']) && !isset($row['season_seed'])) {
            $decoded = @hex2bin((string)$row['season_seed_hex']);
            if ($decoded !== false) {
                $row['season_seed'] = $decoded;
            }
        }

        if (isset($row['season_seed']) && is_string($row['season_seed']) && strlen($row['season_seed']) === 64 && ctype_xdigit($row['season_seed'])) {
            $decoded = @hex2bin($row['season_seed']);
            if ($decoded !== false) {
                $row['season_seed'] = $decoded;
            }
        }

        return $row;
    }

    public static function assertArrayShape(array $season): void
    {
        foreach (self::SEASON_ECONOMY_COLUMNS as $column) {
            if (!array_key_exists($column, $season)) {
                throw new InvalidArgumentException('Missing season key: ' . $column);
            }
            if ($column !== 'blackout_star_price_snapshot' && $column !== 'blackout_started_tick' && $season[$column] === null) {
                throw new InvalidArgumentException('Null season key: ' . $column);
            }
        }
    }

    public static function updateComputedStatus(array &$season, int $tick): string
    {
        $status = GameTime::getSeasonStatus($season, $tick);
        $season['status'] = $status;
        $season['computed_status'] = $status;
        return $status;
    }
}
