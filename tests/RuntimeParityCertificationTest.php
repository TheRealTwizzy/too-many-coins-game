<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/simulation/RuntimeParityCertification.php';

class RuntimeParityCertificationTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tmc_runtime_parity_' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->tempDir);
    }

    public function testCertificationCoversAllRequiredDomainsAndWritesArtifacts(): void
    {
        $result = RuntimeParityCertification::run([
            'candidate_id' => 'runtime-parity-test',
            'seed' => 'runtime-parity-test',
            'output_dir' => $this->tempDir,
        ]);

        $report = (array)$result['report'];
        $domains = array_map(static fn(array $domain): string => (string)$domain['domain_id'], (array)$report['domains']);

        $this->assertSame('pass', (string)$report['certification_status']);
        $this->assertTrue((bool)$report['certified']);
        $this->assertSame([
            'hoarding_sink_behavior',
            'boost_behavior',
            'lock_in_timing',
            'expiry_timing',
            'star_pricing_affordability',
            'rejoin_participation_effects',
            'blackout_finalization_interactions',
        ], $domains);
        $this->assertFileExists((string)$result['artifact_paths']['runtime_parity_certification_json']);
        $this->assertFileExists((string)$result['artifact_paths']['runtime_parity_certification_md']);
    }

    public function testCompareMetricsFlagsMaterialAndToleratedDriftSeparately(): void
    {
        $comparison = RuntimeParityCertification::compareMetrics(
            [
                'coins_after' => 0,
                'published_star_price' => 0,
            ],
            [
                'coins_after' => 100,
                'published_star_price' => 250,
            ],
            [
                'coins_after' => 90,
                'published_star_price' => 255,
            ],
            ['published_star_price']
        );

        $this->assertSame('fail', (string)$comparison['status']);
        $this->assertSame(1, (int)$comparison['material_drift_count']);
        $this->assertSame(1, (int)$comparison['tolerated_difference_count']);
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
            } else {
                @unlink($fullPath);
            }
        }

        @rmdir($path);
    }
}
