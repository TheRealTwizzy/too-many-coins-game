<?php

use PHPUnit\Framework\TestCase;

putenv('TMC_TICK_REAL_SECONDS=3600');

require_once __DIR__ . '/../scripts/simulation/SeasonConfigExporter.php';
require_once __DIR__ . '/../scripts/simulation/SimulationConfigPreflight.php';
require_once __DIR__ . '/../scripts/simulation/CandidatePromotionPipeline.php';

class SeasonConfigExporterTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tmc_season_export_' . uniqid();
        mkdir($this->tempDir, 0777, true);
        putenv(CandidatePromotionPipeline::DEBUG_BYPASS_ENV);
        putenv(SimulationConfigPreflight::AUDIT_ENV_BYPASS);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->tempDir);
    }

    public function testExporterSeparatesCanonicalConfigMetadataAndRuntimeState(): void
    {
        $season = SimulationSeason::build(1, 'season-export-boundary');
        $season['status'] = 'Active';
        $season['current_star_price'] = 777;
        $season['created_at'] = '2026-04-14 00:00:00';

        $export = SeasonConfigExporter::exportDocumentsFromRow($season);
        $canonical = (array)$export['canonical_config'];
        $metadataDocument = (array)$export['metadata'];
        $metadata = (array)($metadataDocument['metadata'] ?? []);
        $runtimeState = (array)($metadataDocument['runtime_state'] ?? []);

        $this->assertSame(SeasonConfigExporter::canonicalConfigKeys(), array_keys($canonical));
        $this->assertArrayNotHasKey('created_at', $canonical);
        $this->assertArrayNotHasKey('status', $canonical);
        $this->assertArrayNotHasKey('current_star_price', $canonical);

        $this->assertSame('2026-04-14 00:00:00', (string)$metadata['created_at']);
        $this->assertSame('Active', (string)$metadata['status']);
        $this->assertSame(64, strlen((string)$metadata['season_seed_hex']));
        $this->assertSame(777, (int)$runtimeState['current_star_price']);
    }

    public function testCanonicalExportedBaselinePassesPreflight(): void
    {
        $season = SimulationSeason::build(1, 'season-export-preflight');
        $exportPath = $this->writeCanonicalExport($season, 'baseline.json');

        $resolved = SimulationConfigPreflight::resolve([
            'seed' => 'season-export-preflight',
            'season_id' => 1,
            'simulator' => 'B',
            'players_per_archetype' => 1,
            'base_season_config_path' => $exportPath,
            'artifact_dir' => $this->tempDir . DIRECTORY_SEPARATOR . 'preflight_audit',
        ]);

        $this->assertSame('pass', (string)$resolved['report']['status']);
        $this->assertSame('file', (string)$resolved['report']['context']['base_season_source']);
        $this->assertFileExists((string)$resolved['artifact_paths']['effective_config_json']);
        $this->assertFileExists((string)$resolved['artifact_paths']['effective_config_audit_md']);
    }

    public function testCanonicalExportRoundTripsIntoPromotionPipelineInput(): void
    {
        $season = SimulationSeason::build(1, 'season-export-promotion');
        $exportPath = $this->writeCanonicalExport($season, 'promotion_baseline.json');

        $result = CandidatePromotionPipeline::run([
            'candidate_id' => 'season-export-promotion',
            'seed' => 'season-export-promotion',
            'candidate_document' => [
                'base_ubi_active_per_tick' => (int)$season['base_ubi_active_per_tick'],
            ],
            'base_season_config_path' => $exportPath,
            'output_dir' => $this->tempDir . DIRECTORY_SEPARATOR . 'promotion',
            'players_per_archetype' => 1,
            'season_count' => 4,
        ]);

        $state = (array)$result['state'];
        $this->assertSame('eligible', (string)$state['status']);
        $this->assertSame('pass', (string)$state['stages'][0]['status']);
        $this->assertSame('pass', (string)$state['stages'][1]['status']);
    }

    private function writeCanonicalExport(array $season, string $filename): string
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . $filename;
        file_put_contents(
            $path,
            json_encode(SeasonConfigExporter::canonicalConfigFromRow($season), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        return $path;
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
