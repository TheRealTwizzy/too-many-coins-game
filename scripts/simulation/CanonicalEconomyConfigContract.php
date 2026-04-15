<?php

require_once __DIR__ . '/SimulationSeason.php';

class CanonicalEconomyConfigContractException extends RuntimeException
{
    private array $issues;

    public function __construct(string $message, array $issues = [])
    {
        parent::__construct($message);
        $this->issues = array_values($issues);
    }

    public function issues(): array
    {
        return $this->issues;
    }
}

class CanonicalEconomyConfigContract
{
    public const SCHEMA_VERSION = 'tmc-canonical-economy-config.v1';
    public const COMPATIBILITY_REPORT_SCHEMA_VERSION = 'tmc-play-test-economy-compatibility.v2';

    private const CANDIDATE_SEARCH_SURFACE_REMOVALS = [
        'hoarding_window_ticks' => 'Declared in the canonical config schema, but the canonical runtime/simulation path never reads it.',
        'target_spend_rate_per_tick' => 'Only referenced by Economy::hoardingFactor(), and that helper is not invoked by the canonical runtime/simulation path.',
        'starprice_reactivation_window_ticks' => 'Stored on the season row, but canonical star pricing and lock-in logic never consult it.',
        'starprice_demand_table' => 'Validated and stored, but canonical star pricing never applies the demand multiplier table.',
        'vault_config' => 'Vault pricing helpers exist, but Phase 1 simulation/runtime search excludes vault-market spending from the canonical balance surface.',
    ];

    private const PATCHABLE_PARAMETER_SCHEMA = [
        'base_ubi_active_per_tick' => [
            'type' => 'int',
            'subsystem' => 'boost_related',
            'units' => 'coins_per_tick',
            'min' => 0,
            'max' => 1000000,
            'description' => 'Base active UBI mint before boost and inflation modifiers.',
        ],
        'base_ubi_idle_factor_fp' => [
            'type' => 'int',
            'subsystem' => 'boost_related',
            'units' => 'fp_1e6',
            'min' => 0,
            'max' => 1000000,
            'description' => 'Idle-player share of active UBI in fixed-point parts per 1,000,000.',
        ],
        'ubi_min_per_tick' => [
            'type' => 'int',
            'subsystem' => 'boost_related',
            'units' => 'coins_per_tick',
            'min' => 0,
            'max' => 1000000,
            'description' => 'Per-tick mint floor after economy modifiers are applied.',
        ],
        'inflation_table' => [
            'type' => 'inflation_table',
            'subsystem' => 'boost_related',
            'units' => 'table[x:coins_supply,factor_fp:fp_1e6]',
            'description' => 'Supply breakpoints that scale minting through fixed-point inflation factors.',
        ],
        'hoarding_window_ticks' => [
            'type' => 'int',
            'subsystem' => 'phase_timing',
            'units' => 'ticks',
            'min' => 1,
            'max_from_context' => 'season_duration_ticks',
            'description' => 'Lookback window used when evaluating hoarding pressure.',
        ],
        'target_spend_rate_per_tick' => [
            'type' => 'int',
            'subsystem' => 'boost_related',
            'units' => 'coins_per_tick',
            'min' => 1,
            'max' => 1000000,
            'description' => 'Reference per-tick spend target used by affordability and pressure systems.',
        ],
        'hoarding_min_factor_fp' => [
            'type' => 'int',
            'subsystem' => 'hoarding_preservation_pressure',
            'units' => 'fp_1e6',
            'min' => 0,
            'max' => 1000000,
            'description' => 'Lower bound on hoarding pressure multiplier.',
        ],
        'hoarding_sink_enabled' => [
            'type' => 'bool_int',
            'subsystem' => 'hoarding_preservation_pressure',
            'units' => 'bool_int',
            'description' => 'Feature flag that enables hoarding sink tuning keys.',
        ],
        'hoarding_safe_hours' => [
            'type' => 'int',
            'subsystem' => 'hoarding_preservation_pressure',
            'units' => 'hours',
            'min' => 0,
            'max' => 2160,
            'feature_flag' => 'season.hoarding_sink_enabled',
            'description' => 'Grace period before hoarding sink pressure may begin.',
        ],
        'hoarding_safe_min_coins' => [
            'type' => 'int',
            'subsystem' => 'hoarding_preservation_pressure',
            'units' => 'coins',
            'min' => 0,
            'max' => 1000000000,
            'feature_flag' => 'season.hoarding_sink_enabled',
            'description' => 'Coin floor protected from hoarding sink consumption.',
        ],
        'hoarding_tier1_excess_cap' => [
            'type' => 'int',
            'subsystem' => 'hoarding_preservation_pressure',
            'units' => 'coins',
            'min' => 0,
            'max' => 1000000000,
            'feature_flag' => 'season.hoarding_sink_enabled',
            'description' => 'Tier-1 excess band cap for hoarding sink pressure.',
        ],
        'hoarding_tier2_excess_cap' => [
            'type' => 'int',
            'subsystem' => 'hoarding_preservation_pressure',
            'units' => 'coins',
            'min' => 0,
            'max' => 1000000000,
            'feature_flag' => 'season.hoarding_sink_enabled',
            'description' => 'Tier-2 excess band cap for hoarding sink pressure.',
        ],
        'hoarding_tier1_rate_hourly_fp' => [
            'type' => 'int',
            'subsystem' => 'hoarding_preservation_pressure',
            'units' => 'fp_1e6_per_hour',
            'min' => 0,
            'max' => 5000000,
            'feature_flag' => 'season.hoarding_sink_enabled',
            'description' => 'Hourly sink rate applied in the lowest excess band.',
        ],
        'hoarding_tier2_rate_hourly_fp' => [
            'type' => 'int',
            'subsystem' => 'hoarding_preservation_pressure',
            'units' => 'fp_1e6_per_hour',
            'min' => 0,
            'max' => 5000000,
            'feature_flag' => 'season.hoarding_sink_enabled',
            'description' => 'Hourly sink rate applied in the middle excess band.',
        ],
        'hoarding_tier3_rate_hourly_fp' => [
            'type' => 'int',
            'subsystem' => 'hoarding_preservation_pressure',
            'units' => 'fp_1e6_per_hour',
            'min' => 0,
            'max' => 5000000,
            'feature_flag' => 'season.hoarding_sink_enabled',
            'description' => 'Hourly sink rate applied above the upper excess cap.',
        ],
        'hoarding_sink_cap_ratio_fp' => [
            'type' => 'int',
            'subsystem' => 'hoarding_preservation_pressure',
            'units' => 'fp_1e6',
            'min' => 0,
            'max' => 1000000,
            'feature_flag' => 'season.hoarding_sink_enabled',
            'description' => 'Maximum per-tick sink expressed as a ratio of protected supply.',
        ],
        'hoarding_idle_multiplier_fp' => [
            'type' => 'int',
            'subsystem' => 'hoarding_preservation_pressure',
            'units' => 'fp_1e6',
            'min' => 0,
            'max' => 5000000,
            'feature_flag' => 'season.hoarding_sink_enabled',
            'description' => 'Multiplier applied when the player is idle during sink evaluation.',
        ],
        'starprice_table' => [
            'type' => 'starprice_table',
            'subsystem' => 'star_conversion_pricing',
            'units' => 'table[m:effective_price_supply_coins,price:coins_per_star]',
            'description' => 'Supply-to-price curve for published star prices.',
        ],
        'star_price_cap' => [
            'type' => 'int',
            'subsystem' => 'star_conversion_pricing',
            'units' => 'coins_per_star',
            'min' => 1,
            'max' => 1000000000,
            'description' => 'Hard cap on published star price.',
        ],
        'starprice_idle_weight_fp' => [
            'type' => 'int',
            'subsystem' => 'star_conversion_pricing',
            'units' => 'fp_1e6',
            'min' => 0,
            'max' => 1000000,
            'description' => 'Fixed-point share of idle coin supply counted in price pressure.',
        ],
        'starprice_active_only' => [
            'type' => 'bool_int',
            'subsystem' => 'star_conversion_pricing',
            'units' => 'bool_int',
            'description' => 'When enabled, only active supply participates in price calculation.',
        ],
        'starprice_max_upstep_fp' => [
            'type' => 'int',
            'subsystem' => 'star_conversion_pricing',
            'units' => 'fp_1e6',
            'min' => 1,
            'max' => 1000000,
            'description' => 'Maximum upward price change per tick in fixed-point ratio.',
        ],
        'starprice_max_downstep_fp' => [
            'type' => 'int',
            'subsystem' => 'star_conversion_pricing',
            'units' => 'fp_1e6',
            'min' => 1,
            'max' => 1000000,
            'description' => 'Maximum downward price change per tick in fixed-point ratio.',
        ],
        'starprice_reactivation_window_ticks' => [
            'type' => 'int',
            'subsystem' => 'lock_in_expiry_incentives',
            'units' => 'ticks',
            'min' => 1,
            'max_from_context' => 'season_duration_ticks',
            'description' => 'Window that treats recently reactivated players as demand-active.',
        ],
        'starprice_demand_table' => [
            'type' => 'starprice_demand_table',
            'subsystem' => 'star_conversion_pricing',
            'units' => 'table[ratio_fp:fp_1e6,multiplier_fp:fp_1e6]',
            'description' => 'Demand multiplier curve applied to star pricing pressure.',
        ],
        'market_affordability_bias_fp' => [
            'type' => 'int',
            'subsystem' => 'star_conversion_pricing',
            'units' => 'fp_1e6',
            'min' => 500000,
            'max' => 1000000,
            'description' => 'Multiplicative affordability bias on the computed star price (fp_1e6). '
                . '1000000 = no effect; 970000 = 3% cheaper; 940000 = 6% cheaper. '
                . 'Applied after velocity clamp, before hard cap. '
                . 'Reduces star-purchase friction for all archetypes equally but disproportionately '
                . 'benefits high-purchase archetypes (Boost-Focused, Hardcore, Star-Focused).',
        ],
        'vault_config' => [
            'type' => 'vault_config',
            'subsystem' => 'sigil_drop_tier_combine',
            'units' => 'table[tier:int,supply:count,cost_table:table[remaining:count,cost:stars]]',
            'description' => 'Vault supply and star-cost ladder per sigil tier.',
        ],
    ];

    public static function validatorSurfaceMeta(): array
    {
        $surface = [];
        foreach (self::candidateSearchParameters() as $key => $meta) {
            $surface[$key] = [
                'type' => $meta['type'],
                'min' => $meta['min'] ?? null,
                'max' => $meta['max'] ?? null,
                'max_from_context' => $meta['max_from_context'] ?? null,
                'subsystem' => $meta['subsystem'],
                'feature_flag' => $meta['feature_flag'] ?? null,
                'units' => $meta['units'],
                'description' => $meta['description'],
            ];
        }

        return $surface;
    }

    public static function candidateSearchParameters(): array
    {
        $schema = self::patchableParameters();
        foreach (array_keys(self::CANDIDATE_SEARCH_SURFACE_REMOVALS) as $key) {
            unset($schema[$key]);
        }

        return $schema;
    }

    public static function removedCandidateSearchKeys(): array
    {
        return self::CANDIDATE_SEARCH_SURFACE_REMOVALS;
    }

    public static function patchableParameters(): array
    {
        static $schema = null;
        if ($schema !== null) {
            return $schema;
        }

        $defaults = self::canonicalDefaults();
        $schema = [];
        foreach (self::PATCHABLE_PARAMETER_SCHEMA as $key => $meta) {
            $schema[$key] = array_merge($meta, [
                'default' => $defaults[$key] ?? null,
                'simulator' => array_replace([
                    'scope' => 'season',
                    'path' => 'season.' . $key,
                    'key' => $key,
                    'codec' => self::defaultCodecForType((string)$meta['type']),
                    'type' => $meta['type'],
                    'units' => $meta['units'],
                    'min' => $meta['min'] ?? null,
                    'max' => $meta['max'] ?? null,
                    'max_from_context' => $meta['max_from_context'] ?? null,
                ], (array)($meta['simulator'] ?? [])),
                'play_test' => array_replace([
                    'path' => $key,
                    'key' => $key,
                    'column' => $key,
                    'codec' => self::defaultCodecForType((string)$meta['type']),
                    'type' => $meta['type'],
                    'units' => $meta['units'],
                    'min' => $meta['min'] ?? null,
                    'max' => $meta['max'] ?? null,
                    'max_from_context' => $meta['max_from_context'] ?? null,
                ], (array)($meta['play_test'] ?? [])),
            ]);
        }

        return $schema;
    }

    public static function schema(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => gmdate('c'),
            'keys' => self::patchableParameters(),
        ];
    }

    public static function mapSimulatorPatchToPlayTestPatch(array $simulatorPatch, array $options = []): array
    {
        $report = self::buildCompatibilityReport($simulatorPatch, $options);
        if ($report['status'] !== 'pass') {
            throw new CanonicalEconomyConfigContractException(
                self::buildFailureMessage((array)$report['issues']),
                (array)$report['issues']
            );
        }

        return [
            'canonical_patch' => (array)$report['canonical_patch'],
            'play_test_patch' => (array)$report['play_test_patch'],
            'round_trip_patch' => (array)$report['round_trip_canonical_patch'],
            'report' => $report,
        ];
    }

    public static function buildCompatibilityReport(array $simulatorPatch, array $options = []): array
    {
        $schema = self::resolveSchema($options);
        $issues = self::validateSchemaCompatibility($schema);
        $normalizedPatch = self::normalizePatchDocument($simulatorPatch);

        $canonicalPatch = [];
        $playTestPatch = [];
        $roundTripPatch = [];

        foreach ($normalizedPatch as $entry) {
            if (($entry['path_status'] ?? 'invalid_path') !== 'valid') {
                $issues[] = self::issue(
                    'unsupported_key',
                    'Candidate path does not resolve to a canonical patchable season key.',
                    (string)($entry['key'] ?? ''),
                    ['path' => (string)($entry['raw_path'] ?? '')]
                );
                continue;
            }

            if ((string)($entry['scope'] ?? '') !== 'season') {
                $issues[] = self::issue(
                    'unsupported_key',
                    'Only season-scoped economy keys are supported by the canonical contract.',
                    (string)($entry['key'] ?? ''),
                    ['path' => (string)($entry['path'] ?? '')]
                );
                continue;
            }

            $key = (string)($entry['key'] ?? '');
            if ($key === '' || !isset($schema[$key])) {
                $issues[] = self::issue(
                    'unsupported_key',
                    'Key is not part of the canonical patchable economy schema.',
                    $key,
                    ['path' => (string)($entry['path'] ?? '')]
                );
                continue;
            }

            $playTestMeta = $schema[$key]['play_test'] ?? null;
            if (!is_array($playTestMeta)) {
                continue;
            }

            $canonicalValue = self::normalizeToCanonicalValue($key, $entry['requested_value'], $schema, $issues, 'simulator');
            if ($canonicalValue === self::invalidValueSentinel()) {
                continue;
            }

            $playTestValue = self::encodeForTarget($key, $canonicalValue, $playTestMeta);
            $roundTripValue = self::decodeFromTarget($key, $playTestValue, $playTestMeta, $issues, 'play_test');
            if ($roundTripValue === self::invalidValueSentinel()) {
                continue;
            }

            if (!self::valuesEqual($canonicalValue, $roundTripValue)) {
                $issues[] = self::issue(
                    'lossy_conversion',
                    'Mapping to play-test config and back changed the canonical value.',
                    $key,
                    [
                        'canonical_value' => $canonicalValue,
                        'play_test_value' => $playTestValue,
                        'round_trip_value' => $roundTripValue,
                    ]
                );
                continue;
            }

            $canonicalPatch[$key] = $canonicalValue;
            $playTestPatch[(string)$playTestMeta['key']] = $playTestValue;
            $roundTripPatch[$key] = $roundTripValue;
        }

        $status = $issues === [] ? 'pass' : 'fail';

        return [
            'schema_version' => self::COMPATIBILITY_REPORT_SCHEMA_VERSION,
            'contract_schema_version' => self::SCHEMA_VERSION,
            'generated_at' => gmdate('c'),
            'status' => $status,
            'summary' => $status === 'pass'
                ? 'Simulator patch maps cleanly into play-test config without semantic drift.'
                : 'Compatibility contract rejected one or more simulator-to-play-test mappings.',
            'schema_key_count' => count($schema),
            'patch_entry_count' => count($normalizedPatch),
            'canonical_patch' => $canonicalPatch,
            'play_test_patch' => $playTestPatch,
            'round_trip_canonical_patch' => $roundTripPatch,
            'round_trip_equal' => self::valuesEqual($canonicalPatch, $roundTripPatch),
            'issues' => array_values($issues),
            'schema' => [
                'keys' => $schema,
            ],
        ];
    }

    public static function writeCompatibilityArtifacts(string $dir, array $report): array
    {
        if ($dir === '') {
            return [];
        }

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $jsonPath = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'play_test_repo_compatibility.json';
        $mdPath = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'play_test_repo_compatibility.md';

        file_put_contents($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($mdPath, self::buildCompatibilityMarkdown($report));

        return [
            'play_test_repo_compatibility_json' => $jsonPath,
            'play_test_repo_compatibility_md' => $mdPath,
        ];
    }

    public static function buildCompatibilityMarkdown(array $report): string
    {
        $lines = [];
        $lines[] = '# Play-Test Compatibility Report';
        $lines[] = '';
        $lines[] = '- Status: `' . (string)($report['status'] ?? 'unknown') . '`';
        $lines[] = '- Contract schema: `' . (string)($report['contract_schema_version'] ?? self::SCHEMA_VERSION) . '`';
        $lines[] = '- Patch entries: `' . (int)($report['patch_entry_count'] ?? 0) . '`';
        $lines[] = '- Round-trip equal: `' . (!empty($report['round_trip_equal']) ? 'true' : 'false') . '`';
        $lines[] = '';

        $issues = (array)($report['issues'] ?? []);
        if ($issues === []) {
            $lines[] = '## Result';
            $lines[] = '';
            $lines[] = '- All patch entries mapped losslessly into play-test config.';
            $lines[] = '';
        } else {
            $lines[] = '## Issues';
            $lines[] = '';
            foreach ($issues as $issue) {
                $lines[] = '- `' . (string)($issue['code'] ?? 'unknown') . '`'
                    . (($issue['key'] ?? '') !== '' ? ' on `' . (string)$issue['key'] . '`' : '')
                    . ': ' . (string)($issue['detail'] ?? 'No detail provided.');
            }
            $lines[] = '';
        }

        $mapped = (array)($report['play_test_patch'] ?? []);
        $lines[] = '## Mapped Patch';
        $lines[] = '';
        if ($mapped === []) {
            $lines[] = '- No compatible play-test patch entries were produced.';
        } else {
            foreach ($mapped as $key => $value) {
                $lines[] = '- `' . (string)$key . '` => `' . self::formatInlineValue($value) . '`';
            }
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private static function canonicalDefaults(): array
    {
        $season = SimulationSeason::build(1, 'canonical-economy-contract-defaults');
        $defaults = [];
        $schema = self::seedSchemaForDefaults();

        foreach (array_keys(self::PATCHABLE_PARAMETER_SCHEMA) as $key) {
            $issues = [];
            $defaults[$key] = self::normalizeToCanonicalValue(
                $key,
                $season[$key] ?? null,
                $schema,
                $issues,
                'play_test'
            );
        }

        return $defaults;
    }

    private static function resolveSchema(array $options): array
    {
        $custom = $options['schema'] ?? null;
        if (is_array($custom) && $custom !== []) {
            return $custom;
        }

        return self::patchableParameters();
    }

    private static function normalizePatchDocument(array $document): array
    {
        if ($document === []) {
            return [];
        }

        $normalized = [];
        if (self::isAssoc($document)) {
            foreach ($document as $path => $value) {
                $normalized[] = self::normalizePatchRecord((string)$path, $value);
            }
            return $normalized;
        }

        foreach ($document as $index => $entry) {
            if (!is_array($entry)) {
                $normalized[] = [
                    'raw_path' => (string)$index,
                    'path' => (string)$index,
                    'scope' => null,
                    'key' => (string)$index,
                    'requested_value' => $entry,
                    'path_status' => 'invalid_path',
                ];
                continue;
            }

            $path = $entry['path'] ?? $entry['target'] ?? $entry['key'] ?? null;
            if ($path === null) {
                $normalized[] = [
                    'raw_path' => '',
                    'path' => '',
                    'scope' => null,
                    'key' => '',
                    'requested_value' => $entry['requested_value'] ?? $entry['proposed_value'] ?? $entry['value'] ?? null,
                    'path_status' => 'invalid_path',
                ];
                continue;
            }

            $normalized[] = self::normalizePatchRecord(
                (string)$path,
                $entry['requested_value'] ?? $entry['proposed_value'] ?? $entry['value'] ?? $entry['new_value'] ?? null
            );
        }

        return $normalized;
    }

    private static function normalizePatchRecord(string $rawPath, mixed $value): array
    {
        $path = trim($rawPath);
        $scope = null;
        $key = $path;
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
            'raw_path' => $rawPath,
            'path' => $path,
            'scope' => $scope,
            'key' => $key,
            'requested_value' => $value,
            'path_status' => $pathStatus,
        ];
    }

    private static function validateSchemaCompatibility(array $schema): array
    {
        $issues = [];

        foreach ($schema as $key => $meta) {
            $simulator = is_array($meta['simulator'] ?? null) ? $meta['simulator'] : null;
            $playTest = is_array($meta['play_test'] ?? null) ? $meta['play_test'] : null;

            if ($simulator === null || $playTest === null) {
                $issues[] = self::issue(
                    'missing_mapping',
                    'Canonical key is missing simulator or play-test mapping metadata.',
                    (string)$key
                );
                continue;
            }

            if ((string)($simulator['units'] ?? '') !== (string)($playTest['units'] ?? '')) {
                $issues[] = self::issue(
                    'unit_mismatch',
                    'Simulator and play-test units differ for this canonical key.',
                    (string)$key,
                    [
                        'simulator_units' => $simulator['units'] ?? null,
                        'play_test_units' => $playTest['units'] ?? null,
                    ]
                );
            }

            if ((string)($simulator['type'] ?? '') !== (string)($playTest['type'] ?? '')) {
                $issues[] = self::issue(
                    'lossy_conversion',
                    'Simulator and play-test types differ for this canonical key.',
                    (string)$key,
                    [
                        'simulator_type' => $simulator['type'] ?? null,
                        'play_test_type' => $playTest['type'] ?? null,
                    ]
                );
            }

            if (!self::rangesCompatible($simulator, $playTest)) {
                $issues[] = self::issue(
                    'incompatible_range',
                    'Simulator and play-test ranges are not compatible for this canonical key.',
                    (string)$key,
                    [
                        'simulator_range' => self::rangeDescriptor($simulator),
                        'play_test_range' => self::rangeDescriptor($playTest),
                    ]
                );
            }
        }

        return $issues;
    }

    private static function normalizeToCanonicalValue(
        string $key,
        mixed $value,
        array $schema,
        array &$issues,
        string $source
    ): mixed {
        $type = (string)($schema[$key]['type'] ?? '');

        return match ($type) {
            'int', 'bool_int' => self::normalizeIntegerLikeValue($key, $value, $issues, $source),
            'inflation_table', 'starprice_table', 'starprice_demand_table', 'vault_config'
                => self::normalizeStructuredValue($key, $value, $issues, $source),
            default => self::invalidFromUnsupportedType($key, $type, $issues),
        };
    }

    private static function normalizeIntegerLikeValue(string $key, mixed $value, array &$issues, string $source): mixed
    {
        if (!is_int($value)) {
            $issues[] = self::issue(
                'lossy_conversion',
                ucfirst($source) . ' value must already be an integer to remain lossless.',
                $key,
                ['value' => $value]
            );
            return self::invalidValueSentinel();
        }

        return $value;
    }

    private static function normalizeStructuredValue(string $key, mixed $value, array &$issues, string $source): mixed
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            $issues[] = self::issue(
                'lossy_conversion',
                ucfirst($source) . ' structured value must be a non-empty array or JSON string.',
                $key,
                ['value' => $value]
            );
            return self::invalidValueSentinel();
        }

        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            $issues[] = self::issue(
                'lossy_conversion',
                ucfirst($source) . ' structured value could not be decoded from JSON.',
                $key,
                ['value' => $value]
            );
            return self::invalidValueSentinel();
        }

        return $decoded;
    }

    private static function encodeForTarget(string $key, mixed $canonicalValue, array $targetMeta): mixed
    {
        $codec = (string)($targetMeta['codec'] ?? 'identity');
        if ($codec === 'identity') {
            return $canonicalValue;
        }

        if ($codec === 'json') {
            $encoded = json_encode($canonicalValue, JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                throw new CanonicalEconomyConfigContractException('Failed to JSON-encode canonical key: ' . $key);
            }
            return $encoded;
        }

        throw new CanonicalEconomyConfigContractException('Unsupported target codec for canonical key: ' . $key);
    }

    private static function decodeFromTarget(
        string $key,
        mixed $targetValue,
        array $targetMeta,
        array &$issues,
        string $source
    ): mixed {
        $codec = (string)($targetMeta['codec'] ?? 'identity');
        if ($codec === 'identity') {
            return $targetValue;
        }

        if ($codec === 'json') {
            if (!is_string($targetValue)) {
                $issues[] = self::issue(
                    'lossy_conversion',
                    ucfirst($source) . ' target value is not JSON text as expected.',
                    $key,
                    ['value' => $targetValue]
                );
                return self::invalidValueSentinel();
            }

            $decoded = json_decode($targetValue, true);
            if (!is_array($decoded)) {
                $issues[] = self::issue(
                    'lossy_conversion',
                    ucfirst($source) . ' target JSON could not be decoded back into canonical shape.',
                    $key,
                    ['value' => $targetValue]
                );
                return self::invalidValueSentinel();
            }

            return $decoded;
        }

        $issues[] = self::issue(
            'lossy_conversion',
            ucfirst($source) . ' target codec is not recognized.',
            $key,
            ['codec' => $codec]
        );
        return self::invalidValueSentinel();
    }

    private static function valuesEqual(mixed $left, mixed $right): bool
    {
        return $left === $right;
    }

    private static function rangesCompatible(array $left, array $right): bool
    {
        return self::rangeDescriptor($left) === self::rangeDescriptor($right);
    }

    private static function rangeDescriptor(array $meta): array
    {
        return [
            'min' => $meta['min'] ?? null,
            'max' => $meta['max'] ?? null,
            'max_from_context' => $meta['max_from_context'] ?? null,
        ];
    }

    private static function defaultCodecForType(string $type): string
    {
        return match ($type) {
            'int', 'bool_int' => 'identity',
            'inflation_table', 'starprice_table', 'starprice_demand_table', 'vault_config' => 'json',
            default => 'identity',
        };
    }

    private static function seedSchemaForDefaults(): array
    {
        $schema = [];
        foreach (self::PATCHABLE_PARAMETER_SCHEMA as $key => $meta) {
            $schema[$key] = $meta;
        }
        return $schema;
    }

    private static function invalidFromUnsupportedType(string $key, string $type, array &$issues): mixed
    {
        $issues[] = self::issue(
            'lossy_conversion',
            'Canonical schema type is not supported by the mapper.',
            $key,
            ['type' => $type]
        );
        return self::invalidValueSentinel();
    }

    private static function invalidValueSentinel(): object
    {
        static $sentinel = null;
        if ($sentinel === null) {
            $sentinel = new stdClass();
        }

        return $sentinel;
    }

    private static function issue(string $code, string $detail, string $key = '', array $extra = []): array
    {
        return array_merge([
            'code' => $code,
            'key' => $key,
            'detail' => $detail,
        ], $extra);
    }

    private static function buildFailureMessage(array $issues): string
    {
        if ($issues === []) {
            return 'Compatibility validation failed.';
        }

        $parts = [];
        foreach (array_slice($issues, 0, 5) as $issue) {
            $parts[] = (($issue['key'] ?? '') !== '' ? (string)$issue['key'] . ' ' : '')
                . '(' . (string)($issue['code'] ?? 'invalid') . ')';
        }

        if (count($issues) > 5) {
            $parts[] = '+' . (count($issues) - 5) . ' more';
        }

        return 'Compatibility validation failed: ' . implode(', ', $parts) . '.';
    }

    private static function formatInlineValue(mixed $value): string
    {
        return (string)json_encode($value, JSON_UNESCAPED_SLASHES);
    }

    private static function isAssoc(array $array): bool
    {
        if ($array === []) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }
}
