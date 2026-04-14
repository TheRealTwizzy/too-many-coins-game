<?php

use PHPUnit\Framework\TestCase;

putenv('TMC_TICK_REAL_SECONDS=3600');

require_once __DIR__ . '/../scripts/simulation/CandidatePromotionPipeline.php';

class CandidatePromotionPipelineTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tmc_promotion_pipeline_' . uniqid();
        mkdir($this->tempDir, 0777, true);
        putenv(CandidatePromotionPipeline::DEBUG_BYPASS_ENV);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->tempDir);
    }

    public function testEligibleCandidatePassesAllStagesAndWritesArtifacts(): void
    {
        $baseSeason = SimulationSeason::build(1, 'promotion-pass');
        $result = CandidatePromotionPipeline::run([
            'candidate_id' => 'promotion-pass',
            'seed' => 'promotion-pass',
            'candidate_document' => [
                'base_ubi_active_per_tick' => (int)$baseSeason['base_ubi_active_per_tick'],
            ],
            'output_dir' => $this->tempDir,
            'players_per_archetype' => 1,
            'season_count' => 4,
        ]);

        $state = (array)$result['state'];
        $this->assertSame('eligible', (string)$state['status']);
        $this->assertTrue((bool)$state['patch_ready']);
        $this->assertTrue((bool)$state['promotion_eligible']);
        $this->assertFalse((bool)$state['debug_bypass_used']);
        $this->assertCount(9, (array)$state['stages']);
        foreach ((array)$state['stages'] as $stage) {
            $this->assertSame('pass', (string)$stage['status'], 'Stage did not pass: ' . (string)$stage['id']);
        }

        $parityStage = (array)$state['stages'][6];
        $this->assertFileExists((string)$parityStage['artifacts']['runtime_parity_certification_json']);
        $this->assertFileExists((string)$parityStage['artifacts']['runtime_parity_certification_md']);

        $compatStage = (array)$state['stages'][7];
        $this->assertFileExists((string)$compatStage['artifacts']['play_test_repo_compatibility_json']);
        $this->assertFileExists((string)$compatStage['artifacts']['play_test_repo_compatibility_md']);

        $this->assertFileExists((string)$result['artifact_paths']['promotion_state_json']);
        $this->assertFileExists((string)$result['artifact_paths']['promotion_report_json']);
        $this->assertFileExists((string)$result['artifact_paths']['promotion_report_md']);
    }

    public function testFailedSchemaValidationBlocksLaterStages(): void
    {
        $result = CandidatePromotionPipeline::run([
            'candidate_id' => 'promotion-fail',
            'seed' => 'promotion-fail',
            'candidate_document' => [
                'not_a_real_key' => 1,
            ],
            'output_dir' => $this->tempDir,
            'players_per_archetype' => 1,
            'season_count' => 4,
        ]);

        $state = (array)$result['state'];
        $this->assertSame('ineligible', (string)$state['status']);
        $this->assertFalse((bool)$state['patch_ready']);
        $this->assertFalse((bool)$state['promotion_eligible']);
        $this->assertSame('fail', (string)$state['stages'][0]['status']);
        $this->assertSame('blocked', (string)$state['stages'][1]['status']);
        $this->assertSame('blocked', (string)$state['stages'][7]['status']);
        $this->assertSame('fail', (string)$state['stages'][8]['status']);
    }

    public function testDebugBypassAllowsDiagnosticContinuationButNeverMarksPatchReady(): void
    {
        $result = CandidatePromotionPipeline::run([
            'candidate_id' => 'promotion-bypass',
            'seed' => 'promotion-bypass',
            'candidate_document' => [
                'runtime.tick_real_seconds' => 120,
            ],
            'debug_bypass_stages' => [
                'candidate_schema_validation',
                'effective_config_preflight',
            ],
            'output_dir' => $this->tempDir,
            'players_per_archetype' => 1,
            'season_count' => 4,
        ]);

        $state = (array)$result['state'];
        $this->assertSame('debug_only', (string)$state['status']);
        $this->assertFalse((bool)$state['patch_ready']);
        $this->assertFalse((bool)$state['promotion_eligible']);
        $this->assertTrue((bool)$state['debug_bypass_used']);
        $this->assertSame('bypassed', (string)$state['stages'][0]['status']);
        $this->assertSame('bypassed', (string)$state['stages'][1]['status']);
        $this->assertSame('pass', (string)$state['stages'][2]['status']);
        $this->assertSame('fail', (string)$state['stages'][8]['status']);
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
