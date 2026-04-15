<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/simulation/SeasonConfigExporter.php';
require_once __DIR__ . '/../scripts/simulation/SimulationConfigPreflight.php';

class OfficialBaselineArtifactTest extends TestCase
{
    private string $artifactPath;
    private string $snapshotPath;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->artifactPath = __DIR__ . '/../simulation_output/current-db/export/current_season_economy_only.json';
        $this->snapshotPath = __DIR__ . '/../simulation_output/current-db/export/current_season.json';
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tmc_official_baseline_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->tempDir);
    }

    public function testOfficialBaselineArtifactPassesPreflightUnchanged(): void
    {
        $resolved = SimulationConfigPreflight::resolve([
            'seed' => 'official-baseline-artifact',
            'season_id' => 1,
            'simulator' => 'B',
            'players_per_archetype' => 1,
            'base_season_config_path' => $this->artifactPath,
            'artifact_dir' => $this->tempDir . DIRECTORY_SEPARATOR . 'audit',
        ]);

        $this->assertSame('pass', (string)$resolved['report']['status']);
        $this->assertSame('file', (string)$resolved['report']['context']['base_season_source']);
        $this->assertStringEndsWith(
            'simulation_output/current-db/export/current_season_economy_only.json',
            (string)$resolved['report']['context']['base_season_config_path']
        );
    }

    public function testOfficialBaselineArtifactExcludesForbiddenMetadataAndRuntimeFields(): void
    {
        $artifact = json_decode((string)file_get_contents($this->artifactPath), true);

        $this->assertIsArray($artifact);
        $this->assertArrayNotHasKey('starprice_model_version', $artifact);
        foreach (array_merge(SeasonConfigExporter::metadataKeys(), SeasonConfigExporter::runtimeOnlyKeys()) as $key) {
            $this->assertArrayNotHasKey($key, $artifact, 'Official baseline artifact must exclude non-canonical key: ' . $key);
        }
        $this->assertSame(SeasonConfigExporter::canonicalConfigKeys(), array_keys($artifact));
    }

    public function testCheckedInCanonicalArtifactMatchesTrackedSnapshotProjection(): void
    {
        $snapshot = json_decode((string)file_get_contents($this->snapshotPath), true);
        $artifact = json_decode((string)file_get_contents($this->artifactPath), true);

        $this->assertSame(
            SeasonConfigExporter::canonicalConfigFromRow((array)$snapshot),
            $artifact,
            'Checked-in canonical artifact must stay aligned with the exporter projection of the tracked season snapshot.'
        );
    }

    private function deleteDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($fullPath)) {
                $this->deleteDir($fullPath);
                continue;
            }

            @unlink($fullPath);
        }

        @rmdir($path);
    }
}
