<?php

use PHPUnit\Framework\TestCase;

// Speed up lifetime + sweep runs in test.
putenv('TMC_TICK_REAL_SECONDS=3600');

require_once __DIR__ . '/../scripts/simulation/SimulationSeason.php';
require_once __DIR__ . '/../scripts/simulation/SeasonConfigExporter.php';
require_once __DIR__ . '/../scripts/simulation/SimulationRandom.php';
require_once __DIR__ . '/../scripts/simulation/Archetypes.php';
require_once __DIR__ . '/../scripts/simulation/PolicyBehavior.php';
require_once __DIR__ . '/../scripts/simulation/MetricsCollector.php';
require_once __DIR__ . '/../scripts/simulation/SimulationPlayer.php';
require_once __DIR__ . '/../scripts/simulation/SimulationPopulationSeason.php';
require_once __DIR__ . '/../scripts/simulation/SimulationPopulationLifetime.php';
require_once __DIR__ . '/../scripts/simulation/PolicyScenarioCatalog.php';
require_once __DIR__ . '/../scripts/simulation/PolicySweepRunner.php';
require_once __DIR__ . '/../scripts/simulation/ResultComparator.php';

/**
 * Verifies the canonical export/import path end-to-end without a real DB.
 *
 * The CLI exporter now emits only canonical patchable economy config.
 * These tests emulate that through SeasonConfigExporter, which is the same
 * shared helper used by tools/export-season-config.php.
 */
class SimulationExportImportTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tmc_export_import_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    // -------------------------------------------------------------------------
    // Shape contract
    // -------------------------------------------------------------------------

    public function testExportedConfigContainsOnlyCanonicalPatchableKeys(): void
    {
        $season = SimulationSeason::build(1, 'export-shape-test');
        $exported = $this->serializeAsExport($season);

        $this->assertSame(
            SeasonConfigExporter::canonicalConfigKeys(),
            array_keys($exported),
            'Canonical export must contain only the explicit patchable tuning surface.'
        );

        foreach (array_merge(SeasonConfigExporter::metadataKeys(), SeasonConfigExporter::runtimeOnlyKeys()) as $key) {
            $this->assertArrayNotHasKey($key, $exported, "Canonical export must not include non-patchable key: $key");
        }
    }

    public function testExportedConfigRoundTripsThroughFromJsonFile(): void
    {
        $season = SimulationSeason::build(1, 'export-roundtrip-test');
        $exportFile = $this->writeExportFile($season, 'roundtrip.json');

        $imported = SimulationSeason::fromJsonFile($exportFile, 1, 'export-roundtrip-test');

        // Every column must be present after import.
        foreach (SimulationSeason::SEASON_ECONOMY_COLUMNS as $col) {
            $this->assertArrayHasKey($col, $imported, "Imported season missing column: $col");
        }

        // Key numeric parameters must survive the round-trip unchanged.
        $checkKeys = [
            'base_ubi_active_per_tick',
            'target_spend_rate_per_tick',
            'hoarding_window_ticks',
            'hoarding_min_factor_fp',
            'hoarding_safe_hours',
            'starprice_idle_weight_fp',
            'star_price_cap',
            'market_affordability_bias_fp',
        ];
        foreach ($checkKeys as $key) {
            $this->assertSame((int)$season[$key], (int)$imported[$key], "Round-trip changed value of: $key");
        }
    }

    // -------------------------------------------------------------------------
    // Simulation B — accepts exported config
    // -------------------------------------------------------------------------

    public function testSimulationBAcceptsExportedSeasonConfig(): void
    {
        $season = SimulationSeason::build(1, 'export-b-test');
        $exportFile = $this->writeExportFile($season, 'export_b.json');

        $payload = SimulationPopulationSeason::run('export-import-b', 1, $exportFile);

        $this->assertSame('tmc-sim-phase1.v1', $payload['schema_version']);
        $this->assertSame('single-season-population', $payload['simulator']);
        $this->assertCount(10, $payload['archetypes']);
        $this->assertArrayHasKey('diagnostics', $payload);
    }

    public function testSimulationBWithExportedConfigIsDeterministic(): void
    {
        $season = SimulationSeason::build(1, 'export-b-det');
        $exportFile = $this->writeExportFile($season, 'export_b_det.json');

        $first  = SimulationPopulationSeason::run('export-import-b-det', 1, $exportFile);
        $second = SimulationPopulationSeason::run('export-import-b-det', 1, $exportFile);

        $this->assertSame($first['archetypes'], $second['archetypes']);
        $this->assertSame($first['diagnostics'], $second['diagnostics']);
    }

    public function testSimulationBExportedConfigOverridesDefaultParameters(): void
    {
        // Build a config with a deliberately non-default value.
        $customSeason = SimulationSeason::build(1, 'export-b-override', [
            'hoarding_safe_hours' => 99,
        ]);
        $exportFile = $this->writeExportFile($customSeason, 'export_b_override.json');

        // Verify the exported file actually contains the override value.
        $decoded = json_decode((string)file_get_contents($exportFile), true);
        $this->assertSame(99, (int)$decoded['hoarding_safe_hours'], 'Exported file must carry the overridden value.');

        // Run Sim B with this config; it must accept it without error.
        $payload = SimulationPopulationSeason::run('export-b-override-run', 1, $exportFile);
        $this->assertSame('tmc-sim-phase1.v1', $payload['schema_version']);
    }

    // -------------------------------------------------------------------------
    // Simulation C — accepts exported config
    // -------------------------------------------------------------------------

    public function testSimulationCAcceptsExportedSeasonConfig(): void
    {
        $season = SimulationSeason::build(1, 'export-c-test');
        $exportFile = $this->writeExportFile($season, 'export_c.json');

        $payload = SimulationPopulationLifetime::run('export-import-c', 1, 4, $exportFile);

        $this->assertSame('tmc-sim-phase1.v1', $payload['schema_version']);
        $this->assertSame('lifetime-overlapping-season', $payload['simulator']);
        $this->assertCount(4, $payload['season_timeline']);
        $this->assertArrayHasKey('population_diagnostics', $payload);
    }

    public function testSimulationCWithExportedConfigIsDeterministic(): void
    {
        $season = SimulationSeason::build(1, 'export-c-det');
        $exportFile = $this->writeExportFile($season, 'export_c_det.json');

        $first  = SimulationPopulationLifetime::run('export-import-c-det', 1, 4, $exportFile);
        $second = SimulationPopulationLifetime::run('export-import-c-det', 1, 4, $exportFile);

        $this->assertSame($first['players'], $second['players']);
        $this->assertSame($first['population_diagnostics'], $second['population_diagnostics']);
    }

    // -------------------------------------------------------------------------
    // Simulation D — accepts exported config as base season config
    // -------------------------------------------------------------------------

    public function testSimulationDAcceptsExportedSeasonConfigAsBase(): void
    {
        $season = SimulationSeason::build(1, 'export-d-test');
        $exportFile = $this->writeExportFile($season, 'export_d.json');

        $outDir = $this->tempDir . DIRECTORY_SEPARATOR . 'sweep_d';
        $result = PolicySweepRunner::run([
            'seed' => 'export-import-d',
            'players_per_archetype' => 1,
            'season_count' => 4,
            'simulators' => ['B'],
            'scenarios' => ['hoarder-pressure-v1'],
            'include_baseline' => true,
            'base_season_config_path' => $exportFile,
            'output_dir' => $outDir,
        ]);

        $manifest = (array)$result['manifest'];
        $this->assertNotEmpty($manifest['runs']);
        $this->assertSame($exportFile, (string)$manifest['config']['base_season_config_path']);

        // Both baseline and scenario runs must have been produced.
        $scenarioNames = array_column((array)$manifest['runs'], 'scenario_name');
        $this->assertContains('baseline', $scenarioNames);
        $this->assertContains('hoarder-pressure-v1', $scenarioNames);

        // Every run JSON must be readable and valid.
        foreach ($manifest['runs'] as $run) {
            $payload = json_decode((string)file_get_contents((string)$run['json']), true);
            $this->assertSame('tmc-sim-phase1.v1', (string)$payload['schema_version']);
        }
    }

    public function testSimulationDBaseConfigDoesNotMutateProduction(): void
    {
        // Verify that running with a base season config leaves SimulationSeason
        // defaults intact for subsequent calls (isolation guarantee).
        $before = SimulationSeason::build(1, 'isolation-check');

        $season = SimulationSeason::build(1, 'export-d-isolation', ['hoarding_safe_hours' => 55]);
        $exportFile = $this->writeExportFile($season, 'export_d_iso.json');

        PolicySweepRunner::run([
            'seed' => 'export-import-d-iso',
            'players_per_archetype' => 1,
            'season_count' => 4,
            'simulators' => ['B'],
            'scenarios' => [],
            'include_baseline' => true,
            'base_season_config_path' => $exportFile,
            'output_dir' => $this->tempDir . DIRECTORY_SEPARATOR . 'sweep_iso',
        ]);

        $after = SimulationSeason::build(1, 'isolation-check');
        $this->assertSame(
            (int)$before['hoarding_safe_hours'],
            (int)$after['hoarding_safe_hours'],
            'ProductionSeason defaults must be unchanged after a sweep run with a custom base config.'
        );
    }

    // -------------------------------------------------------------------------
    // Manifest/comparator interoperability
    // -------------------------------------------------------------------------

    public function testManifestProducedByExportImportRunIsComparatorCompatible(): void
    {
        $season = SimulationSeason::build(1, 'export-compat-test');
        $exportFile = $this->writeExportFile($season, 'export_compat.json');

        $sweepOutDir = $this->tempDir . DIRECTORY_SEPARATOR . 'sweep_compat';
        $sweepResult = PolicySweepRunner::run([
            'seed' => 'export-compat',
            'players_per_archetype' => 1,
            'season_count' => 4,
            'simulators' => ['B', 'C'],
            'scenarios' => ['hoarder-pressure-v1'],
            'include_baseline' => true,
            'base_season_config_path' => $exportFile,
            'output_dir' => $sweepOutDir,
        ]);

        $comparatorOutDir = $this->tempDir . DIRECTORY_SEPARATOR . 'comparator_compat';
        $cmpResult = \ResultComparator::run([
            'seed' => 'export-compat-cmp',
            'sweep_manifest' => (string)$sweepResult['manifest_path'],
            'baseline_b_paths' => [],
            'baseline_c_paths' => [],
            'output_dir' => $comparatorOutDir,
        ]);

        $payload = (array)$cmpResult['payload'];
        $this->assertSame('tmc-sim-comparator.v1', (string)$payload['comparator_schema_version']);
        $this->assertNotEmpty($payload['scenarios']);

        $scenario = (array)$payload['scenarios'][0];
        $this->assertSame('hoarder-pressure-v1', (string)$scenario['scenario_name']);
        $this->assertArrayHasKey('recommended_disposition', $scenario);
        $this->assertArrayHasKey('regression_flags', $scenario);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Serialize a built season config in the same format that
     * tools/export-season-config.php uses: binary season_seed → season_seed_hex,
     * everything else passed through as-is.
     */
    private function serializeAsExport(array $season): array
    {
        return SeasonConfigExporter::canonicalConfigFromRow($season);
    }

    private function writeExportFile(array $season, string $filename): string
    {
        $exported = $this->serializeAsExport($season);
        $path = $this->tempDir . DIRECTORY_SEPARATOR . $filename;
        file_put_contents($path, json_encode($exported, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $path;
    }
}
