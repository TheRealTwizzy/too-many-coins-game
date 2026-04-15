<?php

require_once __DIR__ . '/SimulationSeason.php';
require_once __DIR__ . '/EconomicCandidateValidator.php';
require_once __DIR__ . '/SeasonConfigExporter.php';

class SimulationConfigPreflightException extends RuntimeException
{
    private array $report;
    private array $artifactPaths;

    public function __construct(string $message, array $report, array $artifactPaths = [])
    {
        parent::__construct($message);
        $this->report = $report;
        $this->artifactPaths = $artifactPaths;
    }

    public function report(): array
    {
        return $this->report;
    }

    public function artifactPaths(): array
    {
        return $this->artifactPaths;
    }
}

class SimulationConfigPreflight
{
    public const SCHEMA_VERSION = 'tmc-effective-config.v1';
    public const AUDIT_ENV_BYPASS = 'TMC_SIMULATION_AUDIT_BYPASS';

    private const RUNTIME_KEY_META = [
        'tick_real_seconds' => [
            'env_keys' => ['TMC_TICK_REAL_SECONDS'],
            'default' => 60,
            'caster' => 'int',
            'referenced' => true,
        ],
        'time_scale' => [
            'env_keys' => ['TMC_TIME_SCALE'],
            'default' => 1,
            'caster' => 'int',
            'referenced' => true,
        ],
        'season_duration_ticks' => [
            'derived' => true,
            'referenced' => true,
        ],
        'season_cadence_ticks' => [
            'derived' => true,
            'referenced' => true,
        ],
        'blackout_duration_ticks' => [
            'derived' => true,
            'referenced' => true,
        ],
        'min_participation_ticks' => [
            'constant' => true,
            'referenced' => true,
        ],
        'min_seasonal_lock_in_ticks' => [
            'constant' => true,
            'referenced' => true,
        ],
        'sigil_blackout_duration_ticks' => [
            'constant' => true,
            'referenced' => true,
        ],
        'sigil_late_active_duration_ticks' => [
            'constant' => true,
            'referenced' => true,
        ],
        'sigil_early_phase_fraction_fp' => [
            'constant' => true,
            'referenced' => true,
        ],
    ];

    private const SEASON_KEY_META = [
        'season_id' => ['candidate_scope' => false, 'referenced' => true],
        'start_time' => ['candidate_scope' => false, 'referenced' => true],
        'end_time' => ['candidate_scope' => false, 'referenced' => true],
        'blackout_time' => ['candidate_scope' => false, 'referenced' => true],
        'season_seed' => ['candidate_scope' => false, 'referenced' => true],
        'status' => ['candidate_scope' => false, 'referenced' => true],
        'season_expired' => ['candidate_scope' => false, 'referenced' => false],
        'expiration_finalized' => ['candidate_scope' => false, 'referenced' => false],
        'base_ubi_active_per_tick' => ['candidate_scope' => true, 'referenced' => true],
        'base_ubi_idle_factor_fp' => ['candidate_scope' => true, 'referenced' => true],
        'ubi_min_per_tick' => ['candidate_scope' => true, 'referenced' => true],
        'inflation_table' => ['candidate_scope' => true, 'referenced' => true],
        'hoarding_window_ticks' => ['candidate_scope' => false, 'referenced' => false],
        'target_spend_rate_per_tick' => ['candidate_scope' => false, 'referenced' => false],
        'hoarding_min_factor_fp' => ['candidate_scope' => true, 'referenced' => true],
        'hoarding_sink_enabled' => ['candidate_scope' => true, 'referenced' => true],
        'hoarding_safe_hours' => ['candidate_scope' => true, 'referenced' => true, 'feature_flag' => 'season.hoarding_sink_enabled'],
        'hoarding_safe_min_coins' => ['candidate_scope' => true, 'referenced' => true, 'feature_flag' => 'season.hoarding_sink_enabled'],
        'hoarding_tier1_excess_cap' => ['candidate_scope' => true, 'referenced' => true, 'feature_flag' => 'season.hoarding_sink_enabled'],
        'hoarding_tier2_excess_cap' => ['candidate_scope' => true, 'referenced' => true, 'feature_flag' => 'season.hoarding_sink_enabled'],
        'hoarding_tier1_rate_hourly_fp' => ['candidate_scope' => true, 'referenced' => true, 'feature_flag' => 'season.hoarding_sink_enabled'],
        'hoarding_tier2_rate_hourly_fp' => ['candidate_scope' => true, 'referenced' => true, 'feature_flag' => 'season.hoarding_sink_enabled'],
        'hoarding_tier3_rate_hourly_fp' => ['candidate_scope' => true, 'referenced' => true, 'feature_flag' => 'season.hoarding_sink_enabled'],
        'hoarding_sink_cap_ratio_fp' => ['candidate_scope' => true, 'referenced' => true, 'feature_flag' => 'season.hoarding_sink_enabled'],
        'hoarding_idle_multiplier_fp' => ['candidate_scope' => true, 'referenced' => true, 'feature_flag' => 'season.hoarding_sink_enabled'],
        'starprice_table' => ['candidate_scope' => true, 'referenced' => true],
        'star_price_cap' => ['candidate_scope' => true, 'referenced' => true],
        'starprice_idle_weight_fp' => ['candidate_scope' => true, 'referenced' => true],
        'starprice_active_only' => ['candidate_scope' => true, 'referenced' => true],
        'starprice_max_upstep_fp' => ['candidate_scope' => true, 'referenced' => true],
        'starprice_max_downstep_fp' => ['candidate_scope' => true, 'referenced' => true],
        'starprice_model_version' => ['candidate_scope' => true, 'referenced' => false],
        'starprice_reactivation_window_ticks' => ['candidate_scope' => false, 'referenced' => false],
        'starprice_demand_table' => ['candidate_scope' => false, 'referenced' => false],
        'market_affordability_bias_fp' => ['candidate_scope' => false, 'referenced' => false],
        'vault_config' => ['candidate_scope' => false, 'referenced' => false],
        'current_star_price' => ['candidate_scope' => false, 'referenced' => true],
        'market_anchor_price' => ['candidate_scope' => false, 'referenced' => false],
        'blackout_star_price_snapshot' => ['candidate_scope' => false, 'referenced' => true],
        'blackout_started_tick' => ['candidate_scope' => false, 'referenced' => true],
        'pending_star_burn_coins' => ['candidate_scope' => false, 'referenced' => false],
        'star_burn_ema_fp' => ['candidate_scope' => false, 'referenced' => false],
        'net_mint_ema_fp' => ['candidate_scope' => false, 'referenced' => false],
        'market_pressure_fp' => ['candidate_scope' => false, 'referenced' => false],
        'total_coins_supply' => ['candidate_scope' => false, 'referenced' => true],
        'total_coins_supply_end_of_tick' => ['candidate_scope' => false, 'referenced' => true],
        'coins_active_total' => ['candidate_scope' => false, 'referenced' => true],
        'coins_idle_total' => ['candidate_scope' => false, 'referenced' => true],
        'coins_offline_total' => ['candidate_scope' => false, 'referenced' => true],
        'effective_price_supply' => ['candidate_scope' => false, 'referenced' => true],
        'last_processed_tick' => ['candidate_scope' => false, 'referenced' => true],
    ];

    public static function resolve(array $options): array
    {
        $seed = (string)($options['seed'] ?? 'phase1');
        $seasonId = max(1, (int)($options['season_id'] ?? 1));
        $simulator = (string)($options['simulator'] ?? 'unknown');
        $debugBypass = self::resolveDebugBypass($options);

        $runtime = self::buildRuntimeConfig();
        $defaultSeason = SimulationSeason::build($seasonId, $seed);
        $baseSeasonOverrides = self::resolveBaseSeasonOverrides($options);
        $candidateLayer = self::normalizeRequestedChanges((array)($options['candidate_patch'] ?? []), 'candidate_patch');
        $scenarioLayer = self::normalizeRequestedChanges((array)($options['scenario_overrides'] ?? []), 'scenario_override');

        $candidateSeasonValues = self::collectSeasonValues($candidateLayer);
        $scenarioSeasonValues = self::collectSeasonValues($scenarioLayer);

        $effectiveSeason = array_replace(
            $defaultSeason,
            $baseSeasonOverrides['values'],
            $candidateSeasonValues,
            $scenarioSeasonValues
        );
        SimulationSeason::assertArrayShape($effectiveSeason);

        $candidateValidationFailures = EconomicCandidateValidator::validateNormalizedChanges($candidateLayer, [
            'base_season' => array_replace($defaultSeason, $baseSeasonOverrides['values'], $scenarioSeasonValues),
            'context_prefix' => 'candidate_patch',
            'layer_name' => 'candidate_patch',
        ]);
        $scenarioValidationFailures = EconomicCandidateValidator::validateNormalizedChanges($scenarioLayer, [
            'base_season' => array_replace($defaultSeason, $baseSeasonOverrides['values'], $candidateSeasonValues),
            'context_prefix' => 'scenario_override',
            'layer_name' => 'scenario_override',
        ]);

        $effectiveSeasonForJson = self::seasonForJson($effectiveSeason);
        $seasonSources = self::buildSeasonSources(
            $defaultSeason,
            $baseSeasonOverrides['values'],
            $candidateSeasonValues,
            $scenarioSeasonValues
        );

        $candidateAudit = self::evaluateRequestedChanges(
            $candidateLayer,
            $scenarioLayer,
            $runtime,
            $effectiveSeason,
            $seasonSources
        );

        $inactiveChanges = array_values(array_filter($candidateAudit, static function (array $row): bool {
            return !$row['is_active'];
        }));
        $validationFailures = array_merge($candidateValidationFailures, $scenarioValidationFailures);

        $report = [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => gmdate('c'),
            'status' => ($inactiveChanges === [] && $validationFailures === []) ? 'pass' : ($debugBypass ? 'debug_bypass' : 'fail'),
            'simulator' => $simulator,
            'context' => [
                'seed' => $seed,
                'season_id' => $seasonId,
                'players_per_archetype' => isset($options['players_per_archetype']) ? (int)$options['players_per_archetype'] : null,
                'season_count' => isset($options['season_count']) ? (int)$options['season_count'] : null,
                'run_label' => $options['run_label'] ?? null,
                'base_season_config_path' => $baseSeasonOverrides['path'],
                'base_season_source' => $baseSeasonOverrides['source'],
                'debug_bypass' => $debugBypass,
                'debug_bypass_env' => getenv(self::AUDIT_ENV_BYPASS) ?: null,
            ],
            'precedence' => [
                'season' => [
                    'simulation_defaults',
                    'base_season_override',
                    'candidate_patch',
                    'scenario_override',
                ],
                'runtime' => [
                    'code_default',
                    'environment',
                ],
            ],
            'candidate_validation' => [
                'schema_version' => EconomicCandidateValidator::SCHEMA_VERSION,
                'candidate_patch_failures' => $candidateValidationFailures,
                'scenario_override_failures' => $scenarioValidationFailures,
            ],
            'requested_candidate_changes' => $candidateAudit,
            'effective_config' => [
                'runtime' => $runtime['values'],
                'season' => $effectiveSeasonForJson,
            ],
            'effective_sources' => [
                'runtime' => $runtime['sources'],
                'season' => $seasonSources,
            ],
        ];

        $artifactPaths = self::writeArtifacts((string)($options['artifact_dir'] ?? ''), $report);
        $report['artifact_paths'] = $artifactPaths;

        if ($validationFailures !== [] && !$debugBypass) {
            throw new SimulationConfigPreflightException(
                self::buildValidationFailureMessage($validationFailures, $artifactPaths),
                $report,
                $artifactPaths
            );
        }

        if ($inactiveChanges !== [] && !$debugBypass) {
            throw new SimulationConfigPreflightException(
                self::buildFailureMessage($inactiveChanges, $artifactPaths),
                $report,
                $artifactPaths
            );
        }

        return [
            'report' => $report,
            'runtime' => $runtime,
            'season' => $effectiveSeason,
            'season_json' => $effectiveSeasonForJson,
            'artifact_paths' => $artifactPaths,
        ];
    }

    private static function resolveDebugBypass(array $options): bool
    {
        if (!empty($options['debug_allow_inactive_candidate'])) {
            return true;
        }

        $envValue = getenv(self::AUDIT_ENV_BYPASS);
        return $envValue !== false && filter_var($envValue, FILTER_VALIDATE_BOOLEAN);
    }

    private static function resolveBaseSeasonOverrides(array $options): array
    {
        $path = isset($options['base_season_config_path']) && $options['base_season_config_path'] !== ''
            ? (string)$options['base_season_config_path']
            : null;

        if ($path !== null) {
            if (!is_file($path)) {
                throw new InvalidArgumentException('Base season config file not found: ' . $path);
            }
            $decoded = json_decode((string)file_get_contents($path), true);
            if (!is_array($decoded)) {
                throw new InvalidArgumentException('Base season config must decode to a JSON object: ' . $path);
            }

            return [
                'source' => 'file',
                'path' => $path,
                'values' => self::normalizeBaseSeasonValues($decoded),
            ];
        }

        $inline = (array)($options['base_season_overrides'] ?? []);
        if ($inline !== []) {
            return [
                'source' => 'inline',
                'path' => null,
                'values' => self::normalizeBaseSeasonValues($inline),
            ];
        }

        return [
            'source' => 'defaults_only',
            'path' => null,
            'values' => [],
        ];
    }

    private static function normalizeBaseSeasonValues(array $values): array
    {
        $normalized = SimulationSeason::normalizeImportedRow($values);
        if (isset($normalized['season_seed_hex'])) {
            unset($normalized['season_seed_hex']);
        }

        $allowed = array_fill_keys(SeasonConfigExporter::canonicalConfigKeys(), true);
        foreach (array_keys($normalized) as $key) {
            if (!isset($allowed[$key])) {
                throw new InvalidArgumentException('Unknown base season config key: ' . $key);
            }
        }

        return $normalized;
    }

    private static function buildRuntimeConfig(): array
    {
        $values = [];
        $sources = [];

        foreach (self::RUNTIME_KEY_META as $key => $meta) {
            if (!empty($meta['env_keys'])) {
                $resolved = self::resolveRuntimeEnvValue((array)$meta['env_keys'], $meta['default'], (string)$meta['caster']);
                $values[$key] = $resolved['value'];
                $sources[$key] = $resolved['source'];
                continue;
            }

            if (!empty($meta['derived'])) {
                $values[$key] = match ($key) {
                    'season_duration_ticks' => (int)SEASON_DURATION,
                    'season_cadence_ticks' => (int)SEASON_CADENCE,
                    'blackout_duration_ticks' => (int)BLACKOUT_DURATION,
                    default => null,
                };
                $sources[$key] = 'derived_from_runtime';
                continue;
            }

            $values[$key] = match ($key) {
                'min_participation_ticks' => (int)MIN_PARTICIPATION_TICKS,
                'min_seasonal_lock_in_ticks' => (int)MIN_SEASONAL_LOCK_IN_TICKS,
                'sigil_blackout_duration_ticks' => (int)SIGIL_BLACKOUT_DURATION_TICKS,
                'sigil_late_active_duration_ticks' => (int)SIGIL_LATE_ACTIVE_DURATION_TICKS,
                'sigil_early_phase_fraction_fp' => (int)SIGIL_EARLY_PHASE_FRACTION_FP,
                default => null,
            };
            $sources[$key] = 'code_default';
        }

        return [
            'values' => $values,
            'sources' => $sources,
        ];
    }

    private static function resolveRuntimeEnvValue(array $envKeys, mixed $default, string $caster): array
    {
        foreach ($envKeys as $envKey) {
            $value = getenv($envKey);
            if ($value === false || $value === '') {
                continue;
            }

            return [
                'value' => self::castRuntimeValue($value, $caster),
                'source' => 'environment:' . $envKey,
            ];
        }

        return [
            'value' => self::castRuntimeValue($default, $caster),
            'source' => 'code_default',
        ];
    }

    private static function castRuntimeValue(mixed $value, string $caster): mixed
    {
        return match ($caster) {
            'int' => max(1, (int)$value),
            default => $value,
        };
    }

    private static function normalizeRequestedChanges(array $changes, string $layer): array
    {
        if ($changes === []) {
            return [];
        }

        $normalized = [];
        if (self::isAssoc($changes)) {
            foreach ($changes as $path => $value) {
                $normalized[] = self::normalizeRequestedChangeRecord($path, $value, $layer);
            }
            return $normalized;
        }

        foreach ($changes as $entry) {
            if (!is_array($entry)) {
                throw new InvalidArgumentException('Requested config changes must be arrays.');
            }

            $path = $entry['path'] ?? $entry['key'] ?? null;
            if ($path === null) {
                throw new InvalidArgumentException('Requested config change is missing path/key.');
            }

            $value = $entry['value'] ?? $entry['proposed_value'] ?? $entry['requested_value'] ?? $entry['new_value'] ?? null;
            $normalized[] = self::normalizeRequestedChangeRecord((string)$path, $value, $layer);
        }

        return $normalized;
    }

    private static function normalizeRequestedChangeRecord(string $rawPath, mixed $value, string $layer): array
    {
        $path = trim($rawPath);
        $scope = null;
        $key = null;
        $pathStatus = 'valid';

        if ($path === '') {
            $pathStatus = 'invalid_path';
        } elseif (str_starts_with($path, 'season.')) {
            $scope = 'season';
            $key = substr($path, 7);
        } elseif (str_starts_with($path, 'runtime.')) {
            $scope = 'runtime';
            $key = substr($path, 8);
        } elseif (in_array($path, SimulationSeason::SEASON_ECONOMY_COLUMNS, true)) {
            $scope = 'season';
            $key = $path;
            $path = 'season.' . $path;
        } else {
            $pathStatus = 'invalid_path';
        }

        return [
            'layer' => $layer,
            'raw_path' => $rawPath,
            'path' => $path,
            'scope' => $scope,
            'key' => $key,
            'requested_value' => $value,
            'path_status' => $pathStatus,
        ];
    }

    private static function collectSeasonValues(array $changes): array
    {
        $values = [];
        foreach ($changes as $change) {
            if (($change['scope'] ?? null) !== 'season') {
                continue;
            }
            if (($change['path_status'] ?? '') !== 'valid') {
                continue;
            }
            $values[(string)$change['key']] = $change['requested_value'];
        }
        return $values;
    }

    private static function buildSeasonSources(array $defaults, array $base, array $candidate, array $scenario): array
    {
        $sources = [];
        foreach (SimulationSeason::SEASON_ECONOMY_COLUMNS as $key) {
            $source = 'simulation_defaults';
            if (array_key_exists($key, $base)) {
                $source = 'base_season_override';
            }
            if (array_key_exists($key, $candidate)) {
                $source = 'candidate_patch';
            }
            if (array_key_exists($key, $scenario)) {
                $source = 'scenario_override';
            }
            $sources[$key] = $source;
        }
        return $sources;
    }

    private static function evaluateRequestedChanges(
        array $candidateLayer,
        array $scenarioLayer,
        array $runtime,
        array $effectiveSeason,
        array $seasonSources
    ): array {
        $scenarioIndex = [];
        foreach ($scenarioLayer as $change) {
            if (($change['path_status'] ?? '') !== 'valid') {
                continue;
            }
            $scenarioIndex[(string)$change['path']] = $change;
        }

        $results = [];
        foreach ($candidateLayer as $change) {
            $results[] = self::evaluateOneRequestedChange(
                $change,
                $scenarioIndex,
                $runtime,
                $effectiveSeason,
                $seasonSources
            );
        }

        return $results;
    }

    private static function evaluateOneRequestedChange(
        array $change,
        array $scenarioIndex,
        array $runtime,
        array $effectiveSeason,
        array $seasonSources
    ): array {
        $result = [
            'path' => $change['path'],
            'raw_path' => $change['raw_path'],
            'requested_value' => $change['requested_value'],
            'effective_value' => null,
            'effective_source' => null,
            'is_active' => false,
            'reason_code' => null,
            'reason_detail' => null,
        ];

        if (($change['path_status'] ?? '') !== 'valid') {
            $result['reason_code'] = 'inactive_invalid_path';
            $result['reason_detail'] = 'Path does not resolve to a known canonical config entry.';
            return $result;
        }

        $scope = (string)$change['scope'];
        $key = (string)$change['key'];
        $path = (string)$change['path'];

        if ($scope === 'runtime') {
            $result['effective_value'] = $runtime['values'][$key] ?? null;
            $result['effective_source'] = $runtime['sources'][$key] ?? 'unknown';

            if (!array_key_exists($key, self::RUNTIME_KEY_META)) {
                $result['reason_code'] = 'inactive_invalid_path';
                $result['reason_detail'] = 'Runtime path is not recognized by the canonical resolver.';
                return $result;
            }

            $result['reason_code'] = 'inactive_shadowed';
            $result['reason_detail'] = 'Runtime config is controlled by environment/code defaults, not candidate patches.';
            return $result;
        }

        if (!array_key_exists($key, self::SEASON_KEY_META)) {
            $result['reason_code'] = 'inactive_invalid_path';
            $result['reason_detail'] = 'Season key is not part of the shared season schema.';
            return $result;
        }

        $result['effective_value'] = array_key_exists($key, $effectiveSeason)
            ? self::normalizeForJson($effectiveSeason[$key], $key)
            : null;
        $result['effective_source'] = $seasonSources[$key] ?? 'unknown';

        $meta = self::SEASON_KEY_META[$key];
        if (empty($meta['candidate_scope'])) {
            $result['reason_code'] = 'inactive_out_of_scope';
            $result['reason_detail'] = 'Key exists but is computed/runtime-owned and not valid candidate input.';
            return $result;
        }

        if (!$meta['referenced']) {
            $result['reason_code'] = 'inactive_unreferenced';
            $result['reason_detail'] = 'Key resolves correctly but the simulator does not read it during B/C execution.';
            return $result;
        }

        $featureFlagPath = $meta['feature_flag'] ?? null;
        if (is_string($featureFlagPath) && $featureFlagPath !== '') {
            $featureState = self::resolveFeatureFlag($featureFlagPath, $runtime['values'], $effectiveSeason);
            if (!$featureState['enabled']) {
                $result['reason_code'] = 'inactive_feature_disabled';
                $result['reason_detail'] = $featureState['detail'];
                return $result;
            }
        }

        if (isset($scenarioIndex[$path])) {
            $scenarioValue = self::normalizeForJson($scenarioIndex[$path]['requested_value'], $key);
            $candidateValue = self::normalizeForJson($change['requested_value'], $key);
            if ($scenarioValue !== $candidateValue) {
                $result['reason_code'] = 'inactive_shadowed';
                $result['reason_detail'] = 'Higher-precedence scenario override wins for this path.';
                return $result;
            }
        }

        $candidateValue = self::normalizeForJson($change['requested_value'], $key);
        if ($result['effective_source'] !== 'candidate_patch' || $result['effective_value'] !== $candidateValue) {
            $result['reason_code'] = 'inactive_shadowed';
            $result['reason_detail'] = 'A higher-precedence layer resolved a different effective value.';
            return $result;
        }

        $result['is_active'] = true;
        return $result;
    }

    private static function resolveFeatureFlag(string $path, array $runtimeValues, array $effectiveSeason): array
    {
        if (str_starts_with($path, 'season.')) {
            $key = substr($path, 7);
            $value = $effectiveSeason[$key] ?? null;
            $enabled = self::truthyFlag($value);
            return [
                'enabled' => $enabled,
                'detail' => $enabled
                    ? 'Feature flag is enabled.'
                    : sprintf('%s resolves to %s.', $path, json_encode(self::normalizeForJson($value, $key))),
            ];
        }

        if (str_starts_with($path, 'runtime.')) {
            $key = substr($path, 8);
            $value = $runtimeValues[$key] ?? null;
            $enabled = self::truthyFlag($value);
            return [
                'enabled' => $enabled,
                'detail' => $enabled
                    ? 'Runtime feature flag is enabled.'
                    : sprintf('%s resolves to %s.', $path, json_encode($value)),
            ];
        }

        return [
            'enabled' => false,
            'detail' => 'Feature flag path could not be resolved: ' . $path,
        ];
    }

    private static function truthyFlag(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value !== 0;
        }
        return filter_var((string)$value, FILTER_VALIDATE_BOOLEAN);
    }

    private static function writeArtifacts(string $artifactDir, array $report): array
    {
        if ($artifactDir === '') {
            return [];
        }

        if (!is_dir($artifactDir)) {
            mkdir($artifactDir, 0777, true);
        }

        $jsonPath = rtrim($artifactDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'effective_config.json';
        $mdPath = rtrim($artifactDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'effective_config_audit.md';

        file_put_contents($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($mdPath, self::buildMarkdownAudit($report));

        return [
            'effective_config_json' => $jsonPath,
            'effective_config_audit_md' => $mdPath,
        ];
    }

    private static function buildMarkdownAudit(array $report): string
    {
        $lines = [];
        $lines[] = '# Effective Config Audit';
        $lines[] = '';
        $lines[] = '- Status: `' . (string)$report['status'] . '`';
        $lines[] = '- Simulator: `' . (string)$report['simulator'] . '`';
        $lines[] = '- Seed: `' . (string)($report['context']['seed'] ?? 'unknown') . '`';
        $lines[] = '- Run Label: `' . (string)($report['context']['run_label'] ?? 'n/a') . '`';
        $lines[] = '- Base Season Source: `' . (string)($report['context']['base_season_source'] ?? 'defaults_only') . '`';
        $lines[] = '';
        $lines[] = '## Precedence';
        $lines[] = '';
        $lines[] = '- Season: ' . implode(' < ', (array)$report['precedence']['season']);
        $lines[] = '- Runtime: ' . implode(' < ', (array)$report['precedence']['runtime']);
        $lines[] = '';
        $validation = (array)($report['candidate_validation'] ?? []);
        $candidateFailures = (array)($validation['candidate_patch_failures'] ?? []);
        $scenarioFailures = (array)($validation['scenario_override_failures'] ?? []);
        if ($candidateFailures !== [] || $scenarioFailures !== []) {
            $lines[] = '## Candidate Validation';
            $lines[] = '';
            foreach (array_merge($candidateFailures, $scenarioFailures) as $failure) {
                $lines[] = '- `' . (string)($failure['path'] ?? 'unknown') . '` => ' . (string)($failure['reason_code'] ?? 'invalid');
                if (!empty($failure['reason_detail'])) {
                    $lines[] = '  detail=' . (string)$failure['reason_detail'];
                }
            }
            $lines[] = '';
        }

        $lines[] = '## Candidate Changes';
        $lines[] = '';

        $changes = (array)($report['requested_candidate_changes'] ?? []);
        if ($changes === []) {
            $lines[] = '- No candidate changes were requested.';
        } else {
            foreach ($changes as $change) {
                $status = !empty($change['is_active']) ? 'active' : ((string)($change['reason_code'] ?? 'inactive'));
                $lines[] = '- `' . (string)$change['path'] . '` => ' . $status;
                $lines[] = '  requested=' . self::formatInlineValue($change['requested_value'])
                    . ' | effective=' . self::formatInlineValue($change['effective_value'])
                    . ' | source=' . (string)($change['effective_source'] ?? 'unknown');
                if (!empty($change['reason_detail'])) {
                    $lines[] = '  detail=' . (string)$change['reason_detail'];
                }
            }
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private static function buildValidationFailureMessage(array $failures, array $artifactPaths): string
    {
        $message = EconomicCandidateValidator::buildFailureMessage($failures, 'Simulation preflight failed strict candidate validation');
        if (!empty($artifactPaths['effective_config_audit_md'])) {
            $message .= ' See audit: ' . $artifactPaths['effective_config_audit_md'];
        }
        return $message;
    }

    private static function buildFailureMessage(array $inactiveChanges, array $artifactPaths): string
    {
        $parts = [];
        foreach ($inactiveChanges as $change) {
            $parts[] = sprintf(
                '%s (%s)',
                (string)$change['path'],
                (string)($change['reason_code'] ?? 'inactive')
            );
        }

        $suffix = '';
        if (!empty($artifactPaths['effective_config_audit_md'])) {
            $suffix = ' See audit: ' . $artifactPaths['effective_config_audit_md'];
        }

        return 'Simulation preflight failed due to inactive candidate changes: ' . implode(', ', $parts) . '.' . $suffix;
    }

    private static function seasonForJson(array $season): array
    {
        $normalized = $season;
        if (isset($normalized['season_seed']) && is_string($normalized['season_seed'])) {
            $normalized['season_seed_hex'] = bin2hex($normalized['season_seed']);
            unset($normalized['season_seed']);
        }
        return $normalized;
    }

    private static function normalizeForJson(mixed $value, ?string $key = null): mixed
    {
        if ($key === 'season_seed' && is_string($value)) {
            return bin2hex($value);
        }
        return $value;
    }

    private static function formatInlineValue(mixed $value): string
    {
        if (is_string($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES);
        }
        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES);
        }
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return (string)$value;
    }

    private static function isAssoc(array $value): bool
    {
        if ($value === []) {
            return false;
        }

        return array_keys($value) !== range(0, count($value) - 1);
    }
}
