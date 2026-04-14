<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/simulation/CanonicalEconomyConfigContract.php';

class CanonicalEconomyConfigContractTest extends TestCase
{
    public function testSchemaDocumentsDefaultsMappingsAndDependencies(): void
    {
        $schema = CanonicalEconomyConfigContract::schema();
        $keys = (array)$schema['keys'];

        $this->assertSame(CanonicalEconomyConfigContract::SCHEMA_VERSION, $schema['schema_version']);
        $this->assertSame('coins_per_tick', (string)$keys['base_ubi_active_per_tick']['units']);
        $this->assertSame(30, (int)$keys['base_ubi_active_per_tick']['default']);
        $this->assertSame('season.base_ubi_active_per_tick', (string)$keys['base_ubi_active_per_tick']['simulator']['path']);
        $this->assertSame('base_ubi_active_per_tick', (string)$keys['base_ubi_active_per_tick']['play_test']['column']);
        $this->assertSame('season.hoarding_sink_enabled', (string)$keys['hoarding_safe_hours']['feature_flag']);
        $this->assertIsArray($keys['starprice_table']['default']);
    }

    public function testSimulatorPatchMapsLosslesslyIntoPlayTestPatchAndBack(): void
    {
        $patch = [
            'base_ubi_active_per_tick' => 42,
            'starprice_table' => [
                ['m' => 0, 'price' => 100],
                ['m' => 25000, 'price' => 220],
            ],
        ];

        $mapped = CanonicalEconomyConfigContract::mapSimulatorPatchToPlayTestPatch($patch);

        $this->assertSame(42, (int)$mapped['canonical_patch']['base_ubi_active_per_tick']);
        $this->assertSame(42, (int)$mapped['play_test_patch']['base_ubi_active_per_tick']);
        $this->assertIsString($mapped['play_test_patch']['starprice_table']);
        $this->assertSame($patch['starprice_table'], $mapped['round_trip_patch']['starprice_table']);
        $this->assertTrue((bool)$mapped['report']['round_trip_equal']);
    }

    public function testUnsupportedKeyIsRejected(): void
    {
        $report = CanonicalEconomyConfigContract::buildCompatibilityReport([
            'not_a_real_key' => 1,
        ]);

        $this->assertSame('fail', $report['status']);
        $this->assertContains('unsupported_key', array_column((array)$report['issues'], 'code'));
    }

    public function testMissingMappingIsRejected(): void
    {
        $schema = CanonicalEconomyConfigContract::patchableParameters();
        unset($schema['base_ubi_active_per_tick']['play_test']);

        $report = CanonicalEconomyConfigContract::buildCompatibilityReport([
            'base_ubi_active_per_tick' => 42,
        ], [
            'schema' => $schema,
        ]);

        $this->assertSame('fail', $report['status']);
        $this->assertContains('missing_mapping', array_column((array)$report['issues'], 'code'));
    }

    public function testUnitMismatchIsRejected(): void
    {
        $schema = CanonicalEconomyConfigContract::patchableParameters();
        $schema['base_ubi_active_per_tick']['play_test']['units'] = 'stars_per_tick';

        $report = CanonicalEconomyConfigContract::buildCompatibilityReport([
            'base_ubi_active_per_tick' => 42,
        ], [
            'schema' => $schema,
        ]);

        $this->assertSame('fail', $report['status']);
        $this->assertContains('unit_mismatch', array_column((array)$report['issues'], 'code'));
    }

    public function testIncompatibleRangeIsRejected(): void
    {
        $schema = CanonicalEconomyConfigContract::patchableParameters();
        $schema['base_ubi_active_per_tick']['play_test']['max'] = 20;

        $report = CanonicalEconomyConfigContract::buildCompatibilityReport([
            'base_ubi_active_per_tick' => 42,
        ], [
            'schema' => $schema,
        ]);

        $this->assertSame('fail', $report['status']);
        $this->assertContains('incompatible_range', array_column((array)$report['issues'], 'code'));
    }

    public function testLossyConversionIsRejected(): void
    {
        $schema = CanonicalEconomyConfigContract::patchableParameters();
        $schema['base_ubi_active_per_tick']['play_test']['codec'] = 'json';

        $report = CanonicalEconomyConfigContract::buildCompatibilityReport([
            'base_ubi_active_per_tick' => 42,
        ], [
            'schema' => $schema,
        ]);

        $this->assertSame('fail', $report['status']);
        $this->assertContains('lossy_conversion', array_column((array)$report['issues'], 'code'));
    }
}
