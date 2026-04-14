<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/simulation/PromotionPatchGenerator.php';

class PromotionPatchGeneratorTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tmc_promotion_patch_' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->tempDir);
    }

    public function testGeneratorProducesDeterministicBundleArtifacts(): void
    {
        $baseSeason = SimulationSeason::build(1, 'promotion-bundle-base');
        $candidateSeason = SimulationSeason::build(1, 'promotion-bundle-candidate', [
            'base_ubi_active_per_tick' => 42,
            'hoarding_safe_hours' => 24,
            'starprice_table' => json_encode([
                ['m' => 0, 'price' => 100],
                ['m' => 25000, 'price' => 240],
                ['m' => 100000, 'price' => 560],
                ['m' => 500000, 'price' => 1680],
                ['m' => 2000000, 'price' => 4300],
            ], JSON_UNESCAPED_SLASHES),
        ]);

        $resultA = PromotionPatchGenerator::generate([
            'candidate_id' => 'balanced-safe-v1',
            'bundle_id' => 'bundle-a',
            'canonical_config' => $candidateSeason,
            'base_season' => $baseSeason,
            'output_dir' => $this->tempDir . DIRECTORY_SEPARATOR . 'a',
            'repo_root' => dirname(__DIR__),
        ]);
        $resultB = PromotionPatchGenerator::generate([
            'candidate_id' => 'balanced-safe-v1',
            'bundle_id' => 'bundle-b',
            'canonical_config' => $candidateSeason,
            'base_season' => $baseSeason,
            'output_dir' => $this->tempDir . DIRECTORY_SEPARATOR . 'b',
            'repo_root' => dirname(__DIR__),
        ]);

        $bundleA = (array)$resultA['bundle'];
        $bundleB = (array)$resultB['bundle'];

        $this->assertSame($bundleA['changed_keys'], $bundleB['changed_keys']);
        $this->assertSame($bundleA['canonical_patch'], $bundleB['canonical_patch']);
        $this->assertSame($bundleA['play_test_patch'], $bundleB['play_test_patch']);
        $this->assertSame($bundleA['repo_changes'][0]['relative_path'], $bundleB['repo_changes'][0]['relative_path']);
        $this->assertSame(
            file_get_contents((string)$resultA['artifact_paths']['staged_repo_file']),
            file_get_contents((string)$resultB['artifact_paths']['staged_repo_file'])
        );
        $this->assertSame(
            file_get_contents((string)$resultA['artifact_paths']['repo_patch_diff']),
            file_get_contents((string)$resultB['artifact_paths']['repo_patch_diff'])
        );
        $this->assertSame(
            file_get_contents((string)$resultA['artifact_paths']['canonical_patch_json']),
            file_get_contents((string)$resultB['artifact_paths']['canonical_patch_json'])
        );
        $this->assertStringContainsString(
            'migration_z_sim_promotion_balanced-safe-v1_',
            (string)$bundleA['repo_changes'][0]['relative_path']
        );
    }

    public function testGeneratorRejectsUnapprovedRepoTargets(): void
    {
        $this->expectException(PromotionPatchGeneratorException::class);
        $this->expectExceptionMessage('approved root migration files');

        $baseSeason = SimulationSeason::build(1, 'promotion-bundle-base');
        $candidateSeason = SimulationSeason::build(1, 'promotion-bundle-candidate', [
            'base_ubi_active_per_tick' => 42,
        ]);

        PromotionPatchGenerator::generate([
            'candidate_id' => 'bad-target',
            'bundle_id' => 'bundle-bad-target',
            'canonical_config' => $candidateSeason,
            'base_season' => $baseSeason,
            'migration_relative_path' => 'includes/config.php',
            'output_dir' => $this->tempDir,
            'repo_root' => dirname(__DIR__),
        ]);
    }

    public function testGeneratedPatchedSeasonMatchesCanonicalConfigAndPassesSchemaValidation(): void
    {
        $baseSeason = SimulationSeason::build(1, 'promotion-bundle-base');
        $candidateSeason = SimulationSeason::build(1, 'promotion-bundle-candidate', [
            'base_ubi_active_per_tick' => 42,
            'hoarding_sink_enabled' => 1,
            'hoarding_safe_hours' => 36,
            'vault_config' => json_encode([
                ['tier' => 1, 'supply' => 500, 'cost_table' => [['remaining' => 1, 'cost' => 50]]],
                ['tier' => 2, 'supply' => 250, 'cost_table' => [['remaining' => 1, 'cost' => 260]]],
                ['tier' => 3, 'supply' => 125, 'cost_table' => [['remaining' => 1, 'cost' => 1025]]],
            ], JSON_UNESCAPED_SLASHES),
        ]);

        $result = PromotionPatchGenerator::generate([
            'candidate_id' => 'schema-check-v1',
            'bundle_id' => 'bundle-schema-check',
            'canonical_config' => $candidateSeason,
            'base_season' => $baseSeason,
            'output_dir' => $this->tempDir,
            'repo_root' => dirname(__DIR__),
        ]);

        $bundle = (array)$result['bundle'];
        $patchedSeason = SimulationSeason::fromJsonFile(
            (string)$result['artifact_paths']['patched_play_test_season_json'],
            1,
            'schema-check-v1'
        );

        $candidateMapped = CanonicalEconomyConfigContract::mapSimulatorPatchToPlayTestPatch(
            $this->patchableSurface($candidateSeason)
        );
        $patchedMapped = CanonicalEconomyConfigContract::mapSimulatorPatchToPlayTestPatch(
            $this->patchableSurface($patchedSeason)
        );

        $this->assertTrue((bool)$bundle['validation']['schema_valid']);
        $this->assertTrue((bool)$bundle['validation']['candidate_matches_patched']);
        $this->assertSame(
            $candidateMapped['canonical_patch'],
            $patchedMapped['canonical_patch']
        );
    }

    private function patchableSurface(array $season): array
    {
        $surface = [];
        foreach (array_keys(CanonicalEconomyConfigContract::patchableParameters()) as $key) {
            $surface[$key] = $season[$key];
        }

        return $surface;
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
