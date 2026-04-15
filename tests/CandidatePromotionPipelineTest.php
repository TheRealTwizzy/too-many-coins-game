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
        $this->assertSame('blocked', (string)$state['stages'][8]['status']);
        $this->assertSame('fail', (string)$state['stages'][9]['status']);
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
