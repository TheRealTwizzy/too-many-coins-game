<?php

require_once __DIR__ . '/SimulationSeason.php';
require_once __DIR__ . '/CanonicalEconomyConfigContract.php';

class EconomicCandidateValidationException extends InvalidArgumentException
{
    private array $failures;

    public function __construct(array $failures, string $prefix = 'Economic candidate validation failed')
    {
        $this->failures = array_values($failures);
        parent::__construct(EconomicCandidateValidator::buildFailureMessage($this->failures, $prefix));
    }

    public function failures(): array
    {
        return $this->failures;
    }
}

class EconomicCandidateValidator
{
    public const SCHEMA_VERSION = 'tmc-economic-candidate-surface.v1';

    private const CATEGORY_KEY_ALLOWLIST = [
        'star_conversion_pricing' => [
            'starprice_table',
            'star_price_cap',
            'starprice_idle_weight_fp',
            'starprice_active_only',
            'starprice_max_upstep_fp',
            'starprice_max_downstep_fp',
            'market_affordability_bias_fp',
            'star_price_minimum_absolute',
        ],
        'boost_related' => [
            'base_ubi_active_per_tick',
            'base_ubi_idle_factor_fp',
            'ubi_min_per_tick',
            'inflation_table',
        ],
        'lock_in_expiry_incentives' => [
            'starprice_idle_weight_fp',
            'starprice_max_upstep_fp',
            'starprice_max_downstep_fp',
            'hoarding_min_factor_fp',
        ],
        'hoarding_preservation_pressure' => [
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
            'hoarding_safe_hours',
            'starprice_max_upstep_fp',
            'starprice_max_downstep_fp',
        ],
        'sigil_drop_tier_combine' => [],
    ];

    private const DEPRECATED_KEYS = [
        'starprice_model_version' => 'Deprecated tuning knob. Star pricing model selection is runtime-owned and no longer candidate-configurable.',
    ];

    public static function categoryAllowlist(): array
    {
        return self::CATEGORY_KEY_ALLOWLIST;
    }

    public static function deprecatedKeys(): array
    {
        return self::DEPRECATED_KEYS;
    }

    public static function allowedSurface(): array
    {
        return CanonicalEconomyConfigContract::validatorSurfaceMeta();
    }

    public static function assertNormalizedChanges(array $changes, array $options = []): void
    {
        $failures = self::validateNormalizedChanges($changes, $options);
        if ($failures !== []) {
            throw new EconomicCandidateValidationException($failures);
        }
    }

    public static function validateNormalizedChanges(array $changes, array $options = []): array
    {
        $baseSeason = self::resolveBaseSeason($options);
        $effectiveSeason = self::resolveEffectiveSeason($changes, $baseSeason);
        $surfaceMeta = self::allowedSurface();
        $seenKeys = [];
        $failures = [];

        foreach ($changes as $index => $change) {
            if (!is_array($change)) {
                $failures[] = self::failure(
                    $options,
                    $index,
                    null,
                    'candidate_malformed_change',
                    'Requested candidate change must be an object or associative array.'
                );
                continue;
            }

            $rawPath = (string)($change['raw_path'] ?? $change['path'] ?? '');
            $path = (string)($change['path'] ?? $rawPath);
            $scope = $change['scope'] ?? self::inferScopeFromPath($path);
            $key = (string)($change['key'] ?? self::inferKeyFromPath($rawPath, $path));
            $requestedValue = $change['requested_value'] ?? null;
            $pathStatus = (string)($change['path_status'] ?? 'valid');

            if ($pathStatus !== 'valid' || $key === '') {
                $reason = self::classifyUnknownVsOutOfSurface($rawPath);
                $failures[] = self::failure(
                    $options,
                    $index,
                    $path !== '' ? $path : $rawPath,
                    $reason['code'],
                    $reason['detail'],
                    $requestedValue
                );
                continue;
            }

            if ($scope !== 'season') {
                $failures[] = self::failure(
                    $options,
                    $index,
                    $path,
                    'candidate_out_of_surface',
                    'Runtime-owned config is outside the economic candidate tuning surface.',
                    $requestedValue
                );
                continue;
            }

            if (isset(self::DEPRECATED_KEYS[$key])) {
                $failures[] = self::failure(
                    $options,
                    $index,
                    $path,
                    'candidate_deprecated_key',
                    (string)self::DEPRECATED_KEYS[$key],
                    $requestedValue
                );
                continue;
            }

            if (!array_key_exists($key, $surfaceMeta)) {
                $reason = in_array($key, SimulationSeason::SEASON_ECONOMY_COLUMNS, true)
                    ? [
                        'code' => 'candidate_out_of_surface',
                        'detail' => 'Key exists in the season schema but is not part of the canonical economic tuning surface.',
                    ]
                    : [
                        'code' => 'candidate_unknown_key',
                        'detail' => 'Key is not recognized by the shared season schema.',
                    ];
                $failures[] = self::failure(
                    $options,
                    $index,
                    $path,
                    $reason['code'],
                    $reason['detail'],
                    $requestedValue
                );
                continue;
            }

            if (isset($seenKeys[$key])) {
                $failures[] = self::failure(
                    $options,
                    $index,
                    $path,
                    'candidate_duplicate_key',
                    'Candidate change list may not set the same canonical key more than once.',
                    $requestedValue
                );
                continue;
            }
            $seenKeys[$key] = true;

            $meta = $surfaceMeta[$key];
            $featureFlag = (string)($meta['feature_flag'] ?? '');
            if ($featureFlag !== '') {
                $feature = self::resolveFeatureFlag($featureFlag, $effectiveSeason);
                if (!$feature['enabled']) {
                    $failures[] = self::failure(
                        $options,
                        $index,
                        $path,
                        'candidate_disabled_subsystem',
                        sprintf(
                            'Subsystem `%s` is disabled for this candidate context because `%s` resolves to %s.',
                            (string)($meta['subsystem'] ?? 'unknown'),
                            $featureFlag,
                            json_encode($feature['value'], JSON_UNESCAPED_SLASHES)
                        ),
                        $requestedValue
                    );
                    continue;
                }
            }

            $valueFailure = self::validateValueForKey($key, $requestedValue, $meta, $effectiveSeason);
            if ($valueFailure !== null) {
                $failures[] = self::failure(
                    $options,
                    $index,
                    $path,
                    $valueFailure['code'],
                    $valueFailure['detail'],
                    $requestedValue
                );
            }
        }

        return $failures;
    }

    public static function assertScenario(array $scenario, array $options = []): void
    {
        $failures = self::validateScenario($scenario, $options);
        if ($failures !== []) {
            $name = (string)($scenario['name'] ?? 'unknown');
            throw new EconomicCandidateValidationException($failures, 'Scenario validation failed: ' . $name);
        }
    }

    public static function validateScenario(array $scenario, array $options = []): array
    {
        $failures = [];
        $name = (string)($scenario['name'] ?? '');
        $contextPrefix = (string)($options['context_prefix'] ?? 'scenario');
        $categories = (array)($scenario['categories'] ?? []);
        $overrides = (array)($scenario['overrides'] ?? []);

        if ($name === '') {
            $failures[] = [
                'context' => $contextPrefix,
                'path' => $contextPrefix . '.name',
                'reason_code' => 'candidate_missing_name',
                'reason_detail' => 'Scenario name is required.',
                'requested_value' => null,
            ];
        }

        if ($categories === []) {
            $failures[] = [
                'context' => $contextPrefix,
                'path' => $contextPrefix . '.categories',
                'reason_code' => 'candidate_missing_categories',
                'reason_detail' => 'Scenario must declare at least one category.',
                'requested_value' => [],
            ];
        }

        if ($overrides === []) {
            $failures[] = [
                'context' => $contextPrefix,
                'path' => $contextPrefix . '.overrides',
                'reason_code' => 'candidate_empty_package',
                'reason_detail' => 'Scenario must declare at least one override.',
                'requested_value' => [],
            ];
            return $failures;
        }

        $allowedKeys = [];
        foreach ($categories as $categoryIndex => $category) {
            $categoryName = (string)$category;
            if (!isset(self::CATEGORY_KEY_ALLOWLIST[$categoryName])) {
                $failures[] = [
                    'context' => $contextPrefix,
                    'path' => $contextPrefix . '.categories[' . $categoryIndex . ']',
                    'reason_code' => 'candidate_unknown_category',
                    'reason_detail' => 'Unknown candidate category: ' . $categoryName,
                    'requested_value' => $category,
                ];
                continue;
            }

            foreach (self::CATEGORY_KEY_ALLOWLIST[$categoryName] as $key) {
                $allowedKeys[$key] = true;
            }
        }

        $normalized = [];
        foreach ($overrides as $key => $value) {
            $normalized[] = [
                'layer' => 'scenario_override',
                'raw_path' => (string)$key,
                'path' => 'season.' . (string)$key,
                'scope' => 'season',
                'key' => (string)$key,
                'requested_value' => $value,
                'path_status' => 'valid',
            ];
        }

        $failures = array_merge($failures, self::validateNormalizedChanges($normalized, [
            'base_season' => $options['base_season'] ?? null,
            'context_prefix' => $contextPrefix . '.overrides',
            'layer_name' => 'scenario_override',
        ]));

        foreach ($normalized as $change) {
            $key = (string)$change['key'];
            if (!isset($allowedKeys[$key])) {
                $failures[] = [
                    'context' => $contextPrefix,
                    'path' => $contextPrefix . '.overrides.' . $key,
                    'reason_code' => 'candidate_category_mismatch',
                    'reason_detail' => 'Override key is not allowed by the scenario category set.',
                    'requested_value' => $change['requested_value'],
                ];
            }
        }

        return $failures;
    }

    public static function assertCandidateDocument(array $document, array $options = []): void
    {
        $failures = self::validateCandidateDocument($document, $options);
        if ($failures !== []) {
            throw new EconomicCandidateValidationException($failures, 'Candidate package lint failed');
        }
    }

    public static function validateCandidateDocument(array $document, array $options = []): array
    {
        if (isset($document['packages']) || isset($document['scenarios'])) {
            return self::validateTuningCandidatesDocument($document, $options);
        }

        if (isset($document['overrides'])) {
            return self::validateScenario($document, $options);
        }

        if (self::isAssoc($document)) {
            $normalized = [];
            foreach ($document as $key => $value) {
                $normalized[] = [
                    'layer' => 'candidate_patch',
                    'raw_path' => (string)$key,
                    'path' => 'season.' . (string)$key,
                    'scope' => 'season',
                    'key' => (string)$key,
                    'requested_value' => $value,
                    'path_status' => 'valid',
                ];
            }
            return self::validateNormalizedChanges($normalized, $options);
        }

        $normalized = [];
        foreach ($document as $entry) {
            if (!is_array($entry)) {
                $normalized[] = $entry;
                continue;
            }
            $path = (string)($entry['path'] ?? $entry['target'] ?? $entry['key'] ?? '');
            $normalized[] = [
                'layer' => 'candidate_patch',
                'raw_path' => $path,
                'path' => str_starts_with($path, 'season.') || str_starts_with($path, 'runtime.')
                    ? $path
                    : 'season.' . $path,
                'scope' => str_starts_with($path, 'runtime.') ? 'runtime' : 'season',
                'key' => str_starts_with($path, 'season.') || str_starts_with($path, 'runtime.')
                    ? substr($path, strpos($path, '.') + 1)
                    : $path,
                'requested_value' => $entry['requested_value'] ?? $entry['proposed_value'] ?? $entry['value'] ?? null,
                'path_status' => ($path === '') ? 'invalid_path' : 'valid',
            ];
        }

        return self::validateNormalizedChanges($normalized, $options);
    }

    public static function buildFailureMessage(array $failures, string $prefix = 'Economic candidate validation failed'): string
    {
        if ($failures === []) {
            return $prefix . '.';
        }

        $parts = [];
        foreach (array_slice($failures, 0, 5) as $failure) {
            $parts[] = sprintf(
                '%s (%s)',
                (string)($failure['path'] ?? $failure['context'] ?? 'unknown'),
                (string)($failure['reason_code'] ?? 'invalid')
            );
        }

        if (count($failures) > 5) {
            $parts[] = '+' . (count($failures) - 5) . ' more';
        }

        return $prefix . ': ' . implode(', ', $parts) . '.';
    }

    private static function validateTuningCandidatesDocument(array $document, array $options = []): array
    {
        $failures = [];

        foreach ((array)($document['packages'] ?? []) as $packageIndex => $package) {
            $contextPrefix = 'packages[' . $packageIndex . ']';
            if (!is_array($package)) {
                $failures[] = [
                    'context' => $contextPrefix,
                    'path' => $contextPrefix,
                    'reason_code' => 'candidate_malformed_package',
                    'reason_detail' => 'Package entry must be an object.',
                    'requested_value' => $package,
                ];
                continue;
            }

            $changes = (array)($package['changes'] ?? []);
            if ($changes === []) {
                $failures[] = [
                    'context' => $contextPrefix,
                    'path' => $contextPrefix . '.changes',
                    'reason_code' => 'candidate_empty_package',
                    'reason_detail' => 'Package must contain at least one candidate change.',
                    'requested_value' => [],
                ];
                continue;
            }

            $normalized = [];
            foreach ($changes as $change) {
                if (!is_array($change)) {
                    $normalized[] = $change;
                    continue;
                }
                $target = (string)($change['target'] ?? $change['path'] ?? $change['key'] ?? '');
                $normalized[] = [
                    'layer' => 'candidate_patch',
                    'raw_path' => $target,
                    'path' => str_starts_with($target, 'season.') || str_starts_with($target, 'runtime.')
                        ? $target
                        : 'season.' . $target,
                    'scope' => str_starts_with($target, 'runtime.') ? 'runtime' : 'season',
                    'key' => str_starts_with($target, 'season.') || str_starts_with($target, 'runtime.')
                        ? substr($target, strpos($target, '.') + 1)
                        : $target,
                    'requested_value' => $change['proposed_value'] ?? $change['requested_value'] ?? $change['value'] ?? null,
                    'path_status' => ($target === '') ? 'invalid_path' : 'valid',
                ];
            }

            $failures = array_merge($failures, self::validateNormalizedChanges($normalized, [
                'base_season' => $options['base_season'] ?? null,
                'context_prefix' => $contextPrefix . '.changes',
                'layer_name' => 'candidate_patch',
            ]));
        }

        foreach ((array)($document['scenarios'] ?? []) as $scenarioIndex => $scenario) {
            $failures = array_merge($failures, self::validateScenario((array)$scenario, [
                'base_season' => $options['base_season'] ?? null,
                'context_prefix' => 'scenarios[' . $scenarioIndex . ']',
            ]));
        }

        return $failures;
    }

    private static function failure(
        array $options,
        int $index,
        ?string $path,
        string $reasonCode,
        string $reasonDetail,
        mixed $requestedValue = null
    ): array {
        $contextPrefix = (string)($options['context_prefix'] ?? (($options['layer_name'] ?? 'candidate_change') . '[' . $index . ']'));
        return [
            'context' => $contextPrefix,
            'path' => $path ?? $contextPrefix,
            'reason_code' => $reasonCode,
            'reason_detail' => $reasonDetail,
            'requested_value' => $requestedValue,
        ];
    }

    private static function classifyUnknownVsOutOfSurface(string $rawPath): array
    {
        $trimmed = trim($rawPath);
        $key = self::inferKeyFromPath($trimmed, $trimmed);
        if ($key !== '' && isset(self::DEPRECATED_KEYS[$key])) {
            return [
                'code' => 'candidate_deprecated_key',
                'detail' => (string)self::DEPRECATED_KEYS[$key],
            ];
        }

        if ($trimmed !== '' && str_starts_with($trimmed, 'runtime.')) {
            return [
                'code' => 'candidate_out_of_surface',
                'detail' => 'Runtime-owned config is outside the economic candidate tuning surface.',
            ];
        }

        if ($key !== '' && in_array($key, SimulationSeason::SEASON_ECONOMY_COLUMNS, true)) {
            return [
                'code' => 'candidate_out_of_surface',
                'detail' => 'Key exists in the season schema but is not part of the canonical economic tuning surface.',
            ];
        }

        return [
            'code' => 'candidate_unknown_key',
            'detail' => 'Key is not recognized by the shared season schema.',
        ];
    }

    private static function resolveBaseSeason(array $options): array
    {
        $baseSeason = $options['base_season'] ?? null;
        if (is_array($baseSeason) && $baseSeason !== []) {
            return $baseSeason;
        }

        return SimulationSeason::build(1, 'candidate-validation');
    }

    private static function resolveEffectiveSeason(array $changes, array $baseSeason): array
    {
        $values = [];
        foreach ($changes as $change) {
            if (!is_array($change)) {
                continue;
            }

            $pathStatus = (string)($change['path_status'] ?? 'valid');
            $scope = $change['scope'] ?? null;
            $key = (string)($change['key'] ?? '');
            if ($pathStatus !== 'valid' || $scope !== 'season' || $key === '') {
                continue;
            }

            $values[$key] = $change['requested_value'] ?? null;
        }

        return array_replace($baseSeason, $values);
    }

    private static function resolveFeatureFlag(string $path, array $effectiveSeason): array
    {
        if (!str_starts_with($path, 'season.')) {
            return ['enabled' => false, 'value' => null];
        }

        $key = substr($path, 7);
        $value = $effectiveSeason[$key] ?? null;
        $enabled = false;
        if (is_bool($value)) {
            $enabled = $value;
        } elseif (is_numeric($value)) {
            $enabled = ((int)$value) !== 0;
        }

        return [
            'enabled' => $enabled,
            'value' => $value,
        ];
    }

    private static function validateValueForKey(string $key, mixed $value, array $meta, array $effectiveSeason): ?array
    {
        return match ((string)$meta['type']) {
            'int' => self::validateIntValue($value, $meta, $effectiveSeason),
            'bool_int' => self::validateBoolIntValue($value),
            'inflation_table' => self::validateInflationTable($value),
            'starprice_table' => self::validateStarPriceTable($value),
            'starprice_demand_table' => self::validateStarPriceDemandTable($value),
            'vault_config' => self::validateVaultConfig($value),
            default => [
                'code' => 'candidate_type_mismatch',
                'detail' => 'Unsupported validator type for key: ' . $key,
            ],
        };
    }

    private static function validateIntValue(mixed $value, array $meta, array $effectiveSeason): ?array
    {
        if (!is_int($value)) {
            return [
                'code' => 'candidate_type_mismatch',
                'detail' => 'Value must be an integer.',
            ];
        }

        $min = $meta['min'] ?? null;
        $max = $meta['max'] ?? null;
        if (isset($meta['max_from_context']) && (string)$meta['max_from_context'] === 'season_duration_ticks') {
            $duration = max(1, (int)($effectiveSeason['end_time'] ?? 0) - (int)($effectiveSeason['start_time'] ?? 0));
            $max = $duration;
        }

        if ($min !== null && $value < $min) {
            return [
                'code' => 'candidate_out_of_range',
                'detail' => sprintf('Value must be >= %d.', (int)$min),
            ];
        }

        if ($max !== null && $value > $max) {
            return [
                'code' => 'candidate_out_of_range',
                'detail' => sprintf('Value must be <= %d.', (int)$max),
            ];
        }

        return null;
    }

    private static function validateBoolIntValue(mixed $value): ?array
    {
        if (!is_int($value) || !in_array($value, [0, 1], true)) {
            return [
                'code' => 'candidate_type_mismatch',
                'detail' => 'Boolean candidate values must be encoded as integer 0 or 1.',
            ];
        }

        return null;
    }

    private static function validateInflationTable(mixed $value): ?array
    {
        $decoded = self::decodeArrayValue($value);
        if (!is_array($decoded) || $decoded === []) {
            return [
                'code' => 'candidate_type_mismatch',
                'detail' => 'Inflation table must be a non-empty JSON array.',
            ];
        }

        $prevX = null;
        foreach ($decoded as $entry) {
            if (!is_array($entry) || !isset($entry['x']) || !isset($entry['factor_fp']) || !is_int($entry['x']) || !is_int($entry['factor_fp'])) {
                return [
                    'code' => 'candidate_type_mismatch',
                    'detail' => 'Inflation table entries must contain integer `x` and `factor_fp` fields.',
                ];
            }
            if ($entry['x'] < 0 || $entry['factor_fp'] < 0 || $entry['factor_fp'] > 1000000) {
                return [
                    'code' => 'candidate_out_of_range',
                    'detail' => 'Inflation table entries must keep `x >= 0` and `0 <= factor_fp <= 1000000`.',
                ];
            }
            if ($prevX !== null && $entry['x'] <= $prevX) {
                return [
                    'code' => 'candidate_type_mismatch',
                    'detail' => 'Inflation table `x` values must be strictly increasing.',
                ];
            }
            $prevX = $entry['x'];
        }

        return null;
    }

    private static function validateStarPriceTable(mixed $value): ?array
    {
        $decoded = self::decodeArrayValue($value);
        if (!is_array($decoded) || $decoded === []) {
            return [
                'code' => 'candidate_type_mismatch',
                'detail' => 'Star price table must be a non-empty JSON array.',
            ];
        }

        $prevM = null;
        foreach ($decoded as $entry) {
            if (!is_array($entry) || !isset($entry['m']) || !isset($entry['price']) || !is_int($entry['m']) || !is_int($entry['price'])) {
                return [
                    'code' => 'candidate_type_mismatch',
                    'detail' => 'Star price table entries must contain integer `m` and `price` fields.',
                ];
            }
            if ($entry['m'] < 0 || $entry['price'] < 1) {
                return [
                    'code' => 'candidate_out_of_range',
                    'detail' => 'Star price table entries must keep `m >= 0` and `price >= 1`.',
                ];
            }
            if ($prevM !== null && $entry['m'] <= $prevM) {
                return [
                    'code' => 'candidate_type_mismatch',
                    'detail' => 'Star price table `m` values must be strictly increasing.',
                ];
            }
            $prevM = $entry['m'];
        }

        return null;
    }

    private static function validateStarPriceDemandTable(mixed $value): ?array
    {
        $decoded = self::decodeArrayValue($value);
        if (!is_array($decoded) || $decoded === []) {
            return [
                'code' => 'candidate_type_mismatch',
                'detail' => 'Star price demand table must be a non-empty JSON array.',
            ];
        }

        $prevRatio = null;
        foreach ($decoded as $entry) {
            if (!is_array($entry) || !isset($entry['ratio_fp']) || !isset($entry['multiplier_fp']) || !is_int($entry['ratio_fp']) || !is_int($entry['multiplier_fp'])) {
                return [
                    'code' => 'candidate_type_mismatch',
                    'detail' => 'Star price demand table entries must contain integer `ratio_fp` and `multiplier_fp` fields.',
                ];
            }
            if ($entry['ratio_fp'] <= 0 || $entry['multiplier_fp'] <= 0 || $entry['multiplier_fp'] > 5000000) {
                return [
                    'code' => 'candidate_out_of_range',
                    'detail' => 'Star price demand table entries must keep `ratio_fp > 0` and `0 < multiplier_fp <= 5000000`.',
                ];
            }
            if ($prevRatio !== null && $entry['ratio_fp'] <= $prevRatio) {
                return [
                    'code' => 'candidate_type_mismatch',
                    'detail' => 'Star price demand table `ratio_fp` values must be strictly increasing.',
                ];
            }
            $prevRatio = $entry['ratio_fp'];
        }

        return null;
    }

    private static function validateVaultConfig(mixed $value): ?array
    {
        $decoded = self::decodeArrayValue($value);
        if (!is_array($decoded) || $decoded === []) {
            return [
                'code' => 'candidate_type_mismatch',
                'detail' => 'Vault config must be a non-empty JSON array.',
            ];
        }

        $seenTiers = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry) || !isset($entry['tier']) || !isset($entry['supply']) || !isset($entry['cost_table'])) {
                return [
                    'code' => 'candidate_type_mismatch',
                    'detail' => 'Vault config entries must contain `tier`, `supply`, and `cost_table` fields.',
                ];
            }
            if (!is_int($entry['tier']) || !is_int($entry['supply']) || !is_array($entry['cost_table'])) {
                return [
                    'code' => 'candidate_type_mismatch',
                    'detail' => 'Vault config `tier`/`supply` must be integers and `cost_table` must be an array.',
                ];
            }
            if ($entry['tier'] < 1 || $entry['tier'] > 6 || $entry['supply'] < 1) {
                return [
                    'code' => 'candidate_out_of_range',
                    'detail' => 'Vault config tiers must be within 1..6 and supply must be >= 1.',
                ];
            }
            if (isset($seenTiers[$entry['tier']])) {
                return [
                    'code' => 'candidate_duplicate_key',
                    'detail' => 'Vault config may not declare the same tier more than once.',
                ];
            }
            $seenTiers[$entry['tier']] = true;

            if ($entry['cost_table'] === []) {
                return [
                    'code' => 'candidate_type_mismatch',
                    'detail' => 'Vault config cost tables must not be empty.',
                ];
            }

            foreach ($entry['cost_table'] as $costEntry) {
                if (!is_array($costEntry) || !isset($costEntry['remaining']) || !isset($costEntry['cost']) || !is_int($costEntry['remaining']) || !is_int($costEntry['cost'])) {
                    return [
                        'code' => 'candidate_type_mismatch',
                        'detail' => 'Vault config cost-table entries must contain integer `remaining` and `cost` fields.',
                    ];
                }
                if ($costEntry['remaining'] < 1 || $costEntry['cost'] < 0) {
                    return [
                        'code' => 'candidate_out_of_range',
                        'detail' => 'Vault config cost-table entries must keep `remaining >= 1` and `cost >= 0`.',
                    ];
                }
            }
        }

        return null;
    }

    private static function decodeArrayValue(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function inferScopeFromPath(string $path): ?string
    {
        if (str_starts_with($path, 'season.')) {
            return 'season';
        }
        if (str_starts_with($path, 'runtime.')) {
            return 'runtime';
        }
        return null;
    }

    private static function inferKeyFromPath(string $rawPath, string $path): string
    {
        $candidate = trim($rawPath !== '' ? $rawPath : $path);
        if (str_starts_with($candidate, 'season.')) {
            return substr($candidate, 7);
        }
        if (str_starts_with($candidate, 'runtime.')) {
            return substr($candidate, 8);
        }
        return $candidate;
    }

    private static function isAssoc(array $array): bool
    {
        if ($array === []) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }
}
