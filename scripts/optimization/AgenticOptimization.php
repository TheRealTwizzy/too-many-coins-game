<?php

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../simulation/SimulationSeason.php';
require_once __DIR__ . '/../simulation/SeasonConfigExporter.php';
require_once __DIR__ . '/../simulation/CanonicalEconomyConfigContract.php';
require_once __DIR__ . '/../simulation/SimulationPopulationSeason.php';
require_once __DIR__ . '/../simulation/SimulationPopulationLifetime.php';
require_once __DIR__ . '/../simulation/MetricsCollector.php';
require_once __DIR__ . '/RejectedIterationInputResolver.php';

class AgenticOptimizationUtils
{
    public static function ensureDir(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
    }

    public static function sanitize(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '_', $value);
    }

    public static function writeJson(string $path, array $payload): void
    {
        self::ensureDir(dirname($path));
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public static function jsonHash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    public static function percentile(array $values, float $percentile): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $count = count($values);
        $index = (int)floor(max(0.0, min(1.0, $percentile)) * ($count - 1));
        return (float)$values[$index];
    }

    public static function median(array $values): float
    {
        return self::percentile($values, 0.5);
    }

    public static function mean(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        return array_sum($values) / count($values);
    }

    public static function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }

    public static function entropyNormalized(array $counts): float
    {
        $total = 0.0;
        foreach ($counts as $count) {
            $total += max(0.0, (float)$count);
        }
        if ($total <= 0.0) {
            return 0.0;
        }

        $parts = [];
        foreach ($counts as $count) {
            $c = max(0.0, (float)$count);
            if ($c > 0.0) {
                $parts[] = $c / $total;
            }
        }

        if (count($parts) <= 1) {
            return 0.0;
        }

        $entropy = 0.0;
        foreach ($parts as $p) {
            $entropy -= $p * log($p);
        }

        $maxEntropy = log((float)count($parts));
        if ($maxEntropy <= 0.0) {
            return 0.0;
        }
        return $entropy / $maxEntropy;
    }

    public static function convertSeasonForJson(array $season): array
    {
        $normalized = $season;
        if (isset($normalized['season_seed']) && is_string($normalized['season_seed'])) {
            // Preserve deterministic season seed as hex for safe JSON round-tripping.
            $normalized['season_seed_hex'] = bin2hex($normalized['season_seed']);
            unset($normalized['season_seed']);
        }
        return $normalized;
    }

    public static function sortAssocRecursively(array &$value): void
    {
        foreach ($value as &$child) {
            if (is_array($child)) {
                self::sortAssocRecursively($child);
            }
        }
        unset($child);

        if (self::isAssoc($value)) {
            ksort($value);
        }
    }

    public static function isAssoc(array $array): bool
    {
        if ($array === []) {
            return false;
        }
        return array_keys($array) !== range(0, count($array) - 1);
    }
}

class AgenticBaselineConfigLoader
{
    public static function load(?string $seasonConfigPath = null): array
    {
        $errors = [];

        try {
            $db = Database::getInstance();
            $row = $db->fetch(
                "SELECT * FROM seasons WHERE status IN ('Active', 'Blackout') ORDER BY season_id DESC LIMIT 1"
            );
            if (!$row) {
                $row = $db->fetch('SELECT * FROM seasons ORDER BY season_id DESC LIMIT 1');
            }
            if (!$row) {
                throw new RuntimeException('No season rows found in DB.');
            }

            $raw = SimulationSeason::normalizeImportedRow($row);
            $seasonId = (int)($raw['season_id'] ?? 1);
            $season = SimulationSeason::build($seasonId, 'agentic-db-baseline', $raw);

            $hashInput = AgenticOptimizationUtils::convertSeasonForJson($season);
            AgenticOptimizationUtils::sortAssocRecursively($hashInput);

            return [
                'season' => $season,
                'provenance' => [
                    'source' => 'database',
                    'resolved_at' => gmdate('c'),
                    'db_host' => DB_HOST,
                    'db_port' => DB_PORT,
                    'db_name' => DB_NAME,
                    'season_id' => $seasonId,
                    'surface_sha256' => AgenticOptimizationUtils::jsonHash($hashInput),
                ],
            ];
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }

        if ($seasonConfigPath !== null && $seasonConfigPath !== '') {
            if (!is_file($seasonConfigPath)) {
                throw new RuntimeException(
                    'Failed to resolve baseline from DB and season-config fallback file does not exist: '
                    . $seasonConfigPath
                    . '; DB error(s): ' . implode(' | ', $errors)
                );
            }

            $decoded = json_decode((string)file_get_contents($seasonConfigPath), true);
            if (!is_array($decoded)) {
                throw new RuntimeException('Season config fallback JSON must decode to an object: ' . $seasonConfigPath);
            }

            $normalized = SimulationSeason::normalizeImportedRow($decoded);
            $filtered = [];
            foreach (SimulationSeason::SEASON_ECONOMY_COLUMNS as $column) {
                if (array_key_exists($column, $normalized)) {
                    $filtered[$column] = $normalized[$column];
                }
            }

            $season = SimulationSeason::build(1, 'agentic-file-baseline', $filtered);
            $hashInput = AgenticOptimizationUtils::convertSeasonForJson($season);
            AgenticOptimizationUtils::sortAssocRecursively($hashInput);

            return [
                'season' => $season,
                'provenance' => [
                    'source' => 'season-config-file',
                    'resolved_at' => gmdate('c'),
                    'path' => $seasonConfigPath,
                    'surface_sha256' => AgenticOptimizationUtils::jsonHash($hashInput),
                    'db_errors' => $errors,
                ],
            ];
        }

        throw new RuntimeException('Failed to resolve baseline from DB and no season-config fallback provided. DB error(s): ' . implode(' | ', $errors));
    }
}

class AgenticEconomyDecomposition
{
    public static function build(array $baselineSeason = []): array
    {
        $profiles = [
            'tier1_hoarding' => [
                'id' => 'tier1_hoarding',
                'tier' => 'tier1',
                'description' => 'Cheap local screening for anti-safe-strategy pressure.',
                'simulators' => ['B', 'C'],
                'players_per_archetype' => 2,
                'season_count' => 4,
                'season_duration_ticks' => 1440,
                'blackout_duration_ticks' => 360,
                'archetype_keys' => ['hoarder', 'mostly_idle', 'regular', 'hardcore'],
                'seeds' => ['tier1-hoarding-a'],
            ],
            'tier1_blackout' => [
                'id' => 'tier1_blackout',
                'tier' => 'tier1',
                'description' => 'Blackout and lock-in pressure micro-harness.',
                'simulators' => ['B', 'C'],
                'players_per_archetype' => 2,
                'season_count' => 4,
                'season_duration_ticks' => 1440,
                'blackout_duration_ticks' => 480,
                'archetype_keys' => ['early_locker', 'late_deployer', 'aggressive_sigil_user', 'hardcore', 'regular'],
                'seeds' => ['tier1-blackout-a'],
            ],
            'tier1_boost' => [
                'id' => 'tier1_boost',
                'tier' => 'tier1',
                'description' => 'Boost-focused local screening with reduced cohorts.',
                'simulators' => ['B', 'C'],
                'players_per_archetype' => 2,
                'season_count' => 4,
                'season_duration_ticks' => 1440,
                'blackout_duration_ticks' => 360,
                'archetype_keys' => ['boost_focused', 'hardcore', 'regular', 'casual'],
                'seeds' => ['tier1-boost-a'],
            ],
            'tier1_concentration' => [
                'id' => 'tier1_concentration',
                'tier' => 'tier1',
                'description' => 'Short lifecycle concentration stress harness.',
                'simulators' => ['B', 'C'],
                'players_per_archetype' => 2,
                'season_count' => 5,
                'season_duration_ticks' => 1800,
                'blackout_duration_ticks' => 450,
                'archetype_keys' => [],
                'seeds' => ['tier1-concentration-a'],
            ],
            'tier1_sigil' => [
                'id' => 'tier1_sigil',
                'tier' => 'tier1',
                'description' => 'Sigil acquisition/combine/theft local harness.',
                'simulators' => ['B'],
                'players_per_archetype' => 2,
                'season_count' => 1,
                'season_duration_ticks' => 1200,
                'blackout_duration_ticks' => 300,
                'archetype_keys' => ['aggressive_sigil_user', 'regular', 'hardcore', 'casual'],
                'seeds' => ['tier1-sigil-a'],
            ],
            'tier1_onboarding' => [
                'id' => 'tier1_onboarding',
                'tier' => 'tier1',
                'description' => 'Onboarding-only phase-limited harness.',
                'simulators' => ['B'],
                'players_per_archetype' => 2,
                'season_count' => 1,
                'season_duration_ticks' => 960,
                'blackout_duration_ticks' => 240,
                'archetype_keys' => ['casual', 'regular', 'mostly_idle', 'early_locker'],
                'phase_stop' => 'EARLY',
                'seeds' => ['tier1-onboarding-a'],
            ],
            'tier1_star_pricing' => [
                'id' => 'tier1_star_pricing',
                'tier' => 'tier1',
                'description' => 'Star market and purchasing local harness.',
                'simulators' => ['B'],
                'players_per_archetype' => 2,
                'season_count' => 1,
                'season_duration_ticks' => 1200,
                'blackout_duration_ticks' => 300,
                'archetype_keys' => ['star_focused', 'regular', 'casual', 'late_deployer'],
                'seeds' => ['tier1-star-pricing-a'],
            ],
            'tier1_expiry' => [
                'id' => 'tier1_expiry',
                'tier' => 'tier1',
                'description' => 'Expiry pressure and lock-in gradient harness.',
                'simulators' => ['B', 'C'],
                'players_per_archetype' => 2,
                'season_count' => 4,
                'season_duration_ticks' => 1440,
                'blackout_duration_ticks' => 420,
                'archetype_keys' => [],
                'seeds' => ['tier1-expiry-a'],
            ],
            'tier1_lockin' => [
                'id' => 'tier1_lockin',
                'tier' => 'tier1',
                'description' => 'Lock-in incentive and timing harness.',
                'simulators' => ['B'],
                'players_per_archetype' => 2,
                'season_count' => 1,
                'season_duration_ticks' => 1200,
                'blackout_duration_ticks' => 360,
                'archetype_keys' => ['early_locker', 'late_deployer', 'regular', 'casual'],
                'seeds' => ['tier1-lockin-a'],
            ],
            'tier1_retention' => [
                'id' => 'tier1_retention',
                'tier' => 'tier1',
                'description' => 'Repeated-season retention proxy harness.',
                'simulators' => ['C'],
                'players_per_archetype' => 2,
                'season_count' => 4,
                'season_duration_ticks' => 1440,
                'blackout_duration_ticks' => 360,
                'archetype_keys' => [],
                'seeds' => ['tier1-retention-a'],
            ],
            'coupling_hoarding' => [
                'id' => 'coupling_hoarding',
                'tier' => 'tier1',
                'description' => 'Extra-cheap hoarding pressure harness for early promotion filtering.',
                'simulators' => ['B', 'C'],
                'players_per_archetype' => 1,
                'season_count' => 3,
                'season_duration_ticks' => 960,
                'blackout_duration_ticks' => 240,
                'archetype_keys' => ['hoarder', 'mostly_idle', 'regular'],
                'seeds' => ['coupling-hoarding-a'],
            ],
            'coupling_lockin_expiry' => [
                'id' => 'coupling_lockin_expiry',
                'tier' => 'tier1',
                'description' => 'Extra-cheap lock-in vs expiry coupling harness.',
                'simulators' => ['B'],
                'players_per_archetype' => 1,
                'season_count' => 1,
                'season_duration_ticks' => 720,
                'blackout_duration_ticks' => 180,
                'archetype_keys' => ['early_locker', 'late_deployer', 'regular'],
                'seeds' => ['coupling-lockin-expiry-a'],
            ],
            'coupling_boost' => [
                'id' => 'coupling_boost',
                'tier' => 'tier1',
                'description' => 'Extra-cheap boost viability harness.',
                'simulators' => ['B'],
                'players_per_archetype' => 1,
                'season_count' => 1,
                'season_duration_ticks' => 720,
                'blackout_duration_ticks' => 180,
                'archetype_keys' => ['boost_focused', 'regular', 'casual'],
                'seeds' => ['coupling-boost-a'],
            ],
            'coupling_star_pricing' => [
                'id' => 'coupling_star_pricing',
                'tier' => 'tier1',
                'description' => 'Extra-cheap star affordability and price stability harness.',
                'simulators' => ['B'],
                'players_per_archetype' => 1,
                'season_count' => 1,
                'season_duration_ticks' => 720,
                'blackout_duration_ticks' => 180,
                'archetype_keys' => ['star_focused', 'regular', 'casual'],
                'seeds' => ['coupling-star-pricing-a'],
            ],
            'coupling_skip_rejoin' => [
                'id' => 'coupling_skip_rejoin',
                'tier' => 'tier1',
                'description' => 'Extra-cheap repeat-season skip/rejoin exploit harness.',
                'simulators' => ['C'],
                'players_per_archetype' => 1,
                'season_count' => 3,
                'season_duration_ticks' => 960,
                'blackout_duration_ticks' => 240,
                'archetype_keys' => ['mostly_idle', 'regular', 'early_locker'],
                'seeds' => ['coupling-skip-rejoin-a'],
            ],
            'tier2_integration' => [
                'id' => 'tier2_integration',
                'tier' => 'tier2',
                'description' => 'Cross-subsystem integration validation.',
                'simulators' => ['B', 'C'],
                'players_per_archetype' => 3,
                'season_count' => 8,
                'season_duration_ticks' => 4320,
                'blackout_duration_ticks' => 1080,
                'archetype_keys' => [],
                'seeds' => ['tier2-integration-a'],
            ],
            'tier3_full' => [
                'id' => 'tier3_full',
                'tier' => 'tier3',
                'description' => 'Full lifecycle acceptance gate (agentic screening profile). '
                    . 'Uses reduced cost vs promotion-ladder tier3 to prevent PHP timeout. '
                    . 'Official promotion uses SweepComparatorCampaignRunner qualification profile as the real final gate.',
                'simulators' => ['B', 'C'],
                'players_per_archetype' => 3,
                'season_count' => 8,
                'season_duration_ticks' => 1440,
                'blackout_duration_ticks' => 360,
                'archetype_keys' => [],
                'seeds' => ['tier3-full-a'],
            ],
        ];

        $subsystems = [
            [
                'id' => 'hoarding_pressure',
                'label' => 'Hoarding pressure and anti-safe-strategy controls',
                'priority' => 1,
                'local_profile' => 'tier1_hoarding',
                'coupling_harness_families' => ['hoarding_pressure_imbalance'],
                'owned_parameters' => [
                    ['key' => 'hoarding_tier2_rate_hourly_fp', 'mode' => 'multiply', 'values' => [1.04, 1.08]],
                    ['key' => 'hoarding_tier3_rate_hourly_fp', 'mode' => 'multiply', 'values' => [1.04, 1.08]],
                    ['key' => 'hoarding_idle_multiplier_fp', 'mode' => 'multiply', 'values' => [1.02, 1.05]],
                    ['key' => 'hoarding_sink_cap_ratio_fp', 'mode' => 'multiply', 'values' => [0.97, 0.93]],
                ],
                'local_objectives' => [
                    ['metric' => 'hoarder_advantage_gap', 'direction' => 'down', 'weight' => 2.0, 'scale' => 5000.0],
                    ['metric' => 'dominant_strategy_pressure', 'direction' => 'down', 'weight' => 1.5, 'scale' => 0.05],
                    ['metric' => 'strategic_diversity', 'direction' => 'up', 'weight' => 1.0, 'scale' => 0.05],
                ],
                'adjacent_subsystems' => ['blackout_lockin', 'concentration_control', 'star_pricing'],
                'promotion_gates' => ['tier1_min_local_score' => 0.25, 'tier2_min_global_delta' => -0.15, 'tier3_min_global_delta' => 0.0],
            ],
            [
                'id' => 'blackout_lockin',
                'label' => 'Blackout PvP pressure and lock-in timing logic',
                'priority' => 2,
                'local_profile' => 'tier1_blackout',
                'coupling_harness_families' => ['lock_in_down_but_expiry_dominance_up'],
                'owned_parameters' => [
                    ['key' => 'starprice_idle_weight_fp', 'mode' => 'multiply', 'values' => [0.88, 0.80]],
                    ['key' => 'starprice_max_upstep_fp', 'mode' => 'multiply', 'values' => [0.92, 0.85]],
                    ['key' => 'market_affordability_bias_fp', 'mode' => 'multiply', 'values' => [0.97, 0.94]],
                ],
                'local_objectives' => [
                    ['metric' => 'blackout_action_density', 'direction' => 'up', 'weight' => 1.8, 'scale' => 0.25],
                    ['metric' => 'lock_in_timing_entropy', 'direction' => 'up', 'weight' => 1.2, 'scale' => 0.08],
                    ['metric' => 'expiry_rate_mean', 'direction' => 'down', 'weight' => 1.3, 'scale' => 0.04],
                ],
                'adjacent_subsystems' => ['hoarding_pressure', 'lockin_incentives', 'expiry_pressure'],
                'promotion_gates' => ['tier1_min_local_score' => 0.20, 'tier2_min_global_delta' => -0.10, 'tier3_min_global_delta' => 0.0],
            ],
            [
                'id' => 'boost_viability',
                'label' => 'Boost viability and deployment timing',
                'priority' => 3,
                'local_profile' => 'tier1_boost',
                'coupling_harness_families' => ['boost_underperformance'],
                'owned_parameters' => [
                    ['key' => 'target_spend_rate_per_tick', 'mode' => 'multiply', 'values' => [0.95, 0.90]],
                    ['key' => 'hoarding_min_factor_fp', 'mode' => 'multiply', 'values' => [1.05, 1.10]],
                    ['key' => 'base_ubi_active_per_tick', 'mode' => 'multiply', 'values' => [1.05, 1.10]],
                ],
                'local_objectives' => [
                    ['metric' => 'boost_roi', 'direction' => 'up', 'weight' => 1.7, 'scale' => 6.0],
                    ['metric' => 'boost_mid_late_share', 'direction' => 'up', 'weight' => 1.1, 'scale' => 0.08],
                    ['metric' => 'boost_focused_gap', 'direction' => 'down', 'weight' => 1.0, 'scale' => 4500.0],
                ],
                'adjacent_subsystems' => ['onboarding_economy', 'star_pricing', 'hoarding_pressure'],
                'promotion_gates' => ['tier1_min_local_score' => 0.18, 'tier2_min_global_delta' => -0.08, 'tier3_min_global_delta' => 0.0],
            ],
            [
                'id' => 'concentration_control',
                'label' => 'Concentration and runaway leader control',
                'priority' => 4,
                'local_profile' => 'tier1_concentration',
                'coupling_harness_families' => ['hoarding_pressure_imbalance', 'skip_rejoin_exploit_worsened'],
                'owned_parameters' => [
                    ['key' => 'hoarding_sink_cap_ratio_fp', 'mode' => 'multiply', 'values' => [0.96, 0.92]],
                    ['key' => 'hoarding_tier3_rate_hourly_fp', 'mode' => 'multiply', 'values' => [1.03, 1.06]],
                    ['key' => 'market_affordability_bias_fp', 'mode' => 'multiply', 'values' => [0.98, 0.95]],
                ],
                'local_objectives' => [
                    ['metric' => 'concentration_top10_share', 'direction' => 'down', 'weight' => 2.0, 'scale' => 0.03],
                    ['metric' => 'concentration_top1_share', 'direction' => 'down', 'weight' => 1.5, 'scale' => 0.02],
                    ['metric' => 'archetype_viability_min_ratio', 'direction' => 'up', 'weight' => 1.2, 'scale' => 0.08],
                ],
                'adjacent_subsystems' => ['hoarding_pressure', 'blackout_lockin', 'retention_repeat_season'],
                'promotion_gates' => ['tier1_min_local_score' => 0.22, 'tier2_min_global_delta' => -0.08, 'tier3_min_global_delta' => 0.0],
            ],
            [
                'id' => 'sigil_loop',
                'label' => 'Sigil acquisition/combine/theft/melt/denial loop',
                'priority' => 5,
                'local_profile' => 'tier1_sigil',
                'owned_parameters' => [
                    ['key' => 'starprice_idle_weight_fp', 'mode' => 'multiply', 'values' => [0.90, 0.82]],
                    ['key' => 'starprice_max_upstep_fp', 'mode' => 'multiply', 'values' => [0.94, 0.88]],
                    ['key' => 'market_affordability_bias_fp', 'mode' => 'multiply', 'values' => [0.98, 0.95]],
                ],
                'local_objectives' => [
                    ['metric' => 'sigil_counterplay_density', 'direction' => 'up', 'weight' => 1.7, 'scale' => 0.10],
                    ['metric' => 't6_theft_share', 'direction' => 'up', 'weight' => 1.1, 'scale' => 0.03],
                    ['metric' => 'dead_mechanic_penalty', 'direction' => 'down', 'weight' => 1.3, 'scale' => 0.20],
                ],
                'adjacent_subsystems' => ['blackout_lockin', 'boost_viability', 'concentration_control'],
                'promotion_gates' => ['tier1_min_local_score' => 0.16, 'tier2_min_global_delta' => -0.08, 'tier3_min_global_delta' => 0.0],
            ],
            [
                'id' => 'onboarding_economy',
                'label' => 'Onboarding and early acquisition economy',
                'priority' => 6,
                'local_profile' => 'tier1_onboarding',
                'coupling_harness_families' => ['star_affordability_pricing_instability'],
                'owned_parameters' => [
                    ['key' => 'base_ubi_active_per_tick', 'mode' => 'multiply', 'values' => [1.06, 1.12]],
                    ['key' => 'base_ubi_idle_factor_fp', 'mode' => 'multiply', 'values' => [1.04, 1.08]],
                    ['key' => 'market_affordability_bias_fp', 'mode' => 'multiply', 'values' => [0.97, 0.93]],
                ],
                'local_objectives' => [
                    ['metric' => 'onboarding_liquidity', 'direction' => 'up', 'weight' => 1.8, 'scale' => 1000.0],
                    ['metric' => 'first_choice_viability', 'direction' => 'up', 'weight' => 1.2, 'scale' => 0.08],
                    ['metric' => 'archetype_viability_min_ratio', 'direction' => 'up', 'weight' => 1.0, 'scale' => 0.08],
                ],
                'adjacent_subsystems' => ['star_pricing', 'boost_viability', 'lockin_incentives'],
                'promotion_gates' => ['tier1_min_local_score' => 0.16, 'tier2_min_global_delta' => -0.12, 'tier3_min_global_delta' => 0.0],
            ],
            [
                'id' => 'star_pricing',
                'label' => 'Star pricing and purchasing economy',
                'priority' => 7,
                'local_profile' => 'tier1_star_pricing',
                'coupling_harness_families' => ['star_affordability_pricing_instability'],
                'owned_parameters' => [
                    ['key' => 'starprice_idle_weight_fp', 'mode' => 'multiply', 'values' => [0.92, 0.85]],
                    ['key' => 'starprice_max_upstep_fp', 'mode' => 'multiply', 'values' => [0.95, 0.90]],
                    ['key' => 'starprice_max_downstep_fp', 'mode' => 'multiply', 'values' => [1.05, 1.10]],
                    ['key' => 'market_affordability_bias_fp', 'mode' => 'multiply', 'values' => [0.97, 0.94]],
                    ['key' => 'star_price_minimum_absolute', 'mode' => 'add', 'values' => [24, 49, 99]],
                ],
                'local_objectives' => [
                    ['metric' => 'star_purchase_density', 'direction' => 'up', 'weight' => 1.6, 'scale' => 0.20],
                    ['metric' => 'lock_in_total', 'direction' => 'up', 'weight' => 1.0, 'scale' => 6.0],
                    ['metric' => 'expiry_rate_mean', 'direction' => 'down', 'weight' => 1.1, 'scale' => 0.04],
                ],
                'adjacent_subsystems' => ['onboarding_economy', 'lockin_incentives', 'blackout_lockin'],
                'promotion_gates' => ['tier1_min_local_score' => 0.16, 'tier2_min_global_delta' => -0.10, 'tier3_min_global_delta' => 0.0],
            ],
            [
                'id' => 'lockin_incentives',
                'label' => 'Lock-in incentives and timing',
                'priority' => 8,
                'local_profile' => 'tier1_lockin',
                'coupling_harness_families' => ['lock_in_down_but_expiry_dominance_up'],
                'owned_parameters' => [
                    ['key' => 'starprice_idle_weight_fp', 'mode' => 'multiply', 'values' => [0.88, 0.78]],
                    ['key' => 'market_affordability_bias_fp', 'mode' => 'multiply', 'values' => [0.97, 0.94]],
                    ['key' => 'starprice_max_downstep_fp', 'mode' => 'multiply', 'values' => [1.06, 1.12]],
                ],
                'local_objectives' => [
                    ['metric' => 'lock_in_total', 'direction' => 'up', 'weight' => 1.8, 'scale' => 6.0],
                    ['metric' => 'lock_in_timing_entropy', 'direction' => 'up', 'weight' => 1.2, 'scale' => 0.08],
                    ['metric' => 'expiry_rate_mean', 'direction' => 'down', 'weight' => 1.3, 'scale' => 0.04],
                ],
                'adjacent_subsystems' => ['blackout_lockin', 'expiry_pressure', 'star_pricing'],
                'promotion_gates' => ['tier1_min_local_score' => 0.20, 'tier2_min_global_delta' => -0.08, 'tier3_min_global_delta' => 0.0],
            ],
            [
                'id' => 'expiry_pressure',
                'label' => 'Expiry pressure and punishment/reward gradients',
                'priority' => 9,
                'local_profile' => 'tier1_expiry',
                'coupling_harness_families' => ['lock_in_down_but_expiry_dominance_up'],
                'owned_parameters' => [
                    ['key' => 'starprice_max_downstep_fp', 'mode' => 'multiply', 'values' => [1.05, 1.12]],
                    ['key' => 'target_spend_rate_per_tick', 'mode' => 'multiply', 'values' => [0.96, 0.92]],
                    ['key' => 'hoarding_min_factor_fp', 'mode' => 'multiply', 'values' => [1.04, 1.08]],
                ],
                'local_objectives' => [
                    ['metric' => 'expiry_rate_mean', 'direction' => 'down', 'weight' => 1.8, 'scale' => 0.04],
                    ['metric' => 'lock_in_total', 'direction' => 'up', 'weight' => 1.2, 'scale' => 6.0],
                    ['metric' => 'repeat_season_viability', 'direction' => 'up', 'weight' => 1.0, 'scale' => 0.08],
                ],
                'adjacent_subsystems' => ['lockin_incentives', 'retention_repeat_season', 'hoarding_pressure'],
                'promotion_gates' => ['tier1_min_local_score' => 0.18, 'tier2_min_global_delta' => -0.08, 'tier3_min_global_delta' => 0.0],
            ],
            [
                'id' => 'retention_repeat_season',
                'label' => 'Repeated-season carryover/retention/adaptation',
                'priority' => 10,
                'local_profile' => 'tier1_retention',
                'coupling_harness_families' => ['skip_rejoin_exploit_worsened'],
                'owned_parameters' => [
                    ['key' => 'hoarding_min_factor_fp', 'mode' => 'multiply', 'values' => [1.05, 1.10]],
                    ['key' => 'starprice_reactivation_window_ticks', 'mode' => 'multiply', 'values' => [1.08, 1.15]],
                    ['key' => 'base_ubi_active_per_tick', 'mode' => 'multiply', 'values' => [1.04, 1.08]],
                ],
                'local_objectives' => [
                    ['metric' => 'repeat_season_viability', 'direction' => 'up', 'weight' => 1.7, 'scale' => 0.08],
                    ['metric' => 'skip_strategy_edge', 'direction' => 'down', 'weight' => 1.6, 'scale' => 6000.0],
                    ['metric' => 'concentration_top10_share', 'direction' => 'down', 'weight' => 1.2, 'scale' => 0.02],
                ],
                'adjacent_subsystems' => ['concentration_control', 'expiry_pressure', 'onboarding_economy'],
                'promotion_gates' => ['tier1_min_local_score' => 0.18, 'tier2_min_global_delta' => -0.08, 'tier3_min_global_delta' => 0.0],
            ],
        ];

        $activeSearchKeys = array_fill_keys(array_keys(CanonicalEconomyConfigContract::candidateSearchParameters()), true);
        $searchSurfaceMeta = CanonicalEconomyConfigContract::validatorSurfaceMeta();
        foreach ($subsystems as &$subsystem) {
            $subsystem['owned_parameters'] = array_values(array_filter(
                (array)($subsystem['owned_parameters'] ?? []),
                static function (array $parameter) use ($activeSearchKeys, $searchSurfaceMeta, $baselineSeason): bool {
                    $key = (string)($parameter['key'] ?? '');
                    if ($key === '' || !isset($activeSearchKeys[$key])) {
                        return false;
                    }
                    // Skip parameters whose feature flag is disabled in the baseline season.
                    // This mirrors EconomicCandidateValidator::resolveFeatureFlag() and prevents
                    // the strict preflight from rejecting candidates with candidate_disabled_subsystem.
                    $featureFlag = (string)($searchSurfaceMeta[$key]['feature_flag'] ?? '');
                    if ($featureFlag !== '' && $baselineSeason !== []) {
                        if (str_starts_with($featureFlag, 'season.')) {
                            $flagKey = substr($featureFlag, 7);
                            $flagValue = $baselineSeason[$flagKey] ?? null;
                            $enabled = false;
                            if (is_bool($flagValue)) {
                                $enabled = $flagValue;
                            } elseif (is_numeric($flagValue)) {
                                $enabled = ((int)$flagValue) !== 0;
                            }
                            if (!$enabled) {
                                return false;
                            }
                        }
                    }
                    return true;
                }
            ));
            if (!isset($subsystem['search_limits'])) {
                $subsystem['search_limits'] = [
                    'max_candidates' => 4,
                    'max_accepted' => 1,
                ];
            }
        }
        unset($subsystem);

        return [
            'schema_version' => 'tmc-agentic-decomposition.v1',
            'generated_at' => gmdate('c'),
            'profiles' => $profiles,
            'subsystems' => $subsystems,
            'global_metrics' => [
                'archetype_viability_min_ratio',
                'concentration_top10_share',
                'ranking_churn_proxy',
                'strategic_diversity',
                'dominant_strategy_pressure',
                'blackout_action_density',
                'repeat_season_viability',
                'lifecycle_coherence',
                'skip_strategy_edge',
                'expiry_rate_mean',
            ],
        ];
    }

    public static function writeArtifacts(array $decomposition, string $dir): array
    {
        AgenticOptimizationUtils::ensureDir($dir);

        $jsonPath = $dir . DIRECTORY_SEPARATOR . 'economy_decomposition_map.json';
        AgenticOptimizationUtils::writeJson($jsonPath, $decomposition);

        $mdPath = $dir . DIRECTORY_SEPARATOR . 'economy_decomposition_map.md';
        $lines = [];
        $lines[] = '# Economy Decomposition Map';
        $lines[] = '';
        $lines[] = 'Schema: ' . $decomposition['schema_version'];
        $lines[] = 'Generated: ' . $decomposition['generated_at'];
        $lines[] = '';
        $lines[] = '## Subsystems';
        $lines[] = '';

        foreach ((array)$decomposition['subsystems'] as $subsystem) {
            $lines[] = '### ' . $subsystem['label'] . ' (`' . $subsystem['id'] . '`)';
            $lines[] = '';
            $lines[] = '- Priority: ' . (int)$subsystem['priority'];
            $lines[] = '- Local profile: `' . (string)$subsystem['local_profile'] . '`';
            $lines[] = '- Adjacent systems: ' . implode(', ', (array)$subsystem['adjacent_subsystems']);
            $lines[] = '- Promotion gates: Tier1 score >= ' . $subsystem['promotion_gates']['tier1_min_local_score']
                . ', Tier2 global delta >= ' . $subsystem['promotion_gates']['tier2_min_global_delta']
                . ', Tier3 global delta >= ' . $subsystem['promotion_gates']['tier3_min_global_delta'];
            $lines[] = '- Coupling harness families: ' . implode(', ', (array)($subsystem['coupling_harness_families'] ?? []));
            $lines[] = '- Search limits: max_candidates=' . (int)$subsystem['search_limits']['max_candidates']
                . ', max_accepted=' . (int)$subsystem['search_limits']['max_accepted'];
            $lines[] = '- Owned parameters:';
            foreach ((array)$subsystem['owned_parameters'] as $surface) {
                $lines[] = '  - `' . (string)$surface['key'] . '` (' . (string)$surface['mode'] . ')';
            }
            $lines[] = '- Local metrics:';
            foreach ((array)$subsystem['local_objectives'] as $objective) {
                $lines[] = '  - `' . (string)$objective['metric'] . '` direction=' . (string)$objective['direction']
                    . ' weight=' . (float)$objective['weight'];
            }
            $lines[] = '';
        }

        $lines[] = '## Profiles';
        $lines[] = '';
        foreach ((array)$decomposition['profiles'] as $profile) {
            $line = '- `' . (string)$profile['id'] . '` (' . (string)$profile['tier'] . '): ' . (string)$profile['description'];
            if (!empty($profile['season_duration_ticks'])) {
                $line .= ' [slice=' . (int)$profile['season_duration_ticks'] . ' ticks';
                if (!empty($profile['blackout_duration_ticks'])) {
                    $line .= ', blackout=' . (int)$profile['blackout_duration_ticks'];
                }
                $line .= ']';
            }
            $lines[] = $line;
        }
        $lines[] = '';

        file_put_contents($mdPath, implode(PHP_EOL, $lines));

        return ['json' => $jsonPath, 'md' => $mdPath];
    }
}

class AgenticMetricEvaluator
{
    public static function extractMetrics(?array $seasonPayload, ?array $lifetimePayload): array
    {
        $metrics = [
            'onboarding_liquidity' => 0.0,
            'first_choice_viability' => 0.0,
            'star_purchase_density' => 0.0,
            'boost_roi' => 0.0,
            'boost_mid_late_share' => 0.0,
            'sigil_counterplay_density' => 0.0,
            't6_theft_share' => 0.0,
            'dead_mechanic_penalty' => 1.0,
            'hoarder_advantage_gap' => 0.0,
            'boost_focused_gap' => 0.0,
            'lock_in_timing_entropy' => 0.0,
            'lock_in_total' => 0.0,
            'expiry_rate_mean' => 0.0,
            'blackout_action_density' => 0.0,
            'archetype_viability_min_ratio' => 0.0,
            'strategic_diversity' => 0.0,
            'dominant_strategy_pressure' => 0.0,
            'concentration_top10_share' => 0.0,
            'concentration_top1_share' => 0.0,
            'ranking_churn_proxy' => 0.0,
            'repeat_season_viability' => 0.0,
            'lifecycle_coherence' => 0.0,
            'skip_strategy_edge' => 0.0,
            'throughput_lock_in_rate' => 0.0,
            'star_price_cap_share' => 0.0,
            'star_price_floor_share' => 0.0,
            'star_price_range_ratio' => 0.0,
            'star_price_mean' => 0.0,
            'dominant_archetype_label_b' => 'none',
            'dominant_archetype_label_c' => 'none',
        ];

        if ($seasonPayload !== null) {
            $archetypes = (array)($seasonPayload['archetypes'] ?? []);
            $diagnostics = (array)($seasonPayload['diagnostics'] ?? []);
            $players = max(1, (int)($seasonPayload['config']['total_players'] ?? 0));

            $archetypeScores = [];
            $earlyCoins = 0.0;
            $earlyStars = 0.0;
            $allStars = 0.0;
            $expiryRates = [];
            $boostCoins = 0.0;
            $boostTicks = 0.0;
            $boostActionsByPhase = ['EARLY' => 0, 'MID' => 0, 'LATE_ACTIVE' => 0, 'BLACKOUT' => 0];
            $counterplayActions = 0.0;
            $deadMechanicSignals = ['freeze' => 0.0, 'theft' => 0.0, 'combine' => 0.0];
            $t6Theft = 0.0;
            $t6Total = 0.0;
            $firstChoice = 0;
            $scoreRatios = [];

            foreach ($archetypes as $key => $row) {
                $label = (string)($row['label'] ?? $key);
                $score = (float)($row['global_stars_gained'] ?? 0.0);
                $archetypeScores[$label] = $score;

                $coinsByPhase = (array)($row['coins_earned_by_phase'] ?? []);
                $starsByPhase = (array)($row['stars_purchased_by_phase'] ?? []);

                $earlyCoins += (float)($coinsByPhase['EARLY'] ?? 0.0);
                $earlyStars += (float)($starsByPhase['EARLY'] ?? 0.0);
                $allStars += array_sum(array_map('floatval', $starsByPhase));

                $expiryRates[] = (float)($row['natural_expiry_rate'] ?? 0.0);

                $boostCoins += (float)($row['coins_earned_while_boosted'] ?? 0.0);
                $boostTicks += (float)($row['ticks_boosted'] ?? 0.0);

                $actions = (array)($row['action_volume_by_phase'] ?? []);
                foreach ($boostActionsByPhase as $phase => $_) {
                    $boostActionsByPhase[$phase] += (int)($actions[$phase]['boost'] ?? 0);
                }

                foreach (['EARLY', 'MID', 'LATE_ACTIVE', 'BLACKOUT'] as $phase) {
                    $counterplayActions += (float)($actions[$phase]['combine'] ?? 0);
                    $counterplayActions += (float)($actions[$phase]['freeze'] ?? 0);
                    $counterplayActions += (float)($actions[$phase]['theft'] ?? 0);
                    $deadMechanicSignals['freeze'] += (float)($actions[$phase]['freeze'] ?? 0);
                    $deadMechanicSignals['theft'] += (float)($actions[$phase]['theft'] ?? 0);
                    $deadMechanicSignals['combine'] += (float)($actions[$phase]['combine'] ?? 0);
                }

                $t6Source = (array)($row['t6_by_source'] ?? []);
                $t6Theft += (float)($t6Source['theft'] ?? 0.0);
                $t6Total += (float)($row['t6_total_acquired'] ?? 0.0);

                if (((float)($starsByPhase['EARLY'] ?? 0.0)) > 0.0) {
                    $firstChoice++;
                }
            }

            $totalArchetypes = max(1, count($archetypes));
            $metrics['onboarding_liquidity'] = $earlyCoins / $players;
            $metrics['first_choice_viability'] = $firstChoice / $totalArchetypes;
            $metrics['star_purchase_density'] = $allStars / $players;
            $metrics['boost_roi'] = $boostCoins / max(1.0, $boostTicks);

            $boostTotal = array_sum($boostActionsByPhase);
            $midLate = $boostActionsByPhase['MID'] + $boostActionsByPhase['LATE_ACTIVE'] + $boostActionsByPhase['BLACKOUT'];
            $metrics['boost_mid_late_share'] = $boostTotal > 0 ? ($midLate / $boostTotal) : 0.0;

            $metrics['sigil_counterplay_density'] = $counterplayActions / $players;
            $metrics['t6_theft_share'] = $t6Total > 0 ? ($t6Theft / $t6Total) : 0.0;

            $deadCount = 0;
            foreach ($deadMechanicSignals as $count) {
                if ($count <= 0.0) {
                    $deadCount++;
                }
            }
            $metrics['dead_mechanic_penalty'] = $deadCount / max(1.0, (float)count($deadMechanicSignals));

            $scores = array_values($archetypeScores);
            $medianScore = max(1.0, AgenticOptimizationUtils::median($scores));
            $minScore = !empty($scores) ? min($scores) : 0.0;
            $totalScore = max(1.0, array_sum($scores));
            arsort($archetypeScores);
            $dominantLabel = (string)(array_key_first($archetypeScores) ?? 'none');
            $dominantScore = !empty($archetypeScores) ? (float)reset($archetypeScores) : 0.0;

            $metrics['dominant_archetype_label_b'] = $dominantLabel;
            $metrics['dominant_strategy_pressure'] = $dominantScore / $totalScore;
            $metrics['archetype_viability_min_ratio'] = $minScore / $medianScore;

            $hoarderScore = 0.0;
            $boostFocusedScore = 0.0;
            foreach ($archetypes as $key => $row) {
                if ((string)$key === 'hoarder') {
                    $hoarderScore = (float)($row['global_stars_gained'] ?? 0.0);
                }
                if ((string)$key === 'boost_focused') {
                    $boostFocusedScore = (float)($row['global_stars_gained'] ?? 0.0);
                }
            }
            $metrics['hoarder_advantage_gap'] = $hoarderScore - $medianScore;
            $metrics['boost_focused_gap'] = $boostFocusedScore - $medianScore;

            $metrics['expiry_rate_mean'] = AgenticOptimizationUtils::mean($expiryRates);

            $lockTiming = (array)($diagnostics['lock_in_timing'] ?? []);
            $lockCounts = [
                (float)($lockTiming['EARLY'] ?? 0.0),
                (float)($lockTiming['MID'] ?? 0.0),
                (float)($lockTiming['LATE_ACTIVE'] ?? 0.0),
                (float)($lockTiming['BLACKOUT'] ?? 0.0),
            ];
            $metrics['lock_in_timing_entropy'] = AgenticOptimizationUtils::entropyNormalized($lockCounts);
            $metrics['lock_in_total'] = array_sum($lockCounts);

            $actionByPhase = (array)($diagnostics['action_volume_by_phase'] ?? []);
            $blackoutTotal = 0.0;
            $allActions = 0.0;
            $actionTypeTotals = ['boost' => 0.0, 'combine' => 0.0, 'freeze' => 0.0, 'theft' => 0.0];
            foreach ($actionByPhase as $phase => $counts) {
                foreach ($actionTypeTotals as $action => $_) {
                    $value = (float)($counts[$action] ?? 0.0);
                    $actionTypeTotals[$action] += $value;
                    $allActions += $value;
                    if ((string)$phase === 'BLACKOUT') {
                        $blackoutTotal += $value;
                    }
                }
            }
            $metrics['blackout_action_density'] = $blackoutTotal / $players;
            $metrics['strategic_diversity'] = AgenticOptimizationUtils::entropyNormalized($actionTypeTotals);

            $starPriceSummary = (array)($diagnostics['star_price_summary'] ?? []);
            $starPriceMean = max(1.0, (float)($starPriceSummary['mean'] ?? 0.0));
            $starPriceMin = (float)($starPriceSummary['min'] ?? 0.0);
            $starPriceMax = (float)($starPriceSummary['max'] ?? 0.0);
            $metrics['star_price_mean'] = $starPriceMean;
            $metrics['star_price_cap_share'] = (float)($starPriceSummary['cap_share'] ?? 0.0);
            $metrics['star_price_floor_share'] = (float)($starPriceSummary['floor_share'] ?? 0.0);
            $metrics['star_price_range_ratio'] = max(0.0, ($starPriceMax - $starPriceMin) / $starPriceMean);

            foreach ($scores as $score) {
                $scoreRatios[] = $score / $medianScore;
            }
            if (!empty($scoreRatios)) {
                $metrics['ranking_churn_proxy'] = AgenticOptimizationUtils::entropyNormalized($scoreRatios);
            }
        }

        if ($lifetimePayload !== null) {
            $players = (array)($lifetimePayload['players'] ?? []);
            $seasonCount = max(1, (int)($lifetimePayload['config']['season_count'] ?? 1));
            $diag = (array)($lifetimePayload['population_diagnostics'] ?? []);
            $concentration = (array)($lifetimePayload['concentration_drift'] ?? []);
            $archetypes = (array)($lifetimePayload['archetypes'] ?? []);

            $final = !empty($concentration) ? (array)$concentration[count($concentration) - 1] : [];
            $metrics['concentration_top10_share'] = (float)($final['top_10_percent_share'] ?? 0.0);
            $metrics['concentration_top1_share'] = (float)($final['top_1_percent_share'] ?? 0.0);
            $metrics['skip_strategy_edge'] = (float)($diag['skip_strategy_edge'] ?? 0.0);
            $metrics['throughput_lock_in_rate'] = (float)($diag['throughput_lock_in_rate'] ?? 0.0);

            $entryRatios = [];
            $rejoinDelays = [];
            foreach ($players as $player) {
                $entered = (float)($player['seasons_entered'] ?? 0.0);
                $skipped = (float)($player['seasons_skipped'] ?? 0.0);
                $entryRatios[] = $entered / max(1.0, $entered + $skipped);
                $rejoinDelays[] = (float)($player['rejoin_delay_average'] ?? 0.0);
            }
            $metrics['repeat_season_viability'] = AgenticOptimizationUtils::mean($entryRatios);
            $avgRejoinDelay = AgenticOptimizationUtils::mean($rejoinDelays);

            if (!empty($archetypes)) {
                $scores = [];
                foreach ($archetypes as $key => $row) {
                    $scores[(string)$row['label']] = (float)($row['cumulative_global_stars_avg'] ?? 0.0);
                }
                arsort($scores);
                $metrics['dominant_archetype_label_c'] = (string)(array_key_first($scores) ?? 'none');
            }

            // Lifecycle coherence rewards sustained participation with lower rejoin delay and lower skip edge.
            $coherence = 0.0;
            $coherence += 0.6 * $metrics['repeat_season_viability'];
            $coherence += 0.4 * $metrics['throughput_lock_in_rate'];
            $coherence -= 0.25 * AgenticOptimizationUtils::clamp($avgRejoinDelay / max(1.0, (float)$seasonCount), 0.0, 1.0);
            $coherence -= 0.25 * AgenticOptimizationUtils::clamp(max(0.0, $metrics['skip_strategy_edge']) / 40000.0, 0.0, 1.0);
            $metrics['lifecycle_coherence'] = AgenticOptimizationUtils::clamp($coherence, 0.0, 1.0);
        }

        return $metrics;
    }

    public static function globalScore(array $metrics): float
    {
        $score = 0.0;
        $score += 22.0 * AgenticOptimizationUtils::clamp((float)$metrics['archetype_viability_min_ratio'], 0.0, 1.0);
        $score += 12.0 * AgenticOptimizationUtils::clamp((float)$metrics['strategic_diversity'], 0.0, 1.0);
        $score += 8.0 * AgenticOptimizationUtils::clamp((float)$metrics['blackout_action_density'] / 2.0, 0.0, 1.0);
        $score += 10.0 * AgenticOptimizationUtils::clamp((float)$metrics['repeat_season_viability'], 0.0, 1.0);
        $score += 8.0 * AgenticOptimizationUtils::clamp((float)$metrics['lifecycle_coherence'], 0.0, 1.0);
        $score += 6.0 * AgenticOptimizationUtils::clamp((float)$metrics['throughput_lock_in_rate'], 0.0, 1.0);

        $score -= 18.0 * AgenticOptimizationUtils::clamp((float)$metrics['dominant_strategy_pressure'], 0.0, 1.0);
        $score -= 18.0 * AgenticOptimizationUtils::clamp((float)$metrics['concentration_top10_share'], 0.0, 1.0);
        $score -= 9.0 * AgenticOptimizationUtils::clamp(max(0.0, (float)$metrics['skip_strategy_edge']) / 30000.0, 0.0, 1.0);
        $score -= 10.0 * AgenticOptimizationUtils::clamp((float)$metrics['expiry_rate_mean'], 0.0, 1.0);

        return $score;
    }

    public static function localScore(array $baseline, array $candidate, array $objectives): array
    {
        $score = 0.0;
        $parts = [];

        foreach ($objectives as $objective) {
            $metric = (string)$objective['metric'];
            $direction = (string)$objective['direction'];
            $weight = (float)$objective['weight'];
            $scale = max(0.00001, (float)($objective['scale'] ?? 1.0));

            $base = (float)($baseline[$metric] ?? 0.0);
            $cand = (float)($candidate[$metric] ?? 0.0);
            $delta = $cand - $base;

            $improvement = 0.0;
            if ($direction === 'up') {
                $improvement = $delta / $scale;
            } elseif ($direction === 'down') {
                $improvement = -$delta / $scale;
            } else {
                $target = (float)($objective['target'] ?? $base);
                $improvement = (abs($base - $target) - abs($cand - $target)) / $scale;
            }

            $partScore = $improvement * $weight;
            $score += $partScore;
            $parts[] = [
                'metric' => $metric,
                'direction' => $direction,
                'base' => $base,
                'candidate' => $cand,
                'delta' => $delta,
                'weighted_contribution' => $partScore,
            ];
        }

        return ['score' => $score, 'parts' => $parts];
    }

    public static function regressionFlags(array $baseline, array $candidate): array
    {
        $flags = [];

        $lockDelta = (float)($candidate['lock_in_total'] ?? 0.0) - (float)($baseline['lock_in_total'] ?? 0.0);
        $expiryDelta = (float)($candidate['expiry_rate_mean'] ?? 0.0) - (float)($baseline['expiry_rate_mean'] ?? 0.0);
        if ($lockDelta < -1.0 && $expiryDelta > 0.02) {
            $flags[] = 'lock_in_down_but_expiry_dominance_up';
        }

        $dominantBBase = (string)($baseline['dominant_archetype_label_b'] ?? 'none');
        $dominantBCand = (string)($candidate['dominant_archetype_label_b'] ?? 'none');
        $dominantCBase = (string)($baseline['dominant_archetype_label_c'] ?? 'none');
        $dominantCCand = (string)($candidate['dominant_archetype_label_c'] ?? 'none');
        if (($dominantBBase !== 'none' && $dominantBCand !== 'none' && $dominantBBase !== $dominantBCand)
            || ($dominantCBase !== 'none' && $dominantCCand !== 'none' && $dominantCBase !== $dominantCCand)) {
            $flags[] = 'dominant_archetype_shifted';
        }

        $top10Delta = (float)($candidate['concentration_top10_share'] ?? 0.0) - (float)($baseline['concentration_top10_share'] ?? 0.0);
        if ($top10Delta > 0.01) {
            $flags[] = 'long_run_concentration_worsened';
        }

        $skipDelta = (float)($candidate['skip_strategy_edge'] ?? 0.0) - (float)($baseline['skip_strategy_edge'] ?? 0.0);
        if ($skipDelta > 2000.0) {
            $flags[] = 'skip_rejoin_exploit_worsened';
        }

        $viabilityDelta = (float)($candidate['archetype_viability_min_ratio'] ?? 0.0) - (float)($baseline['archetype_viability_min_ratio'] ?? 0.0);
        if ($viabilityDelta < -0.05) {
            $flags[] = 'archetype_viability_regressed';
        }

        $blackoutDelta = (float)($candidate['blackout_action_density'] ?? 0.0) - (float)($baseline['blackout_action_density'] ?? 0.0);
        if ($blackoutDelta < -0.15) {
            $flags[] = 'blackout_action_density_down';
        }

        $diversityDelta = (float)($candidate['strategic_diversity'] ?? 0.0) - (float)($baseline['strategic_diversity'] ?? 0.0);
        if ($diversityDelta < -0.05) {
            $flags[] = 'strategic_diversity_down';
        }

        sort($flags);
        return array_values(array_unique($flags));
    }

    public static function averageMetricSet(array $metricSets): array
    {
        if ($metricSets === []) {
            return [];
        }

        $result = [];
        $keys = array_keys($metricSets[0]);
        foreach ($keys as $key) {
            $first = $metricSets[0][$key];
            if (is_numeric($first)) {
                $values = [];
                foreach ($metricSets as $set) {
                    $values[] = (float)($set[$key] ?? 0.0);
                }
                $result[$key] = AgenticOptimizationUtils::mean($values);
            } else {
                $result[$key] = $first;
            }
        }

        return $result;
    }
}

class AgenticHarnessRunner
{
    private string $runRoot;
    private string $cachePath;
    private array $cache;

    public function __construct(string $runRoot)
    {
        $this->runRoot = $runRoot;
        AgenticOptimizationUtils::ensureDir($this->runRoot);
        $this->cachePath = $this->runRoot . DIRECTORY_SEPARATOR . 'search-memory' . DIRECTORY_SEPARATOR . 'run_cache_index.json';
        AgenticOptimizationUtils::ensureDir(dirname($this->cachePath));

        if (is_file($this->cachePath)) {
            $decoded = json_decode((string)file_get_contents($this->cachePath), true);
            $this->cache = is_array($decoded) ? $decoded : ['entries' => []];
        } else {
            $this->cache = ['schema_version' => 'tmc-agentic-run-cache.v1', 'updated_at' => gmdate('c'), 'entries' => []];
        }
    }

    public function evaluate(array $seasonConfig, array $profile, string $label, array $candidateChanges = [], ?array $baseSeasonConfig = null): array
    {
        $effectiveSeasonConfig = self::applySeasonSlice($seasonConfig, $profile);

        $seasonForHash = AgenticOptimizationUtils::convertSeasonForJson($effectiveSeasonConfig);
        AgenticOptimizationUtils::sortAssocRecursively($seasonForHash);

        $profileForHash = [
            'id' => (string)$profile['id'],
            'simulators' => array_values((array)$profile['simulators']),
            'players_per_archetype' => (int)$profile['players_per_archetype'],
            'season_count' => (int)($profile['season_count'] ?? 1),
            'season_duration_ticks' => (int)($profile['season_duration_ticks'] ?? 0),
            'blackout_duration_ticks' => (int)($profile['blackout_duration_ticks'] ?? 0),
            'tick_real_seconds' => (int)($profile['tick_real_seconds'] ?? 3600),
            'archetype_keys' => array_values((array)($profile['archetype_keys'] ?? [])),
            'phase_stop' => $profile['phase_stop'] ?? null,
            'seeds' => array_values((array)($profile['seeds'] ?? ['default-seed'])),
        ];
        AgenticOptimizationUtils::sortAssocRecursively($profileForHash);

        $cacheKey = AgenticOptimizationUtils::jsonHash([
            'season' => $seasonForHash,
            'profile' => $profileForHash,
        ]);

        if (isset($this->cache['entries'][$cacheKey])) {
            $entry = $this->cache['entries'][$cacheKey];
            $entry['cache_hit'] = true;
            return $entry;
        }

        $tierDir = $this->runRoot . DIRECTORY_SEPARATOR . ($profile['tier'] ?? 'tier-unknown') . DIRECTORY_SEPARATOR . 'runs';
        AgenticOptimizationUtils::ensureDir($tierDir);

        $metricsBySeed = [];
        $seasonPaths = [];
        $lifetimePaths = [];
        $seasonAuditPaths = [];
        $lifetimeAuditPaths = [];
        $runtimeStart = microtime(true);

        $seeds = (array)($profile['seeds'] ?? ['seed-a']);
        $seedIndex = 0;
        foreach ($seeds as $seed) {
            $seedIndex++;
            $seedName = (string)$seed;
            $contextSeed = AgenticOptimizationUtils::sanitize($label . '-' . $profile['id'] . '-' . $seedName . '-' . $seedIndex);

            $configPath = $tierDir . DIRECTORY_SEPARATOR . 'season_cfg_' . $contextSeed . '.json';
            $configForWrite = AgenticOptimizationUtils::convertSeasonForJson($effectiveSeasonConfig);
            AgenticOptimizationUtils::writeJson($configPath, $configForWrite);

            $seasonPayload = null;
            $lifetimePayload = null;
            $simulators = array_map('strtoupper', (array)$profile['simulators']);
            $tickRealSeconds = max(1, (int)($profile['tick_real_seconds'] ?? 3600));
            $previousTick = getenv('TMC_TICK_REAL_SECONDS');
            putenv('TMC_TICK_REAL_SECONDS=' . $tickRealSeconds);
            $_ENV['TMC_TICK_REAL_SECONDS'] = (string)$tickRealSeconds;
            try {
                if (in_array('B', $simulators, true)) {
                    $baseName = 'season_' . $contextSeed;
                    $seasonPayload = SimulationPopulationSeason::run(
                        $seedName,
                        (int)$profile['players_per_archetype'],
                        null,
                        [
                            'archetype_keys' => (array)($profile['archetype_keys'] ?? []),
                            'phase_stop' => $profile['phase_stop'] ?? null,
                            'run_label' => $baseName,
                            'preflight_artifact_dir' => $tierDir . DIRECTORY_SEPARATOR . $baseName . '.audit',
                            'base_season_overrides' => SeasonConfigExporter::canonicalOverridesFromSeason($baseSeasonConfig ?? $effectiveSeasonConfig),
                            'candidate_patch' => $candidateChanges,
                        ]
                    );

                    $seasonJsonPath = MetricsCollector::writeJson($seasonPayload, $tierDir, $baseName);
                    MetricsCollector::writeSeasonCsv($seasonPayload, $tierDir, $baseName);
                    $seasonPaths[] = $seasonJsonPath;
                    $seasonAuditPaths[] = (array)($seasonPayload['config_audit']['artifact_paths'] ?? []);
                }

                if (in_array('C', $simulators, true)) {
                    $baseName = 'lifetime_' . $contextSeed;
                    $lifetimePayload = SimulationPopulationLifetime::run(
                        $seedName,
                        (int)$profile['players_per_archetype'],
                        (int)($profile['season_count'] ?? 4),
                        null,
                        [
                            'archetype_keys' => (array)($profile['archetype_keys'] ?? []),
                            'run_label' => $baseName,
                            'preflight_artifact_dir' => $tierDir . DIRECTORY_SEPARATOR . $baseName . '.audit',
                            'base_season_overrides' => SeasonConfigExporter::canonicalOverridesFromSeason($baseSeasonConfig ?? $effectiveSeasonConfig),
                            'candidate_patch' => $candidateChanges,
                        ]
                    );

                    $lifetimeJsonPath = MetricsCollector::writeJson($lifetimePayload, $tierDir, $baseName);
                    MetricsCollector::writeLifetimeCsv($lifetimePayload, $tierDir, $baseName);
                    $lifetimePaths[] = $lifetimeJsonPath;
                    $lifetimeAuditPaths[] = (array)($lifetimePayload['config_audit']['artifact_paths'] ?? []);
                }
            } finally {
                if ($previousTick === false || $previousTick === null || $previousTick === '') {
                    putenv('TMC_TICK_REAL_SECONDS');
                    unset($_ENV['TMC_TICK_REAL_SECONDS']);
                } else {
                    putenv('TMC_TICK_REAL_SECONDS=' . $previousTick);
                    $_ENV['TMC_TICK_REAL_SECONDS'] = (string)$previousTick;
                }
                @unlink($configPath);
            }

            $metricsBySeed[] = AgenticMetricEvaluator::extractMetrics($seasonPayload, $lifetimePayload);
        }

        $aggregatedMetrics = AgenticMetricEvaluator::averageMetricSet($metricsBySeed);
        $runtime = microtime(true) - $runtimeStart;

        $entry = [
            'profile_id' => (string)$profile['id'],
            'tier' => (string)($profile['tier'] ?? 'unknown'),
            'label' => $label,
            'cache_key' => $cacheKey,
            'cache_hit' => false,
            'evaluated_at' => gmdate('c'),
            'runtime_secs' => round($runtime, 3),
            'metrics' => $aggregatedMetrics,
            'metrics_by_seed' => $metricsBySeed,
            'season_paths' => $seasonPaths,
            'lifetime_paths' => $lifetimePaths,
            'season_audit_paths' => $seasonAuditPaths,
            'lifetime_audit_paths' => $lifetimeAuditPaths,
        ];

        $this->cache['entries'][$cacheKey] = $entry;
        $this->cache['updated_at'] = gmdate('c');
        AgenticOptimizationUtils::writeJson($this->cachePath, $this->cache);

        return $entry;
    }

    private static function applySeasonSlice(array $seasonConfig, array $profile): array
    {
        $durationTicks = (int)($profile['season_duration_ticks'] ?? 0);
        if ($durationTicks <= 1) {
            // WARNING: No season_duration_ticks set — returns the raw DB config unchanged,
            // which may have end_time in the millions of ticks. Every profile must set
            // season_duration_ticks explicitly or this will cause a multi-hour hang.
            return $seasonConfig;
        }

        $startTime = max(1, (int)($seasonConfig['start_time'] ?? 1));
        $blackoutDuration = (int)($profile['blackout_duration_ticks'] ?? max(1, (int)round($durationTicks * 0.25)));
        $blackoutDuration = max(1, min($durationTicks - 1, $blackoutDuration));

        $sliced = $seasonConfig;
        $sliced['start_time'] = $startTime;
        $sliced['end_time'] = $startTime + $durationTicks;
        $sliced['blackout_time'] = $sliced['end_time'] - $blackoutDuration;
        $sliced['last_processed_tick'] = $startTime;

        if (isset($sliced['hoarding_window_ticks']) && is_numeric($sliced['hoarding_window_ticks'])) {
            $sliced['hoarding_window_ticks'] = max(1, min((int)$sliced['hoarding_window_ticks'], $durationTicks));
        }

        return $sliced;
    }
}

class AgenticCouplingHarnessCatalog
{
    public static function families(): array
    {
        return [
            'lock_in_down_but_expiry_dominance_up' => [
                'family_id' => 'lock_in_down_but_expiry_dominance_up',
                'label' => 'Lock-in down while expiry dominance rises',
                'profile_id' => 'coupling_lockin_expiry',
                'description' => 'Detects the known wall where lock-ins fall and natural expiry grows instead of improving the lock-in/expiry balance.',
                'blocking_flags' => ['lock_in_down_but_expiry_dominance_up'],
                'metric_gates' => [
                    ['metric' => 'lock_in_total', 'direction' => 'up', 'min_improvement' => 0.0, 'label' => 'Lock-in volume must not fall.'],
                    ['metric' => 'expiry_rate_mean', 'direction' => 'down', 'min_improvement' => 0.0, 'label' => 'Expiry rate must not rise.'],
                    ['metric' => 'lock_in_timing_entropy', 'direction' => 'up', 'min_improvement' => 0.0, 'label' => 'Lock-in timing spread must not collapse.'],
                ],
                'proves' => 'Short-horizon lock-in and expiry incentives did not recreate the previously observed structural failure.',
                'cannot_prove' => 'Does not prove long-run repeat-season retention or full-economy stability.',
            ],
            'skip_rejoin_exploit_worsened' => [
                'family_id' => 'skip_rejoin_exploit_worsened',
                'label' => 'Skip/rejoin exploit worsened',
                'profile_id' => 'coupling_skip_rejoin',
                'description' => 'Detects repeat-season candidates that reward skipping or make healthy re-entry less attractive.',
                'blocking_flags' => ['skip_rejoin_exploit_worsened', 'long_run_concentration_worsened'],
                'metric_gates' => [
                    // skip_strategy_edge is an absolute coin-count metric (baseline ~5910 coins).
                    // Epsilon of -1.0 tolerates up to a 1-unit absolute regression (~0.017% of baseline),
                    // matching the sub-percent structural spill from cross-subsystem affordability changes.
                    ['metric' => 'skip_strategy_edge', 'direction' => 'down', 'min_improvement' => -1.0, 'label' => 'Skip-heavy players must not gain meaningful edge (1-unit absolute tolerance).'],
                    ['metric' => 'repeat_season_viability', 'direction' => 'up', 'min_improvement' => 0.0, 'label' => 'Repeat participation must not degrade.'],
                    ['metric' => 'throughput_lock_in_rate', 'direction' => 'up', 'min_improvement' => 0.0, 'label' => 'Throughput lock-in rate must not fall.'],
                ],
                'proves' => 'Short lifecycle retention dynamics did not make the skip/rejoin exploit materially stronger.',
                'cannot_prove' => 'Does not prove final long-run concentration is globally acceptable outside this reduced harness.',
            ],
            'hoarding_pressure_imbalance' => [
                'family_id' => 'hoarding_pressure_imbalance',
                'label' => 'Hoarding pressure imbalance',
                'profile_id' => 'coupling_hoarding',
                'description' => 'Detects candidates that leave hoarding too safe or push the economy toward a new dominant anti-play strategy.',
                'blocking_flags' => ['dominant_archetype_shifted', 'archetype_viability_regressed'],
                'metric_gates' => [
                    ['metric' => 'hoarder_advantage_gap', 'direction' => 'down', 'min_improvement' => 0.0, 'label' => 'Hoarder advantage must not widen.'],
                    // dominant_strategy_pressure is a 0–1 ratio metric.
                    // Epsilon of -0.001 tolerates up to 0.1% cross-subsystem spill from affordability parameters
                    // whose primary effect is on star pricing, not hoarding strategy dominance.
                    ['metric' => 'dominant_strategy_pressure', 'direction' => 'down', 'min_improvement' => -0.001, 'label' => 'Dominant strategy pressure must not rise materially (0.1% tolerance).'],
                    ['metric' => 'strategic_diversity', 'direction' => 'up', 'min_improvement' => 0.0, 'label' => 'Strategic diversity must not fall.'],
                ],
                'proves' => 'The cheap anti-hoarding harness did not show the candidate worsening safe-strategy dominance.',
                'cannot_prove' => 'Does not prove cross-subsystem pricing or retention interactions remain healthy at full scale.',
            ],
            'boost_underperformance' => [
                'family_id' => 'boost_underperformance',
                'label' => 'Boost underperformance',
                'profile_id' => 'coupling_boost',
                'description' => 'Detects candidates where boost-focused play gets weaker, later boosts disappear, or boosts stop converting into score.',
                'blocking_flags' => ['archetype_viability_regressed'],
                'metric_gates' => [
                    ['metric' => 'boost_roi', 'direction' => 'up', 'min_improvement' => 0.0, 'label' => 'Boost ROI must not fall.'],
                    ['metric' => 'boost_mid_late_share', 'direction' => 'up', 'min_improvement' => 0.0, 'label' => 'Mid/late boost usage must not fall.'],
                    ['metric' => 'boost_focused_gap', 'direction' => 'down', 'min_improvement' => 0.0, 'label' => 'Boost-focused score gap must not widen.'],
                ],
                'proves' => 'The reduced boost harness did not show the candidate weakening boost payoff or deployment timing.',
                'cannot_prove' => 'Does not prove boost changes remain neutral once all other subsystems mutate together.',
            ],
            'star_affordability_pricing_instability' => [
                'family_id' => 'star_affordability_pricing_instability',
                'label' => 'Star affordability and pricing instability',
                'profile_id' => 'coupling_star_pricing',
                'description' => 'Detects candidates that make early star access worse or create unstable pricing behavior even in a reduced star-market slice.',
                'blocking_flags' => ['dominant_archetype_shifted', 'lock_in_down_but_expiry_dominance_up'],
                'metric_gates' => [
                    ['metric' => 'star_purchase_density', 'direction' => 'up', 'min_improvement' => 0.0, 'label' => 'Star purchase density must not fall.'],
                    ['metric' => 'first_choice_viability', 'direction' => 'up', 'min_improvement' => 0.0, 'label' => 'Early star access must not fall.'],
                    ['metric' => 'star_price_cap_share', 'direction' => 'down', 'min_improvement' => 0.0, 'label' => 'Time at price cap must not rise.'],
                    ['metric' => 'star_price_range_ratio', 'direction' => 'down', 'min_improvement' => 0.0, 'label' => 'Price range volatility must not rise.'],
                ],
                'proves' => 'The focused star-market slice did not show weaker affordability or more unstable price movement.',
                'cannot_prove' => 'Does not prove production star-price behavior under the full player mix or full-duration seasons.',
            ],
        ];
    }

    public static function family(string $familyId): array
    {
        $families = self::families();
        if (!isset($families[$familyId])) {
            throw new InvalidArgumentException('Unknown coupling harness family: ' . $familyId);
        }

        return $families[$familyId];
    }

    public static function subsystemFamilyMap(): array
    {
        return [
            'hoarding_pressure' => ['hoarding_pressure_imbalance'],
            'blackout_lockin' => ['lock_in_down_but_expiry_dominance_up'],
            'boost_viability' => ['boost_underperformance'],
            'concentration_control' => ['hoarding_pressure_imbalance', 'skip_rejoin_exploit_worsened'],
            'onboarding_economy' => ['star_affordability_pricing_instability'],
            'star_pricing' => ['star_affordability_pricing_instability'],
            'lockin_incentives' => ['lock_in_down_but_expiry_dominance_up'],
            'expiry_pressure' => ['lock_in_down_but_expiry_dominance_up'],
            'retention_repeat_season' => ['skip_rejoin_exploit_worsened'],
        ];
    }

    public static function estimateWorkUnits(array $profile): float
    {
        $playerCount = max(1, (int)$profile['players_per_archetype']) * max(1, count((array)($profile['archetype_keys'] ?? [])) ?: 10);
        $seasonCount = max(1, (int)($profile['season_count'] ?? (in_array('C', (array)($profile['simulators'] ?? []), true) ? 4 : 1)));
        $duration = max(1, (int)($profile['season_duration_ticks'] ?? 1));
        $simCount = max(1, count((array)($profile['simulators'] ?? [])));
        $seedCount = max(1, count((array)($profile['seeds'] ?? [])));

        return (float)$playerCount * $seasonCount * $duration * $simCount * $seedCount;
    }
}

class AgenticCouplingHarnessEvaluator
{
    public static function evaluateFamily(array $family, array $baselineMetrics, array $candidateMetrics, array $regressionFlags = []): array
    {
        $gateResults = [];
        $failedMetrics = [];

        foreach ((array)$family['metric_gates'] as $gate) {
            $metric = (string)$gate['metric'];
            $direction = (string)$gate['direction'];
            $baseline = (float)($baselineMetrics[$metric] ?? 0.0);
            $candidate = (float)($candidateMetrics[$metric] ?? 0.0);
            $delta = $candidate - $baseline;
            $improvement = ($direction === 'down') ? ($baseline - $candidate) : $delta;
            $threshold = (float)($gate['min_improvement'] ?? 0.0);
            $passed = $improvement >= $threshold;

            $gateResults[] = [
                'metric' => $metric,
                'label' => (string)($gate['label'] ?? $metric),
                'direction' => $direction,
                'baseline' => $baseline,
                'candidate' => $candidate,
                'delta' => $delta,
                'improvement_toward_goal' => $improvement,
                'threshold_min_improvement' => $threshold,
                'status' => $passed ? 'pass' : 'fail',
                'diagnostic' => self::buildMetricDiagnostic($metric, $direction, $delta, $improvement, $threshold),
            ];

            if (!$passed) {
                $failedMetrics[] = $metric;
            }
        }

        $blockingFlags = array_values(array_intersect((array)$family['blocking_flags'], $regressionFlags));
        $passed = ($failedMetrics === []) && ($blockingFlags === []);

        return [
            'family_id' => (string)$family['family_id'],
            'label' => (string)$family['label'],
            'profile_id' => (string)$family['profile_id'],
            'description' => (string)$family['description'],
            'status' => $passed ? 'pass' : 'fail',
            'metric_results' => $gateResults,
            'failed_metrics' => $failedMetrics,
            'blocking_flags' => $blockingFlags,
            'regression_flags' => array_values($regressionFlags),
            'proves' => (string)$family['proves'],
            'cannot_prove' => (string)$family['cannot_prove'],
        ];
    }

    public static function runFamilies(
        AgenticHarnessRunner $runner,
        array $decomposition,
        array $baselineConfig,
        array $candidateConfig,
        array $candidateChanges,
        array $familyIds,
        string $labelPrefix
    ): array {
        $families = [];
        $fullProfile = (array)$decomposition['profiles']['tier3_full'];
        $fullWorkUnits = max(1.0, AgenticCouplingHarnessCatalog::estimateWorkUnits($fullProfile));

        foreach ($familyIds as $familyId) {
            $family = AgenticCouplingHarnessCatalog::family((string)$familyId);
            $profile = (array)$decomposition['profiles'][(string)$family['profile_id']];

            $baselineEval = $runner->evaluate($baselineConfig, $profile, $labelPrefix . '-baseline-' . $familyId, [], $baselineConfig);
            $candidateEval = $runner->evaluate($candidateConfig, $profile, $labelPrefix . '-candidate-' . $familyId, $candidateChanges, $baselineConfig);

            $baselineMetrics = (array)$baselineEval['metrics'];
            $candidateMetrics = (array)$candidateEval['metrics'];
            $regressionFlags = AgenticMetricEvaluator::regressionFlags($baselineMetrics, $candidateMetrics);
            $familyResult = self::evaluateFamily($family, $baselineMetrics, $candidateMetrics, $regressionFlags);
            $profileWorkUnits = max(1.0, AgenticCouplingHarnessCatalog::estimateWorkUnits($profile));

            $families[] = array_merge($familyResult, [
                'profile' => [
                    'tier' => (string)($profile['tier'] ?? 'tier1'),
                    'simulators' => array_values((array)($profile['simulators'] ?? [])),
                    'players_per_archetype' => (int)($profile['players_per_archetype'] ?? 0),
                    'season_count' => (int)($profile['season_count'] ?? 1),
                    'season_duration_ticks' => (int)($profile['season_duration_ticks'] ?? 0),
                    'blackout_duration_ticks' => (int)($profile['blackout_duration_ticks'] ?? 0),
                    'estimated_work_units' => $profileWorkUnits,
                    'estimated_cost_ratio_vs_tier3_full' => round($profileWorkUnits / $fullWorkUnits, 6),
                    'estimated_speedup_vs_tier3_full' => round($fullWorkUnits / $profileWorkUnits, 2),
                ],
                'baseline' => [
                    'runtime_secs' => (float)($baselineEval['runtime_secs'] ?? 0.0),
                    'cache_hit' => (bool)($baselineEval['cache_hit'] ?? false),
                    'metrics' => $baselineMetrics,
                    'season_paths' => (array)($baselineEval['season_paths'] ?? []),
                    'lifetime_paths' => (array)($baselineEval['lifetime_paths'] ?? []),
                    'season_audit_paths' => (array)($baselineEval['season_audit_paths'] ?? []),
                    'lifetime_audit_paths' => (array)($baselineEval['lifetime_audit_paths'] ?? []),
                ],
                'candidate' => [
                    'runtime_secs' => (float)($candidateEval['runtime_secs'] ?? 0.0),
                    'cache_hit' => (bool)($candidateEval['cache_hit'] ?? false),
                    'metrics' => $candidateMetrics,
                    'season_paths' => (array)($candidateEval['season_paths'] ?? []),
                    'lifetime_paths' => (array)($candidateEval['lifetime_paths'] ?? []),
                    'season_audit_paths' => (array)($candidateEval['season_audit_paths'] ?? []),
                    'lifetime_audit_paths' => (array)($candidateEval['lifetime_audit_paths'] ?? []),
                ],
            ]);
        }

        $failedFamilies = array_values(array_map(
            static fn(array $row): string => (string)$row['family_id'],
            array_values(array_filter($families, static fn(array $row): bool => (string)$row['status'] !== 'pass'))
        ));

        return [
            'status' => $failedFamilies === [] ? 'pass' : 'fail',
            'families' => $families,
            'failed_family_ids' => $failedFamilies,
        ];
    }

    private static function buildMetricDiagnostic(string $metric, string $direction, float $delta, float $improvement, float $threshold): string
    {
        $roundedDelta = round($delta, 6);
        $roundedImprovement = round($improvement, 6);
        $roundedThreshold = round($threshold, 6);
        $verb = $delta > 0 ? 'rose' : ($delta < 0 ? 'fell' : 'held');

        if ($direction === 'down') {
            return sprintf(
                '%s %s; goal-facing improvement=%s (need >= %s).',
                $metric,
                $verb . ' by ' . abs($roundedDelta),
                $roundedImprovement,
                $roundedThreshold
            );
        }

        return sprintf(
            '%s %s; goal-facing improvement=%s (need >= %s).',
            $metric,
            $verb . ' by ' . abs($roundedDelta),
            $roundedImprovement,
            $roundedThreshold
        );
    }
}

class AgenticSearchMemory
{
    private string $path;
    private array $state;

    public function __construct(string $path)
    {
        $this->path = $path;
        AgenticOptimizationUtils::ensureDir(dirname($this->path));

        if (is_file($this->path)) {
            $decoded = json_decode((string)file_get_contents($this->path), true);
            if (is_array($decoded)) {
                $this->state = $decoded;
                return;
            }
        }

        $this->state = [
            'schema_version' => 'tmc-agentic-search-memory.v1',
            'updated_at' => gmdate('c'),
            'rejected_candidates' => [],
            'local_winners' => [],
            'local_winner_global_rejects' => [],
            'sensitivity_findings' => [],
            'conflict_log' => [],
            'candidate_hashes' => [],
            'failure_patterns_by_parameter' => [],
        ];
    }

    public function hasCandidateHash(string $hash): bool
    {
        return !empty($this->state['candidate_hashes'][$hash]);
    }

    public function markCandidateHash(string $hash): void
    {
        $this->state['candidate_hashes'][$hash] = true;
    }

    public function recordReject(array $record): void
    {
        $this->state['rejected_candidates'][] = $record;

        foreach ((array)($record['changes'] ?? []) as $change) {
            $key = (string)($change['key'] ?? 'unknown');
            if (!isset($this->state['failure_patterns_by_parameter'][$key])) {
                $this->state['failure_patterns_by_parameter'][$key] = ['count' => 0, 'flags' => []];
            }
            $this->state['failure_patterns_by_parameter'][$key]['count']++;
            foreach ((array)($record['regression_flags'] ?? []) as $flag) {
                $this->state['failure_patterns_by_parameter'][$key]['flags'][$flag] =
                    (int)($this->state['failure_patterns_by_parameter'][$key]['flags'][$flag] ?? 0) + 1;
            }
        }
    }

    public function recordLocalWinner(array $record): void
    {
        $this->state['local_winners'][] = $record;
    }

    public function recordLocalWinnerGlobalReject(array $record): void
    {
        $this->state['local_winner_global_rejects'][] = $record;
    }

    public function recordSensitivity(array $record): void
    {
        $this->state['sensitivity_findings'][] = $record;
    }

    public function recordConflict(array $record): void
    {
        $this->state['conflict_log'][] = $record;
    }

    public function getParameterFailureCount(string $key): int
    {
        return (int)($this->state['failure_patterns_by_parameter'][$key]['count'] ?? 0);
    }

    public function snapshot(): array
    {
        return $this->state;
    }

    public function persist(): void
    {
        $this->state['updated_at'] = gmdate('c');
        AgenticOptimizationUtils::writeJson($this->path, $this->state);
    }
}

class AgenticRejectedIterationAuditor
{
    public static function run(string $repoRoot, string $outputDir): array
    {
        $events = [];

        $v2SummaryPath = $repoRoot . DIRECTORY_SEPARATOR . 'simulation_output' . DIRECTORY_SEPARATOR . 'current-db'
            . DIRECTORY_SEPARATOR . 'verification-v2' . DIRECTORY_SEPARATOR . 'verification_summary_v2.json';
        if (is_file($v2SummaryPath)) {
            $v2 = json_decode((string)file_get_contents($v2SummaryPath), true);
            if (is_array($v2)) {
                foreach ((array)($v2['packages'] ?? []) as $package) {
                    $packageName = (string)($package['package_name'] ?? 'unknown');
                    foreach ((array)($package['per_seed'] ?? []) as $seedRow) {
                        if ((string)($seedRow['disposition'] ?? '') !== 'reject') {
                            continue;
                        }
                        $events[] = [
                            'source' => 'verification-v2',
                            'event_id' => 'v2-' . $packageName . '-' . (string)$seedRow['seed'],
                            'package' => $packageName,
                            'seed' => (string)$seedRow['seed'],
                            'wins' => (int)($seedRow['wins'] ?? 0),
                            'losses' => (int)($seedRow['losses'] ?? 0),
                            'regression_flags' => array_values((array)($seedRow['regression_flags'] ?? [])),
                            'disposition' => (string)$seedRow['disposition'],
                            'confidence_notes' => (string)($seedRow['confidence_notes'] ?? ''),
                        ];
                    }
                }
            }
        }

        $v3ComparisonPath = $repoRoot . DIRECTORY_SEPARATOR . 'simulation_output' . DIRECTORY_SEPARATOR . 'current-db'
            . DIRECTORY_SEPARATOR . 'comparisons-v3-fast' . DIRECTORY_SEPARATOR . 'comparison_tuning-verify-v3-fast-1.json';
        if (is_file($v3ComparisonPath)) {
            $v3 = json_decode((string)file_get_contents($v3ComparisonPath), true);
            if (is_array($v3)) {
                foreach ((array)($v3['scenarios'] ?? []) as $scenario) {
                    if ((string)($scenario['recommended_disposition'] ?? '') !== 'reject') {
                        continue;
                    }
                    $scenarioName = (string)($scenario['scenario_name'] ?? 'unknown');
                    $events[] = [
                        'source' => 'verification-v3-fast',
                        'event_id' => 'v3-fast-' . $scenarioName,
                        'package' => $scenarioName,
                        'seed' => (string)($v3['seed'] ?? 'v3-fast-seed'),
                        'wins' => (int)($scenario['wins'] ?? 0),
                        'losses' => (int)($scenario['losses'] ?? 0),
                        'regression_flags' => array_values((array)($scenario['regression_flags'] ?? [])),
                        'disposition' => (string)($scenario['recommended_disposition'] ?? 'reject'),
                        'confidence_notes' => (string)($scenario['confidence_notes'] ?? ''),
                    ];
                }
            }
        }

        // Most-recent approximation: prioritize v3-fast entries first, then v2 entries.
        usort($events, static function ($a, $b) {
            $priorityA = str_contains((string)$a['source'], 'v3') ? 0 : 1;
            $priorityB = str_contains((string)$b['source'], 'v3') ? 0 : 1;
            if ($priorityA !== $priorityB) {
                return $priorityA <=> $priorityB;
            }
            return strcmp((string)$a['event_id'], (string)$b['event_id']);
        });

        $recent = array_slice($events, 0, 8);

        $flagHistogram = [];
        foreach ($recent as &$event) {
            $flags = (array)$event['regression_flags'];
            foreach ($flags as $flag) {
                $flagHistogram[$flag] = (int)($flagHistogram[$flag] ?? 0) + 1;
            }

            $event['classifications'] = self::classify($event);
        }
        unset($event);

        arsort($flagHistogram);

        $report = [
            'schema_version' => 'tmc-agentic-reject-audit.v1',
            'generated_at' => gmdate('c'),
            'audited_events_count' => count($recent),
            'audited_events' => $recent,
            'flag_histogram' => $flagHistogram,
            'key_failure_patterns' => array_slice(array_keys($flagHistogram), 0, 6),
        ];

        AgenticOptimizationUtils::ensureDir($outputDir);
        AgenticOptimizationUtils::writeJson($outputDir . DIRECTORY_SEPARATOR . 'rejected_iteration_audit.json', $report);

        $md = [];
        $md[] = '# Rejected Iteration Audit';
        $md[] = '';
        $md[] = 'Generated: ' . $report['generated_at'];
        $md[] = 'Audited reject events: ' . $report['audited_events_count'];
        $md[] = '';
        $md[] = '## Event Classification';
        $md[] = '';
        foreach ($report['audited_events'] as $event) {
            $md[] = '- `' . $event['event_id'] . '` (' . $event['source'] . '): '
                . implode(', ', (array)$event['classifications'])
                . ' | wins=' . $event['wins'] . ' losses=' . $event['losses']
                . ' | flags=' . implode(', ', (array)$event['regression_flags']);
        }
        $md[] = '';
        $md[] = '## Dominant Failure Patterns';
        $md[] = '';
        foreach ($flagHistogram as $flag => $count) {
            $md[] = '- `' . $flag . '`: ' . $count;
        }

        file_put_contents($outputDir . DIRECTORY_SEPARATOR . 'rejected_iteration_audit.md', implode(PHP_EOL, $md));

        return $report;
    }

    private static function classify(array $event): array
    {
        $categories = [];
        $wins = (int)($event['wins'] ?? 0);
        $losses = (int)($event['losses'] ?? 0);
        $flags = (array)($event['regression_flags'] ?? []);

        if ($wins <= $losses) {
            $categories[] = 'local_weakness';
        }

        if (in_array('candidate_improves_B_but_worsens_C', $flags, true)
            || in_array('reduces_lock_in_but_expiry_dominance_rises', $flags, true)
            || in_array('reduced_one_dominant_but_created_new_dominant', $flags, true)) {
            $categories[] = 'cross_system_regression';
        }

        if (in_array('dominant_archetype_shifted', $flags, true)
            || in_array('long_run_concentration_worsened', $flags, true)
            || in_array('skip_rejoin_exploit_worsened', $flags, true)
            || in_array('lock_in_down_but_expiry_dominance_up', $flags, true)) {
            $categories[] = 'full_economy_regression';
        }

        if ($wins > $losses && !empty($flags)) {
            $categories[] = 'bad_metric_targeting';
        }

        if (count($flags) >= 4) {
            $categories[] = 'over_broad_change';
        }

        if (str_contains((string)($event['confidence_notes'] ?? ''), 'Paired samples: 2')) {
            $categories[] = 'insufficient_signal';
        }

        $alwaysRepeated = [
            'dominant_archetype_shifted',
            'lock_in_down_but_expiry_dominance_up',
            'reduces_lock_in_but_expiry_dominance_rises',
        ];
        $repeatHits = 0;
        foreach ($alwaysRepeated as $flag) {
            if (in_array($flag, $flags, true)) {
                $repeatHits++;
            }
        }
        if ($repeatHits >= 2) {
            $categories[] = 'search_inefficiency';
        }

        if ($categories === []) {
            $categories[] = 'uncategorized';
        }

        return array_values(array_unique($categories));
    }
}

class AgenticRejectedIterationShadowParity
{
    /**
     * @return array<string, mixed>
     */
    public static function run(string $repoRoot, string $auditDir, array $legacyAudit, ?string $manifestPath = null): array
    {
        $manifestSelection = self::resolveManifestSelection($repoRoot, $manifestPath);
        $resolvedManifestPath = (string)$manifestSelection['selected_manifest_path'];
        $manifestSource = (string)$manifestSelection['manifest_source'];

        $diagnostic = [
            'schema_version' => 'tmc-agentic-shadow-parity.v1',
            'generated_at' => gmdate('c'),
            'mode' => 'shadow-manifest',
            'manifest_source' => $manifestSource,
            'requested_manifest_path' => $manifestSelection['requested_manifest_path'],
            'selected_manifest_path' => $resolvedManifestPath,
            'manifest_path' => $resolvedManifestPath,
            'shadow_status' => 'not_started',
            'fallback_occurred' => false,
            'fallback_reason' => null,
            'freshness_status' => 'unknown',
            'freshness_reasons' => [],
            'parity_result' => 'not_computed',
            'parity_pass' => false,
            'missing_sources' => [],
            'resolver_errors' => [],
            'resolver_warnings' => [],
            'legacy_rejected_iteration_audit' => self::coreProjection($legacyAudit),
            'shadow_rejected_iteration_audit' => null,
            'mismatches' => [],
        ];

        $resolverResult = AgenticRejectAuditManifestResolver::resolve(
            $resolvedManifestPath,
            dirname($resolvedManifestPath),
            true,
            [
                'validate_freshness' => $manifestSource === 'canonical_default',
                'validate_checksums' => $manifestSource === 'canonical_default',
                'repo_root' => $repoRoot,
                'max_age_seconds' => 86400,
                'strict_integrity' => false,
            ]
        );
        $diagnostic['resolver_errors'] = array_values((array)($resolverResult['errors'] ?? []));
        $diagnostic['resolver_warnings'] = array_values((array)($resolverResult['warnings'] ?? []));
        $diagnostic['missing_sources'] = array_values((array)($resolverResult['missing_sources'] ?? []));
        $diagnostic['freshness_status'] = self::resolveFreshnessStatus($resolverResult, $manifestSource);
        $diagnostic['freshness_reasons'] = self::resolveFreshnessReasons($resolverResult, $manifestSource);

        if (!(bool)($resolverResult['manifest_valid'] ?? false)) {
            $diagnostic['fallback_occurred'] = true;
            $diagnostic['shadow_status'] = self::resolverStatusToShadowStatus($resolverResult, $manifestSource);
            $diagnostic['fallback_reason'] = $diagnostic['shadow_status'];
            self::writeDiagnostic($auditDir, $diagnostic);
            return $diagnostic;
        }

        if ($manifestSource === 'canonical_default' && $diagnostic['freshness_status'] === 'stale') {
            $diagnostic['fallback_occurred'] = true;
            $diagnostic['shadow_status'] = 'canonical_manifest_stale';
            $diagnostic['fallback_reason'] = 'canonical_manifest_stale';
            self::writeDiagnostic($auditDir, $diagnostic);
            return $diagnostic;
        }

        if (in_array('primary', $diagnostic['missing_sources'], true)) {
            $diagnostic['fallback_occurred'] = true;
            $diagnostic['shadow_status'] = 'primary_missing';
            $diagnostic['fallback_reason'] = 'primary_missing';
            self::writeDiagnostic($auditDir, $diagnostic);
            return $diagnostic;
        }

        if (in_array('secondary', $diagnostic['missing_sources'], true)) {
            $diagnostic['fallback_occurred'] = true;
            $diagnostic['shadow_status'] = 'secondary_missing';
            $diagnostic['fallback_reason'] = 'secondary_missing';
            self::writeDiagnostic($auditDir, $diagnostic);
            return $diagnostic;
        }

        $shadowBuild = self::buildShadowAuditFromResolvedSources((array)($resolverResult['resolved_sources'] ?? []));
        if (!(bool)($shadowBuild['ok'] ?? false)) {
            $diagnostic['fallback_occurred'] = true;
            $diagnostic['shadow_status'] = 'shadow_build_failed';
            $diagnostic['fallback_reason'] = 'shadow_build_failed';
            $diagnostic['resolver_errors'][] = [
                'code' => (string)($shadowBuild['error_code'] ?? 'shadow_build_failed'),
                'path' => '/shadow',
                'message' => (string)($shadowBuild['message'] ?? 'Failed to build shadow audit payload'),
            ];
            self::writeDiagnostic($auditDir, $diagnostic);
            return $diagnostic;
        }

        $shadowAudit = (array)$shadowBuild['report'];
        $diagnostic['shadow_rejected_iteration_audit'] = self::coreProjection($shadowAudit);

        $parity = self::compareReports($legacyAudit, $shadowAudit);
        $diagnostic['mismatches'] = (array)$parity['mismatches'];
        $diagnostic['parity_pass'] = (bool)$parity['pass'];
        $diagnostic['parity_result'] = (bool)$parity['pass'] ? 'pass' : 'fail';
        $diagnostic['shadow_status'] = (bool)$parity['pass'] ? 'parity_pass' : 'parity_mismatch';
        $diagnostic['fallback_occurred'] = !(bool)$parity['pass'];
        $diagnostic['fallback_reason'] = (bool)$parity['pass'] ? null : 'parity_mismatch';

        self::writeDiagnostic($auditDir, $diagnostic);
        return $diagnostic;
    }

    /**
     * @return array{
     *   authoritative_source: string,
     *   authoritative_audit: array<string, mixed>,
     *   manifest_audit: array<string, mixed>|null,
     *   diagnostic: array<string, mixed>
     * }
     */
    public static function runManifestPreferred(string $repoRoot, string $auditDir, array $legacyAudit, ?string $manifestPath = null): array
    {
        $manifestSelection = self::resolveManifestSelection($repoRoot, $manifestPath);
        $resolvedManifestPath = (string)$manifestSelection['selected_manifest_path'];
        $manifestSource = (string)$manifestSelection['manifest_source'];

        $diagnostic = [
            'schema_version' => 'tmc.rejected_iteration_manifest_preferred.v1',
            'generated_at_utc' => gmdate('c'),
            'mode' => 'manifest_preferred',
            'manifest_source' => $manifestSource,
            'requested_manifest_path' => $manifestSelection['requested_manifest_path'],
            'selected_manifest_path' => $resolvedManifestPath,
            'authoritative_source' => 'legacy_fallback',
            'fallback_occurred' => true,
            'fallback_reason' => null,
            'freshness_status' => 'unknown',
            'freshness_reasons' => [],
            'parity_result' => 'not_computed',
            'parity_pass' => false,
            'mismatches' => [],
            'missing_sources' => [],
            'resolver_errors' => [],
            'resolver_warnings' => [],
            'legacy_rejected_iteration_audit' => self::coreProjection($legacyAudit),
            'manifest_rejected_iteration_audit' => null,
        ];

        $resolverResult = AgenticRejectAuditManifestResolver::resolve(
            $resolvedManifestPath,
            dirname($resolvedManifestPath),
            true,
            [
                'validate_freshness' => $manifestSource === 'canonical_default',
                'validate_checksums' => $manifestSource === 'canonical_default',
                'repo_root' => $repoRoot,
                'max_age_seconds' => 86400,
                'strict_integrity' => false,
            ]
        );
        $diagnostic['resolver_errors'] = array_values((array)($resolverResult['errors'] ?? []));
        $diagnostic['resolver_warnings'] = array_values((array)($resolverResult['warnings'] ?? []));
        $diagnostic['missing_sources'] = array_values((array)($resolverResult['missing_sources'] ?? []));
        $diagnostic['freshness_status'] = self::resolveFreshnessStatus($resolverResult, $manifestSource);
        $diagnostic['freshness_reasons'] = self::resolveFreshnessReasons($resolverResult, $manifestSource);

        if (!(bool)($resolverResult['manifest_valid'] ?? false)) {
            $diagnostic['fallback_reason'] = self::resolverStatusToShadowStatus($resolverResult, $manifestSource);
            self::writeManifestPreferredDiagnostic($auditDir, $diagnostic);
            return [
                'authoritative_source' => 'legacy_fallback',
                'authoritative_audit' => $legacyAudit,
                'manifest_audit' => null,
                'diagnostic' => $diagnostic,
            ];
        }

        if ($manifestSource === 'canonical_default' && $diagnostic['freshness_status'] === 'stale') {
            $diagnostic['fallback_reason'] = 'canonical_manifest_stale';
            self::writeManifestPreferredDiagnostic($auditDir, $diagnostic);
            return [
                'authoritative_source' => 'legacy_fallback',
                'authoritative_audit' => $legacyAudit,
                'manifest_audit' => null,
                'diagnostic' => $diagnostic,
            ];
        }

        if (in_array('primary', $diagnostic['missing_sources'], true)) {
            $diagnostic['fallback_reason'] = 'primary_missing';
            self::writeManifestPreferredDiagnostic($auditDir, $diagnostic);
            return [
                'authoritative_source' => 'legacy_fallback',
                'authoritative_audit' => $legacyAudit,
                'manifest_audit' => null,
                'diagnostic' => $diagnostic,
            ];
        }

        if (in_array('secondary', $diagnostic['missing_sources'], true)) {
            $diagnostic['fallback_reason'] = 'secondary_missing';
            self::writeManifestPreferredDiagnostic($auditDir, $diagnostic);
            return [
                'authoritative_source' => 'legacy_fallback',
                'authoritative_audit' => $legacyAudit,
                'manifest_audit' => null,
                'diagnostic' => $diagnostic,
            ];
        }

        $shadowBuild = self::buildShadowAuditFromResolvedSources((array)($resolverResult['resolved_sources'] ?? []));
        if (!(bool)($shadowBuild['ok'] ?? false)) {
            $diagnostic['fallback_reason'] = 'manifest_build_failed';
            $diagnostic['resolver_errors'][] = [
                'code' => (string)($shadowBuild['error_code'] ?? 'manifest_build_failed'),
                'path' => '/manifest',
                'message' => (string)($shadowBuild['message'] ?? 'Failed to build manifest-derived audit payload'),
            ];
            self::writeManifestPreferredDiagnostic($auditDir, $diagnostic);
            return [
                'authoritative_source' => 'legacy_fallback',
                'authoritative_audit' => $legacyAudit,
                'manifest_audit' => null,
                'diagnostic' => $diagnostic,
            ];
        }

        $manifestAudit = (array)$shadowBuild['report'];
        $diagnostic['manifest_rejected_iteration_audit'] = self::coreProjection($manifestAudit);

        $parity = self::compareReports($legacyAudit, $manifestAudit);
        $diagnostic['mismatches'] = (array)$parity['mismatches'];
        $diagnostic['parity_pass'] = (bool)$parity['pass'];
        $diagnostic['parity_result'] = (bool)$parity['pass'] ? 'pass' : 'fail';

        if (!(bool)$parity['pass']) {
            $diagnostic['fallback_reason'] = 'parity_mismatch';
            self::writeManifestPreferredDiagnostic($auditDir, $diagnostic);
            return [
                'authoritative_source' => 'legacy_fallback',
                'authoritative_audit' => $legacyAudit,
                'manifest_audit' => $manifestAudit,
                'diagnostic' => $diagnostic,
            ];
        }

        $diagnostic['authoritative_source'] = 'manifest';
        $diagnostic['fallback_occurred'] = false;
        $diagnostic['fallback_reason'] = null;

        self::writeManifestPreferredDiagnostic($auditDir, $diagnostic);
        return [
            'authoritative_source' => 'manifest',
            'authoritative_audit' => $manifestAudit,
            'manifest_audit' => $manifestAudit,
            'diagnostic' => $diagnostic,
        ];
    }

    /**
     * @return array{manifest_source: string, requested_manifest_path: ?string, selected_manifest_path: string}
     */
    private static function resolveManifestSelection(string $repoRoot, ?string $manifestPath): array
    {
        $candidate = trim((string)$manifestPath);
        if ($candidate === '') {
            return [
                'manifest_source' => 'canonical_default',
                'requested_manifest_path' => null,
                'selected_manifest_path' => $repoRoot
                . DIRECTORY_SEPARATOR . 'simulation_output'
                . DIRECTORY_SEPARATOR . 'current-db'
                . DIRECTORY_SEPARATOR . 'rejected-iteration-inputs'
                . DIRECTORY_SEPARATOR . 'current'
                . DIRECTORY_SEPARATOR . 'manifest.json',
            ];
        }

        $isWindowsAbsolute = preg_match('/^[A-Za-z]:[\\\\\\/]/', $candidate) === 1;
        $isUnixAbsolute = str_starts_with($candidate, '/');
        $selectedPath = '';
        if ($isWindowsAbsolute || $isUnixAbsolute) {
            $selectedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $candidate);
        } else {
            $selectedPath = $repoRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $candidate);
        }

        return [
            'manifest_source' => 'override',
            'requested_manifest_path' => $candidate,
            'selected_manifest_path' => $selectedPath,
        ];
    }

    /**
     * @param array<string, mixed> $resolverResult
     */
    private static function resolverStatusToShadowStatus(array $resolverResult, string $manifestSource): string
    {
        $manifestMissing = false;
        foreach ((array)($resolverResult['errors'] ?? []) as $error) {
            $code = (string)($error['code'] ?? '');
            if ($code === 'manifest_missing') {
                $manifestMissing = true;
                break;
            }
        }
        if ($manifestSource === 'canonical_default') {
            return $manifestMissing ? 'canonical_manifest_missing' : 'canonical_manifest_invalid';
        }
        return $manifestMissing ? 'manifest_missing' : 'manifest_invalid';
    }

    /**
     * @param array<string, mixed> $resolverResult
     * @return array<int, string>
     */
    private static function resolveFreshnessReasons(array $resolverResult, string $manifestSource): array
    {
        if ($manifestSource !== 'canonical_default') {
            return [];
        }

        $reasons = array_values((array)($resolverResult['integrity']['freshness']['reasons'] ?? []));
        foreach (array_merge((array)($resolverResult['errors'] ?? []), (array)($resolverResult['warnings'] ?? [])) as $entry) {
            $code = (string)($entry['code'] ?? '');
            if ($code === 'manifest_missing') {
                $reasons[] = 'manifest_not_found';
            } elseif (in_array($code, ['manifest_invalid_json', 'required_key_missing', 'invalid_type', 'unknown_key'], true)) {
                $reasons[] = 'schema_invalid';
            } elseif ($code === 'checksum_mismatch') {
                $path = (string)($entry['path'] ?? '');
                if (str_contains($path, '/primary/')) {
                    $reasons[] = 'checksum_mismatch_primary';
                } elseif (str_contains($path, '/secondary/')) {
                    $reasons[] = 'checksum_mismatch_secondary';
                }
            }
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @param array<string, mixed> $resolverResult
     */
    private static function resolveFreshnessStatus(array $resolverResult, string $manifestSource): string
    {
        if ($manifestSource !== 'canonical_default') {
            return 'unknown';
        }
        $status = (string)($resolverResult['integrity']['freshness']['status'] ?? 'unknown');
        $reasons = self::resolveFreshnessReasons($resolverResult, $manifestSource);
        if ($status === 'stale' || in_array('checksum_mismatch_primary', $reasons, true) || in_array('checksum_mismatch_secondary', $reasons, true)) {
            return 'stale';
        }
        if ($status === 'fresh') {
            return 'fresh';
        }
        return 'unknown';
    }

    /**
     * @param array<string, mixed> $resolvedSources
     * @return array<string, mixed>
     */
    private static function buildShadowAuditFromResolvedSources(array $resolvedSources): array
    {
        $primaryPath = (string)($resolvedSources['primary']['resolved_path'] ?? '');
        $secondaryPath = (string)($resolvedSources['secondary']['resolved_path'] ?? '');
        if ($primaryPath === '' || $secondaryPath === '') {
            return [
                'ok' => false,
                'error_code' => 'source_path_unavailable',
                'message' => 'Resolved source path missing for primary or secondary source',
            ];
        }

        $primary = json_decode((string)file_get_contents($primaryPath), true);
        if (!is_array($primary)) {
            return [
                'ok' => false,
                'error_code' => 'primary_invalid_json',
                'message' => 'Primary source JSON is invalid',
            ];
        }

        $secondary = json_decode((string)file_get_contents($secondaryPath), true);
        if (!is_array($secondary)) {
            return [
                'ok' => false,
                'error_code' => 'secondary_invalid_json',
                'message' => 'Secondary source JSON is invalid',
            ];
        }

        $events = [];
        $events = array_merge($events, self::extractEventsFromSecondarySummary($secondary));
        $events = array_merge($events, self::extractEventsFromPrimaryComparison($primary));

        return [
            'ok' => true,
            'report' => self::buildAuditReportFromEvents($events),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function extractEventsFromSecondarySummary(array $secondary): array
    {
        $events = [];
        foreach ((array)($secondary['packages'] ?? []) as $package) {
            $packageName = (string)($package['package_name'] ?? 'unknown');
            foreach ((array)($package['per_seed'] ?? []) as $seedRow) {
                if ((string)($seedRow['disposition'] ?? '') !== 'reject') {
                    continue;
                }
                $events[] = [
                    'source' => 'verification-v2',
                    'event_id' => 'v2-' . $packageName . '-' . (string)$seedRow['seed'],
                    'package' => $packageName,
                    'seed' => (string)$seedRow['seed'],
                    'wins' => (int)($seedRow['wins'] ?? 0),
                    'losses' => (int)($seedRow['losses'] ?? 0),
                    'regression_flags' => array_values((array)($seedRow['regression_flags'] ?? [])),
                    'disposition' => (string)$seedRow['disposition'],
                    'confidence_notes' => (string)($seedRow['confidence_notes'] ?? ''),
                ];
            }
        }
        return $events;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function extractEventsFromPrimaryComparison(array $primary): array
    {
        $events = [];
        foreach ((array)($primary['scenarios'] ?? []) as $scenario) {
            if ((string)($scenario['recommended_disposition'] ?? '') !== 'reject') {
                continue;
            }
            $scenarioName = (string)($scenario['scenario_name'] ?? 'unknown');
            $events[] = [
                'source' => 'verification-v3-fast',
                'event_id' => 'v3-fast-' . $scenarioName,
                'package' => $scenarioName,
                'seed' => (string)($primary['seed'] ?? 'v3-fast-seed'),
                'wins' => (int)($scenario['wins'] ?? 0),
                'losses' => (int)($scenario['losses'] ?? 0),
                'regression_flags' => array_values((array)($scenario['regression_flags'] ?? [])),
                'disposition' => (string)($scenario['recommended_disposition'] ?? 'reject'),
                'confidence_notes' => (string)($scenario['confidence_notes'] ?? ''),
            ];
        }
        return $events;
    }

    /**
     * @param array<int, array<string, mixed>> $events
     * @return array<string, mixed>
     */
    private static function buildAuditReportFromEvents(array $events): array
    {
        usort($events, static function ($a, $b) {
            $priorityA = str_contains((string)$a['source'], 'v3') ? 0 : 1;
            $priorityB = str_contains((string)$b['source'], 'v3') ? 0 : 1;
            if ($priorityA !== $priorityB) {
                return $priorityA <=> $priorityB;
            }
            return strcmp((string)$a['event_id'], (string)$b['event_id']);
        });

        $recent = array_slice($events, 0, 8);

        $flagHistogram = [];
        foreach ($recent as &$event) {
            $flags = (array)$event['regression_flags'];
            foreach ($flags as $flag) {
                $flagHistogram[$flag] = (int)($flagHistogram[$flag] ?? 0) + 1;
            }
            $event['classifications'] = self::classify($event);
        }
        unset($event);
        arsort($flagHistogram);

        return [
            'schema_version' => 'tmc-agentic-reject-audit.v1',
            'generated_at' => gmdate('c'),
            'audited_events_count' => count($recent),
            'audited_events' => $recent,
            'flag_histogram' => $flagHistogram,
            'key_failure_patterns' => array_slice(array_keys($flagHistogram), 0, 6),
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function classify(array $event): array
    {
        $categories = [];
        $wins = (int)($event['wins'] ?? 0);
        $losses = (int)($event['losses'] ?? 0);
        $flags = (array)($event['regression_flags'] ?? []);

        if ($wins <= $losses) {
            $categories[] = 'local_weakness';
        }

        if (in_array('candidate_improves_B_but_worsens_C', $flags, true)
            || in_array('reduces_lock_in_but_expiry_dominance_rises', $flags, true)
            || in_array('reduced_one_dominant_but_created_new_dominant', $flags, true)) {
            $categories[] = 'cross_system_regression';
        }

        if (in_array('dominant_archetype_shifted', $flags, true)
            || in_array('long_run_concentration_worsened', $flags, true)
            || in_array('skip_rejoin_exploit_worsened', $flags, true)
            || in_array('lock_in_down_but_expiry_dominance_up', $flags, true)) {
            $categories[] = 'full_economy_regression';
        }

        if ($wins > $losses && !empty($flags)) {
            $categories[] = 'bad_metric_targeting';
        }

        if (count($flags) >= 4) {
            $categories[] = 'over_broad_change';
        }

        if (str_contains((string)($event['confidence_notes'] ?? ''), 'Paired samples: 2')) {
            $categories[] = 'insufficient_signal';
        }

        $alwaysRepeated = [
            'dominant_archetype_shifted',
            'lock_in_down_but_expiry_dominance_up',
            'reduces_lock_in_but_expiry_dominance_rises',
        ];
        $repeatHits = 0;
        foreach ($alwaysRepeated as $flag) {
            if (in_array($flag, $flags, true)) {
                $repeatHits++;
            }
        }
        if ($repeatHits >= 2) {
            $categories[] = 'search_inefficiency';
        }

        if ($categories === []) {
            $categories[] = 'uncategorized';
        }

        return array_values(array_unique($categories));
    }

    /**
     * @return array<string, mixed>
     */
    private static function compareReports(array $legacyAudit, array $shadowAudit): array
    {
        $mismatches = [];

        foreach (['audited_events_count', 'flag_histogram', 'key_failure_patterns'] as $field) {
            if (($legacyAudit[$field] ?? null) !== ($shadowAudit[$field] ?? null)) {
                $mismatches[] = [
                    'field' => $field,
                    'legacy_value' => $legacyAudit[$field] ?? null,
                    'shadow_value' => $shadowAudit[$field] ?? null,
                ];
            }
        }

        $legacyEvents = array_values((array)($legacyAudit['audited_events'] ?? []));
        $shadowEvents = array_values((array)($shadowAudit['audited_events'] ?? []));
        if ($legacyEvents !== $shadowEvents) {
            $mismatches[] = [
                'field' => 'audited_events',
                'legacy_count' => count($legacyEvents),
                'shadow_count' => count($shadowEvents),
            ];

            $max = min(max(count($legacyEvents), count($shadowEvents)), 25);
            for ($i = 0; $i < $max; $i++) {
                $legacyEvent = $legacyEvents[$i] ?? null;
                $shadowEvent = $shadowEvents[$i] ?? null;
                if ($legacyEvent !== $shadowEvent) {
                    $mismatches[] = [
                        'field' => 'audited_events[' . $i . ']',
                        'legacy_event_id' => (string)($legacyEvent['event_id'] ?? ''),
                        'shadow_event_id' => (string)($shadowEvent['event_id'] ?? ''),
                        'legacy_value' => $legacyEvent,
                        'shadow_value' => $shadowEvent,
                    ];
                }
            }
        }

        return [
            'pass' => $mismatches === [],
            'mismatches' => $mismatches,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function coreProjection(array $audit): array
    {
        return [
            'audited_events_count' => (int)($audit['audited_events_count'] ?? 0),
            'key_failure_patterns' => array_values((array)($audit['key_failure_patterns'] ?? [])),
            'flag_histogram' => (array)($audit['flag_histogram'] ?? []),
        ];
    }

    /**
     * @param array<string, mixed> $diagnostic
     */
    private static function writeDiagnostic(string $auditDir, array $diagnostic): void
    {
        AgenticOptimizationUtils::writeJson(
            $auditDir . DIRECTORY_SEPARATOR . 'rejected_iteration_shadow_parity.json',
            $diagnostic
        );
    }

    /**
     * @param array<string, mixed> $diagnostic
     */
    private static function writeManifestPreferredDiagnostic(string $auditDir, array $diagnostic): void
    {
        AgenticOptimizationUtils::writeJson(
            $auditDir . DIRECTORY_SEPARATOR . 'rejected_iteration_manifest_preferred_diagnostic.json',
            $diagnostic
        );
    }
}

class AgenticSubsystemAgent
{
    private array $subsystem;
    private array $decomposition;
    private AgenticHarnessRunner $harness;
    private AgenticSearchMemory $memory;
    private string $runLabelPrefix;

    public function __construct(array $subsystem, array $decomposition, AgenticHarnessRunner $harness, AgenticSearchMemory $memory, string $runLabelPrefix)
    {
        $this->subsystem = $subsystem;
        $this->decomposition = $decomposition;
        $this->harness = $harness;
        $this->memory = $memory;
        $this->runLabelPrefix = $runLabelPrefix;
    }

    public function optimize(array $currentConfig): array
    {
        $localProfile = $this->decomposition['profiles'][$this->subsystem['local_profile']];
        $integrationProfile = $this->decomposition['profiles']['tier2_integration'];
        $fullProfile = $this->decomposition['profiles']['tier3_full'];

        $baselineLocal = $this->harness->evaluate($currentConfig, $localProfile, $this->runLabelPrefix . '-baseline-local-' . $this->subsystem['id']);
        $baselineIntegration = $this->harness->evaluate($currentConfig, $integrationProfile, $this->runLabelPrefix . '-baseline-int-' . $this->subsystem['id']);
        $baselineFull = $this->harness->evaluate($currentConfig, $fullProfile, $this->runLabelPrefix . '-baseline-full-' . $this->subsystem['id']);

        $baselineLocalMetrics = (array)$baselineLocal['metrics'];
        $baselineIntegrationMetrics = (array)$baselineIntegration['metrics'];
        $baselineFullMetrics = (array)$baselineFull['metrics'];

        $candidates = $this->generateCandidates($currentConfig);
        $maxCandidates = max(1, (int)($this->subsystem['search_limits']['max_candidates'] ?? 4));
        if (count($candidates) > $maxCandidates) {
            $candidates = array_slice($candidates, 0, $maxCandidates);
        }
        $maxAccepted = max(1, (int)($this->subsystem['search_limits']['max_accepted'] ?? 1));

        $tested = [];
        $localWinners = [];

        foreach ($candidates as $index => $candidate) {
            $candidateHash = AgenticOptimizationUtils::jsonHash([
                'subsystem' => $this->subsystem['id'],
                'changes' => $candidate['changes'],
                'config' => AgenticOptimizationUtils::convertSeasonForJson($candidate['config']),
            ]);

            if ($this->memory->hasCandidateHash($candidateHash)) {
                continue;
            }
            $this->memory->markCandidateHash($candidateHash);

            $label = $this->runLabelPrefix . '-' . $this->subsystem['id'] . '-cand-' . ($index + 1);
            $localEval = $this->harness->evaluate($candidate['config'], $localProfile, $label . '-tier1', (array)$candidate['changes'], $currentConfig);

            $localMetrics = (array)$localEval['metrics'];
            $localScoring = AgenticMetricEvaluator::localScore(
                $baselineLocalMetrics,
                $localMetrics,
                (array)$this->subsystem['local_objectives']
            );

            $localFlags = AgenticMetricEvaluator::regressionFlags($baselineLocalMetrics, $localMetrics);
            $localScore = (float)$localScoring['score'];
            $gateTier1 = (float)$this->subsystem['promotion_gates']['tier1_min_local_score'];

            $record = [
                'subsystem_id' => $this->subsystem['id'],
                'candidate_id' => $label,
                'changes' => $candidate['changes'],
                'local_score' => $localScore,
                'local_score_parts' => $localScoring['parts'],
                'local_regression_flags' => $localFlags,
                'tier1_cache_hit' => (bool)$localEval['cache_hit'],
                'status' => 'tier1_reject',
                'tier_results' => [
                    'tier1' => [
                        'profile_id' => $localProfile['id'],
                        'metrics' => $localMetrics,
                        'global_score' => AgenticMetricEvaluator::globalScore($localMetrics),
                    ],
                ],
            ];

            // Sensitivity tracking is recorded for every local candidate.
            foreach ((array)$candidate['changes'] as $change) {
                $this->memory->recordSensitivity([
                    'subsystem_id' => $this->subsystem['id'],
                    'parameter' => $change['key'],
                    'mutation' => $change['mutation_label'],
                    'local_score' => $localScore,
                    'local_regression_flags' => $localFlags,
                ]);
            }

            $couplingFamilies = array_values((array)($this->subsystem['coupling_harness_families'] ?? []));
            if ($couplingFamilies !== []) {
                $couplingReport = AgenticCouplingHarnessEvaluator::runFamilies(
                    $this->harness,
                    $this->decomposition,
                    $currentConfig,
                    (array)$candidate['config'],
                    (array)$candidate['changes'],
                    $couplingFamilies,
                    $label . '-coupling'
                );
                $record['tier_results']['coupling_harnesses'] = $couplingReport;
            }

            $hasHardLocalFlag = $this->hasHardRegressionFlag($localFlags);
            $hasCouplingFailure = !empty($record['tier_results']['coupling_harnesses'])
                && (string)$record['tier_results']['coupling_harnesses']['status'] === 'fail';
            if ($localScore < $gateTier1 || $hasHardLocalFlag || $hasCouplingFailure) {
                $record['status'] = $hasCouplingFailure ? 'tier1_coupling_reject' : 'tier1_reject';
                $this->memory->recordReject([
                    'stage' => 'tier1',
                    'subsystem_id' => $this->subsystem['id'],
                    'candidate_id' => $label,
                    'changes' => $candidate['changes'],
                    'local_score' => $localScore,
                    'regression_flags' => array_merge(
                        $localFlags,
                        array_map(
                            static fn(string $familyId): string => 'coupling_harness_failed:' . $familyId,
                            (array)($record['tier_results']['coupling_harnesses']['failed_family_ids'] ?? [])
                        )
                    ),
                ]);
                $tested[] = $record;
                continue;
            }

            $record['status'] = 'tier1_pass';
            $this->memory->recordLocalWinner([
                'subsystem_id' => $this->subsystem['id'],
                'candidate_id' => $label,
                'changes' => $candidate['changes'],
                'local_score' => $localScore,
            ]);

            $integrationEval = $this->harness->evaluate($candidate['config'], $integrationProfile, $label . '-tier2', (array)$candidate['changes'], $currentConfig);
            $integrationMetrics = (array)$integrationEval['metrics'];
            $integrationGlobalDelta = AgenticMetricEvaluator::globalScore($integrationMetrics)
                - AgenticMetricEvaluator::globalScore($baselineIntegrationMetrics);
            $integrationFlags = AgenticMetricEvaluator::regressionFlags($baselineIntegrationMetrics, $integrationMetrics);
            $record['tier_results']['tier2'] = [
                'profile_id' => $integrationProfile['id'],
                'metrics' => $integrationMetrics,
                'global_score_delta' => $integrationGlobalDelta,
                'regression_flags' => $integrationFlags,
                'cache_hit' => (bool)$integrationEval['cache_hit'],
            ];

            $gateTier2 = (float)$this->subsystem['promotion_gates']['tier2_min_global_delta'];
            if ($integrationGlobalDelta < $gateTier2 || $this->hasHardRegressionFlag($integrationFlags)) {
                $record['status'] = 'tier2_reject';
                $this->memory->recordLocalWinnerGlobalReject([
                    'stage' => 'tier2',
                    'subsystem_id' => $this->subsystem['id'],
                    'candidate_id' => $label,
                    'changes' => $candidate['changes'],
                    'local_score' => $localScore,
                    'global_score_delta' => $integrationGlobalDelta,
                    'regression_flags' => $integrationFlags,
                ]);
                $tested[] = $record;
                continue;
            }

            $fullEval = $this->harness->evaluate($candidate['config'], $fullProfile, $label . '-tier3', (array)$candidate['changes'], $currentConfig);
            $fullMetrics = (array)$fullEval['metrics'];
            $fullGlobalDelta = AgenticMetricEvaluator::globalScore($fullMetrics)
                - AgenticMetricEvaluator::globalScore($baselineFullMetrics);
            $fullFlags = AgenticMetricEvaluator::regressionFlags($baselineFullMetrics, $fullMetrics);
            $record['tier_results']['tier3'] = [
                'profile_id' => $fullProfile['id'],
                'metrics' => $fullMetrics,
                'global_score_delta' => $fullGlobalDelta,
                'regression_flags' => $fullFlags,
                'cache_hit' => (bool)$fullEval['cache_hit'],
            ];

            $gateTier3 = (float)$this->subsystem['promotion_gates']['tier3_min_global_delta'];
            if ($fullGlobalDelta < $gateTier3 || $this->hasHardRegressionFlag($fullFlags)) {
                $record['status'] = 'tier3_reject';
                $this->memory->recordLocalWinnerGlobalReject([
                    'stage' => 'tier3',
                    'subsystem_id' => $this->subsystem['id'],
                    'candidate_id' => $label,
                    'changes' => $candidate['changes'],
                    'local_score' => $localScore,
                    'global_score_delta' => $fullGlobalDelta,
                    'regression_flags' => $fullFlags,
                ]);
                $tested[] = $record;
                continue;
            }

            $record['status'] = 'accepted';
            $record['accepted_config'] = $candidate['config'];
            $record['applied_changes'] = $candidate['changes'];
            $record['full_global_delta'] = $fullGlobalDelta;
            $localWinners[] = $record;
            $tested[] = $record;

            if (count($localWinners) >= $maxAccepted) {
                break;
            }
        }

        usort($localWinners, static function ($a, $b) {
            $left = (float)($a['full_global_delta'] ?? -9999.0);
            $right = (float)($b['full_global_delta'] ?? -9999.0);
            if ($left === $right) {
                return 0;
            }
            return ($left > $right) ? -1 : 1;
        });

        return [
            'subsystem' => $this->subsystem,
            'baseline' => [
                'local' => $baselineLocal,
                'integration' => $baselineIntegration,
                'full' => $baselineFull,
            ],
            'tested_candidates' => $tested,
            'accepted_candidates' => $localWinners,
            'selected_candidate' => $localWinners[0] ?? null,
        ];
    }

    private function hasHardRegressionFlag(array $flags): bool
    {
        $hardFlags = [
            'lock_in_down_but_expiry_dominance_up',
            'dominant_archetype_shifted',
            'long_run_concentration_worsened',
            'skip_rejoin_exploit_worsened',
        ];

        foreach ($flags as $flag) {
            if (in_array($flag, $hardFlags, true)) {
                return true;
            }
        }
        return false;
    }

    private function generateCandidates(array $currentConfig): array
    {
        $candidates = [];

        foreach ((array)$this->subsystem['owned_parameters'] as $surface) {
            $key = (string)$surface['key'];
            $mode = (string)$surface['mode'];
            $values = (array)($surface['values'] ?? []);

            $failureCount = $this->memory->getParameterFailureCount($key);
            $filteredValues = $this->pruneValuesByFailureCount($values, $mode, $failureCount);

            foreach ($filteredValues as $value) {
                $next = $this->applyMutation($currentConfig, $key, $mode, $value);
                if ($next === null) {
                    continue;
                }

                $mutationLabel = is_array($value) ? json_encode($value, JSON_UNESCAPED_SLASHES) : (string)$value;
                $candidates[] = [
                    'config' => $next['config'],
                    'changes' => [[
                        'key' => $key,
                        'mode' => $mode,
                        'mutation_label' => $mutationLabel,
                        'old_value' => $next['old_value'],
                        'new_value' => $next['new_value'],
                    ]],
                ];
            }
        }

        // Add a coarse-to-fine two-surface composition candidate from the top two surfaces.
        // Only compose when the two candidates mutate distinct keys; composing the same key twice
        // produces a candidate_duplicate_key validation error and stacks multipliers nonsensically.
        if (count($candidates) >= 2) {
            $firstKeys = array_column((array)($candidates[0]['changes'] ?? []), 'key');
            $secondKeys = array_column((array)($candidates[1]['changes'] ?? []), 'key');
        }
        if (count($candidates) >= 2 && array_intersect($firstKeys ?? [], $secondKeys ?? []) === []) {
            $first = $candidates[0];
            $second = $candidates[1];
            $mergedConfig = $currentConfig;
            $mergedChanges = [];

            foreach ([$first, $second] as $candidate) {
                foreach ((array)$candidate['changes'] as $change) {
                    $apply = $this->applyMutation($mergedConfig, (string)$change['key'], (string)$change['mode'], $change['new_value']);
                    if ($apply === null) {
                        continue;
                    }
                    $mergedConfig = $apply['config'];
                    $mergedChanges[] = [
                        'key' => $change['key'],
                        'mode' => $change['mode'],
                        'mutation_label' => $change['mutation_label'],
                        'old_value' => $apply['old_value'],
                        'new_value' => $apply['new_value'],
                    ];
                }
            }

            if ($mergedChanges !== []) {
                $candidates[] = ['config' => $mergedConfig, 'changes' => $mergedChanges];
            }
        }

        // Deduplicate by effective config hash.
        $unique = [];
        foreach ($candidates as $candidate) {
            $hash = AgenticOptimizationUtils::jsonHash(AgenticOptimizationUtils::convertSeasonForJson($candidate['config']));
            if (isset($unique[$hash])) {
                continue;
            }
            $unique[$hash] = $candidate;
        }

        // Keep a small beam width to avoid wasting runs.
        return array_slice(array_values($unique), 0, 8);
    }

    private function pruneValuesByFailureCount(array $values, string $mode, int $failureCount): array
    {
        if ($failureCount < 2) {
            return $values;
        }

        $filtered = [];
        foreach ($values as $value) {
            if (is_numeric($value)) {
                $v = (float)$value;
                if ($mode === 'multiply' && ($v > 1.12 || $v < 0.88)) {
                    continue;
                }
            }
            if (is_array($value) && isset($value['supply_mult']) && (float)$value['supply_mult'] > 1.12) {
                continue;
            }
            $filtered[] = $value;
        }

        return $filtered === [] ? $values : $filtered;
    }

    private function applyMutation(array $config, string $key, string $mode, $mutation): ?array
    {
        if (!array_key_exists($key, $config)) {
            return null;
        }

        $oldValue = $config[$key];
        $newValue = $oldValue;

        if ($mode === 'multiply') {
            $factor = (float)$mutation;
            if (is_numeric($oldValue)) {
                $raw = (float)$oldValue * $factor;
                $newValue = is_int($oldValue) ? (int)round($raw) : $raw;
            } elseif (is_string($oldValue) && is_numeric($oldValue)) {
                $raw = (float)$oldValue * $factor;
                $newValue = (string)round($raw);
            } else {
                return null;
            }
        } elseif ($mode === 'add') {
            $delta = (float)$mutation;
            if (!is_numeric($oldValue)) {
                return null;
            }
            $raw = (float)$oldValue + $delta;
            $newValue = is_int($oldValue) ? (int)round($raw) : $raw;
        } elseif ($mode === 'vault_supply_cost') {
            $decoded = is_string($oldValue) ? json_decode($oldValue, true) : $oldValue;
            if (!is_array($decoded)) {
                return null;
            }

            $supplyMult = (float)($mutation['supply_mult'] ?? 1.0);
            $costMult = (float)($mutation['cost_mult'] ?? 1.0);

            foreach ($decoded as &$tier) {
                if (isset($tier['supply'])) {
                    $tier['supply'] = max(1, (int)round((float)$tier['supply'] * $supplyMult));
                }
                if (isset($tier['cost_table']) && is_array($tier['cost_table'])) {
                    foreach ($tier['cost_table'] as &$entry) {
                        if (isset($entry['cost'])) {
                            $entry['cost'] = max(1, (int)round((float)$entry['cost'] * $costMult));
                        }
                    }
                    unset($entry);
                }
            }
            unset($tier);

            $newValue = json_encode($decoded, JSON_UNESCAPED_SLASHES);
        } else {
            return null;
        }

        if ($newValue === $oldValue) {
            return null;
        }

        $next = $config;
        $next[$key] = $newValue;

        return [
            'config' => $next,
            'old_value' => $oldValue,
            'new_value' => $newValue,
        ];
    }
}

class AgenticOptimizationCoordinator
{
    public static function resolveRejectAuditMode(array $options): string
    {
        $mode = strtolower(trim((string)($options['reject_audit_mode'] ?? 'legacy')));
        if ($mode === 'shadow-manifest') {
            return 'shadow-manifest';
        }
        if ($mode === 'manifest_preferred') {
            return 'manifest_preferred';
        }
        return 'legacy';
    }

    public static function run(array $options): array
    {
        $repoRoot = (string)($options['repo_root'] ?? dirname(__DIR__, 2));
        $outputRoot = (string)($options['output_root'] ?? ($repoRoot . DIRECTORY_SEPARATOR . 'simulation_output' . DIRECTORY_SEPARATOR . 'current-db' . DIRECTORY_SEPARATOR . 'agentic-optimization'));
        $runSeed = (string)($options['seed'] ?? ('agentic-' . gmdate('Ymd-His')));
        $rejectAuditMode = self::resolveRejectAuditMode($options);
        $rejectAuditManifest = trim((string)($options['reject_audit_manifest'] ?? ''));
        $runId = AgenticOptimizationUtils::sanitize($runSeed);

        $runDir = $outputRoot . DIRECTORY_SEPARATOR . $runId;
        AgenticOptimizationUtils::ensureDir($runDir);
        AgenticOptimizationUtils::ensureDir($runDir . DIRECTORY_SEPARATOR . 'reports');
        AgenticOptimizationUtils::ensureDir($runDir . DIRECTORY_SEPARATOR . 'decomposition');
        AgenticOptimizationUtils::ensureDir($runDir . DIRECTORY_SEPARATOR . 'audit');
        AgenticOptimizationUtils::ensureDir($runDir . DIRECTORY_SEPARATOR . 'search-memory');

        $baseline = AgenticBaselineConfigLoader::load($options['season_config'] ?? null);
        $baseSeason = (array)$baseline['season'];

        AgenticOptimizationUtils::writeJson($runDir . DIRECTORY_SEPARATOR . 'baseline_config_snapshot.json', [
            'schema_version' => 'tmc-agentic-baseline-snapshot.v1',
            'generated_at' => gmdate('c'),
            'provenance' => $baseline['provenance'],
            'effective_config' => AgenticOptimizationUtils::convertSeasonForJson($baseSeason),
        ]);

        $decomposition = AgenticEconomyDecomposition::build($baseSeason);
        $decompositionPaths = AgenticEconomyDecomposition::writeArtifacts($decomposition, $runDir . DIRECTORY_SEPARATOR . 'decomposition');

        $auditReport = AgenticRejectedIterationAuditor::run($repoRoot, $runDir . DIRECTORY_SEPARATOR . 'audit');
        $shadowParity = null;
        $manifestPreferred = null;
        if ($rejectAuditMode === 'shadow-manifest') {
            $shadowParity = AgenticRejectedIterationShadowParity::run(
                $repoRoot,
                $runDir . DIRECTORY_SEPARATOR . 'audit',
                $auditReport,
                $rejectAuditManifest !== '' ? $rejectAuditManifest : null
            );
        } elseif ($rejectAuditMode === 'manifest_preferred') {
            $manifestPreferred = AgenticRejectedIterationShadowParity::runManifestPreferred(
                $repoRoot,
                $runDir . DIRECTORY_SEPARATOR . 'audit',
                $auditReport,
                $rejectAuditManifest !== '' ? $rejectAuditManifest : null
            );
            $auditReport = (array)($manifestPreferred['authoritative_audit'] ?? $auditReport);
        }

        $memoryPath = $outputRoot . DIRECTORY_SEPARATOR . 'search-memory' . DIRECTORY_SEPARATOR . 'global_search_memory.json';
        $searchMemory = new AgenticSearchMemory($memoryPath);

        $harness = new AgenticHarnessRunner($runDir);

        $subsystems = (array)$decomposition['subsystems'];
        usort($subsystems, static function ($a, $b) {
            return ((int)$a['priority']) <=> ((int)$b['priority']);
        });

        $currentConfig = $baseSeason;
        $subsystemReports = [];
        $acceptedByPriority = [];

        foreach ($subsystems as $subsystem) {
            $agent = new AgenticSubsystemAgent(
                $subsystem,
                $decomposition,
                $harness,
                $searchMemory,
                $runId
            );

            $report = $agent->optimize($currentConfig);
            $selected = $report['selected_candidate'];

            if ($selected !== null) {
                $conflicts = self::detectConflicts($acceptedByPriority, (array)$selected['applied_changes']);
                if (!empty($conflicts)) {
                    $searchMemory->recordConflict([
                        'subsystem_id' => $subsystem['id'],
                        'candidate_id' => $selected['candidate_id'],
                        'conflicting_keys' => $conflicts,
                        'resolution' => 'newer-higher-priority-subsystem-candidate-preferred',
                    ]);
                }

                $currentConfig = (array)$selected['accepted_config'];
                $acceptedByPriority[] = [
                    'subsystem_id' => $subsystem['id'],
                    'candidate_id' => $selected['candidate_id'],
                    'changes' => (array)$selected['applied_changes'],
                    'full_global_delta' => (float)($selected['full_global_delta'] ?? 0.0),
                ];
            }

            $subsystemReports[] = $report;
        }

        $finalVariants = self::buildComposedVariants($baseSeason, $acceptedByPriority);
        $fullProfile = $decomposition['profiles']['tier3_full'];
        $baselineFullEval = $harness->evaluate($baseSeason, $fullProfile, $runId . '-final-baseline-full');
        $baselineFullMetrics = (array)$baselineFullEval['metrics'];

        $finalVariantReports = [];
        $bestVariant = null;
        foreach ($finalVariants as $variant) {
            $eval = $harness->evaluate($variant['config'], $fullProfile, $runId . '-variant-' . $variant['variant_id']);
            $metrics = (array)$eval['metrics'];
            $globalDelta = AgenticMetricEvaluator::globalScore($metrics) - AgenticMetricEvaluator::globalScore($baselineFullMetrics);
            $flags = AgenticMetricEvaluator::regressionFlags($baselineFullMetrics, $metrics);

            $variantReport = [
                'variant_id' => $variant['variant_id'],
                'description' => $variant['description'],
                'included_candidates' => $variant['included_candidates'],
                'global_score_delta_vs_baseline' => $globalDelta,
                'regression_flags' => $flags,
                'metrics' => $metrics,
                'cache_hit' => (bool)$eval['cache_hit'],
                'status' => (self::hasHardRegressionFlag($flags) || $globalDelta < 0.0) ? 'global_reject' : 'global_accept',
            ];
            $finalVariantReports[] = $variantReport;

            if ($variantReport['status'] === 'global_accept') {
                if ($bestVariant === null || $variantReport['global_score_delta_vs_baseline'] > $bestVariant['global_score_delta_vs_baseline']) {
                    $bestVariant = $variantReport;
                }
            }
        }

        $bestConfigPath = $runDir . DIRECTORY_SEPARATOR . 'best_composed_config.json';
        $bestConfigPayload = [
            'schema_version' => 'tmc-agentic-best-config.v1',
            'generated_at' => gmdate('c'),
            'baseline_provenance' => $baseline['provenance'],
            'best_variant' => $bestVariant,
            'accepted_subsystem_candidates' => $acceptedByPriority,
            'effective_config' => AgenticOptimizationUtils::convertSeasonForJson($currentConfig),
        ];
        AgenticOptimizationUtils::writeJson($bestConfigPath, $bestConfigPayload);

        $searchMemory->persist();
        AgenticOptimizationUtils::writeJson($runDir . DIRECTORY_SEPARATOR . 'search-memory' . DIRECTORY_SEPARATOR . 'run_search_memory_snapshot.json', $searchMemory->snapshot());

        $summary = self::buildSummary(
            $runId,
            $baseline,
            $auditReport,
            $decompositionPaths,
            $subsystemReports,
            $acceptedByPriority,
            $finalVariantReports,
            $bestVariant,
            $bestConfigPath
        );

        AgenticOptimizationUtils::writeJson($runDir . DIRECTORY_SEPARATOR . 'reports' . DIRECTORY_SEPARATOR . 'final_integration_report.json', $summary);
        self::writeSummaryMarkdown($runDir . DIRECTORY_SEPARATOR . 'reports' . DIRECTORY_SEPARATOR . 'final_integration_report.md', $summary);

        $result = [
            'run_id' => $runId,
            'run_dir' => $runDir,
            'summary' => $summary,
        ];
        if (is_array($shadowParity)) {
            $result['shadow_reject_audit_parity'] = $shadowParity;
        }
        if (is_array($manifestPreferred)) {
            $result['manifest_preferred_diagnostic'] = (array)($manifestPreferred['diagnostic'] ?? []);
        }
        return $result;
    }

    private static function detectConflicts(array $acceptedByPriority, array $changes): array
    {
        $priorKeys = [];
        foreach ($acceptedByPriority as $accepted) {
            foreach ((array)$accepted['changes'] as $change) {
                $priorKeys[(string)$change['key']] = true;
            }
        }

        $conflicts = [];
        foreach ($changes as $change) {
            $key = (string)$change['key'];
            if (isset($priorKeys[$key])) {
                $conflicts[] = $key;
            }
        }

        return array_values(array_unique($conflicts));
    }

    private static function buildComposedVariants(array $baselineConfig, array $acceptedByPriority): array
    {
        $variants = [];

        $variants[] = [
            'variant_id' => 'baseline',
            'description' => 'Baseline DB snapshot only.',
            'included_candidates' => [],
            'config' => $baselineConfig,
        ];

        if ($acceptedByPriority === []) {
            return $variants;
        }

        $applyChanges = static function (array $base, array $selected): array {
            $result = $base;
            foreach ($selected as $accepted) {
                foreach ((array)$accepted['changes'] as $change) {
                    $result[(string)$change['key']] = $change['new_value'];
                }
            }
            return $result;
        };

        $allConfig = $applyChanges($baselineConfig, $acceptedByPriority);
        $variants[] = [
            'variant_id' => 'all_accepted',
            'description' => 'All accepted subsystem winners composed in priority order.',
            'included_candidates' => array_map(static fn($x) => $x['candidate_id'], $acceptedByPriority),
            'config' => $allConfig,
        ];

        $top4 = array_slice($acceptedByPriority, 0, min(4, count($acceptedByPriority)));
        if (!empty($top4)) {
            $variants[] = [
                'variant_id' => 'top4_priority',
                'description' => 'Top four priority subsystem winners only.',
                'included_candidates' => array_map(static fn($x) => $x['candidate_id'], $top4),
                'config' => $applyChanges($baselineConfig, $top4),
            ];
        }

        $top2 = array_slice($acceptedByPriority, 0, min(2, count($acceptedByPriority)));
        if (!empty($top2)) {
            $variants[] = [
                'variant_id' => 'top2_priority',
                'description' => 'Top two priority subsystem winners only.',
                'included_candidates' => array_map(static fn($x) => $x['candidate_id'], $top2),
                'config' => $applyChanges($baselineConfig, $top2),
            ];
        }

        return $variants;
    }

    private static function hasHardRegressionFlag(array $flags): bool
    {
        $hard = [
            'lock_in_down_but_expiry_dominance_up',
            'dominant_archetype_shifted',
            'long_run_concentration_worsened',
            'skip_rejoin_exploit_worsened',
            'archetype_viability_regressed',
        ];

        foreach ($flags as $flag) {
            if (in_array($flag, $hard, true)) {
                return true;
            }
        }
        return false;
    }

    private static function buildSummary(
        string $runId,
        array $baseline,
        array $audit,
        array $decompositionPaths,
        array $subsystemReports,
        array $acceptedByPriority,
        array $finalVariantReports,
        ?array $bestVariant,
        string $bestConfigPath
    ): array {
        $subsystemResults = [];
        foreach ($subsystemReports as $report) {
            $tested = (array)$report['tested_candidates'];
            $accepted = (array)$report['accepted_candidates'];
            $rejected = array_values(array_filter($tested, static function ($row) {
                return (string)($row['status'] ?? '') !== 'accepted';
            }));

            $subsystemResults[] = [
                'subsystem_id' => (string)$report['subsystem']['id'],
                'subsystem_label' => (string)$report['subsystem']['label'],
                'priority' => (int)$report['subsystem']['priority'],
                'tested_count' => count($tested),
                'accepted_count' => count($accepted),
                'selected_candidate_id' => $report['selected_candidate']['candidate_id'] ?? null,
                'accepted_candidate_ids' => array_values(array_map(static fn($x) => (string)$x['candidate_id'], $accepted)),
                'rejected_candidate_ids' => array_values(array_map(static fn($x) => (string)$x['candidate_id'], $rejected)),
            ];
        }

        return [
            'schema_version' => 'tmc-agentic-final-report.v1',
            'generated_at' => gmdate('c'),
            'run_id' => $runId,
            'baseline_provenance' => $baseline['provenance'],
            'decomposition_artifacts' => $decompositionPaths,
            'rejected_iteration_audit' => [
                'audited_events_count' => (int)($audit['audited_events_count'] ?? 0),
                'key_failure_patterns' => (array)($audit['key_failure_patterns'] ?? []),
                'flag_histogram' => (array)($audit['flag_histogram'] ?? []),
            ],
            'subsystem_results' => $subsystemResults,
            'accepted_subsystem_winners' => $acceptedByPriority,
            'final_variant_reports' => $finalVariantReports,
            'best_variant' => $bestVariant,
            'globally_valid_full_configuration_found' => $bestVariant !== null,
            'best_config_path' => $bestConfigPath,
            'notes' => [
                'Tier1 used cheap local harnesses with reduced cohorts and/or phase-limited screening.',
                'Known economy coupling families now run as explicit subsystem harness gates before tier2 promotion.',
                'Tier2 validated cross-subsystem effects before any full-lifecycle promotion.',
                'Tier3 full-lifecycle validation only ran on promoted winners and composed variants.',
            ],
        ];
    }

    private static function writeSummaryMarkdown(string $path, array $summary): void
    {
        $lines = [];
        $lines[] = '# Agentic Hierarchical Optimization Report';
        $lines[] = '';
        $lines[] = 'Generated: ' . $summary['generated_at'];
        $lines[] = 'Run ID: `' . $summary['run_id'] . '`';
        $lines[] = '';
        $lines[] = '## Rejection Audit';
        $lines[] = '';
        $lines[] = '- Audited reject events: ' . (int)$summary['rejected_iteration_audit']['audited_events_count'];
        $lines[] = '- Key repeated failure patterns: ' . implode(', ', (array)$summary['rejected_iteration_audit']['key_failure_patterns']);
        $lines[] = '';
        $lines[] = '## Subsystem Optimization';
        $lines[] = '';
        foreach ((array)$summary['subsystem_results'] as $row) {
            $lines[] = '- `' . $row['subsystem_id'] . '` priority=' . $row['priority']
                . ' tested=' . $row['tested_count']
                . ' accepted=' . $row['accepted_count']
                . ' selected=' . ($row['selected_candidate_id'] ?? 'none');
        }
        $lines[] = '';
        $lines[] = '## Composed Full Validation';
        $lines[] = '';
        foreach ((array)$summary['final_variant_reports'] as $variant) {
            $lines[] = '- `' . $variant['variant_id'] . '` status=' . $variant['status']
                . ' global_delta=' . round((float)$variant['global_score_delta_vs_baseline'], 4)
                . ' flags=' . (empty($variant['regression_flags']) ? 'none' : implode(', ', (array)$variant['regression_flags']));
        }
        $lines[] = '';
        $lines[] = '## Outcome';
        $lines[] = '';
        $lines[] = '- Globally valid full configuration found: '
            . ($summary['globally_valid_full_configuration_found'] ? 'YES' : 'NO');
        if (!empty($summary['best_variant'])) {
            $lines[] = '- Best variant: `' . $summary['best_variant']['variant_id'] . '`';
            $lines[] = '- Best variant global delta: ' . round((float)$summary['best_variant']['global_score_delta_vs_baseline'], 4);
        }
        $lines[] = '- Best config snapshot: `' . $summary['best_config_path'] . '`';

        AgenticOptimizationUtils::ensureDir(dirname($path));
        file_put_contents($path, implode(PHP_EOL, $lines));
    }
}
