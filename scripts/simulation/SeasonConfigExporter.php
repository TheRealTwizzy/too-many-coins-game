<?php

require_once __DIR__ . '/SimulationSeason.php';
require_once __DIR__ . '/CanonicalEconomyConfigContract.php';

class SeasonConfigExporter
{
    public const CANONICAL_SCHEMA_VERSION = 'tmc-season-canonical-export.v1';
    public const METADATA_SCHEMA_VERSION = 'tmc-season-export-metadata.v1';

    private const METADATA_KEYS = [
        'season_id',
        'start_time',
        'end_time',
        'blackout_time',
        'season_seed_hex',
        'status',
        'season_expired',
        'expiration_finalized',
        'created_at',
    ];

    private const RUNTIME_ONLY_KEYS = [
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

    public static function canonicalConfigKeys(): array
    {
        return array_keys(CanonicalEconomyConfigContract::patchableParameters());
    }

    public static function metadataKeys(): array
    {
        return self::METADATA_KEYS;
    }

    public static function runtimeOnlyKeys(): array
    {
        return self::RUNTIME_ONLY_KEYS;
    }

    public static function dbSelectExpressions(): array
    {
        $expressions = self::canonicalConfigKeys();

        foreach ([
            'season_id',
            'start_time',
            'end_time',
            'blackout_time',
            'HEX(season_seed) AS season_seed_hex',
            'status',
            'season_expired',
            'expiration_finalized',
            'created_at',
        ] as $expression) {
            $expressions[] = $expression;
        }

        foreach (self::runtimeOnlyKeys() as $key) {
            $expressions[] = $key;
        }

        return $expressions;
    }

    public static function exportDocumentsFromRow(array $row): array
    {
        self::assertBoundaryIntegrity();

        return [
            'canonical_config' => self::canonicalConfigFromRow($row),
            'metadata' => self::metadataDocumentFromRow($row),
        ];
    }

    public static function canonicalConfigFromRow(array $row): array
    {
        self::assertBoundaryIntegrity();

        $normalized = self::normalizeRowForExport($row);
        $canonical = [];
        $missing = [];

        foreach (self::canonicalConfigKeys() as $key) {
            if (!array_key_exists($key, $normalized)) {
                $missing[] = $key;
                continue;
            }

            $canonical[$key] = $normalized[$key];
        }

        if ($missing !== []) {
            throw new InvalidArgumentException(
                'Season export row is missing canonical patchable keys: ' . implode(', ', $missing)
            );
        }

        return $canonical;
    }

    public static function metadataDocumentFromRow(array $row): array
    {
        self::assertBoundaryIntegrity();

        return [
            'schema_version' => self::METADATA_SCHEMA_VERSION,
            'exported_at' => gmdate('c'),
            'boundary' => [
                'canonical_patchable_keys' => self::canonicalConfigKeys(),
                'metadata_keys' => self::metadataKeys(),
                'runtime_only_keys' => self::runtimeOnlyKeys(),
            ],
            'metadata' => self::metadataFromRow($row),
            'runtime_state' => self::runtimeStateFromRow($row),
        ];
    }

    public static function metadataFromRow(array $row): array
    {
        $normalized = self::normalizeRowForExport($row);
        $metadata = [];

        foreach (self::metadataKeys() as $key) {
            if (!array_key_exists($key, $normalized)) {
                continue;
            }

            $metadata[$key] = $normalized[$key];
        }

        return $metadata;
    }

    public static function runtimeStateFromRow(array $row): array
    {
        $normalized = self::normalizeRowForExport($row);
        $runtime = [];

        foreach (self::runtimeOnlyKeys() as $key) {
            if (!array_key_exists($key, $normalized)) {
                continue;
            }

            $runtime[$key] = $normalized[$key];
        }

        return $runtime;
    }

    public static function canonicalOverridesFromSeason(array $season): array
    {
        return self::canonicalConfigFromRow($season);
    }

    private static function normalizeRowForExport(array $row): array
    {
        $normalized = SimulationSeason::normalizeImportedRow($row);
        if (isset($normalized['season_seed']) && is_string($normalized['season_seed'])) {
            $normalized['season_seed_hex'] = bin2hex($normalized['season_seed']);
            unset($normalized['season_seed']);
        }

        return $normalized;
    }

    private static function assertBoundaryIntegrity(): void
    {
        static $validated = false;
        if ($validated) {
            return;
        }

        $patchable = self::canonicalConfigKeys();
        $metadata = self::metadataKeys();
        $runtime = self::runtimeOnlyKeys();

        $patchableIndex = array_fill_keys($patchable, true);
        foreach (array_merge($metadata, $runtime) as $key) {
            if (isset($patchableIndex[$key])) {
                throw new LogicException('Season export boundary overlaps patchable and non-patchable key: ' . $key);
            }
        }

        $runtimeIndex = array_fill_keys($runtime, true);
        foreach ($metadata as $key) {
            if (isset($runtimeIndex[$key])) {
                throw new LogicException('Season export boundary overlaps metadata and runtime key: ' . $key);
            }
        }

        $knownSeasonKeys = array_fill_keys(SimulationSeason::SEASON_ECONOMY_COLUMNS, true);
        foreach ($patchable as $key) {
            if (!isset($knownSeasonKeys[$key])) {
                throw new LogicException('Canonical patchable key is missing from SimulationSeason schema: ' . $key);
            }
        }

        foreach ($runtime as $key) {
            if (!isset($knownSeasonKeys[$key])) {
                throw new LogicException('Runtime-only key is missing from SimulationSeason schema: ' . $key);
            }
        }

        $validated = true;
    }
}
