<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/optimization/RejectedIterationInputProducer.php';

class RejectedIterationInputProducerTest extends TestCase
{
    private string $tempRoot;
    private string $repoRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tmc-reject-producer-' . bin2hex(random_bytes(6));
        $this->repoRoot = $this->tempRoot . DIRECTORY_SEPARATOR . 'repo';
        $this->ensureDir($this->repoRoot);
    }

    protected function tearDown(): void
    {
        $this->removeDirRecursive($this->tempRoot);
        parent::tearDown();
    }

    public function testGeneratesCanonicalManifestAndNormalizedInputsFromLegacySources(): void
    {
        $this->writeDefaultSourceFixtures();

        $result = AgenticRejectedIterationInputProducer::generate([
            'repo_root' => $this->repoRoot,
            'generated_at_utc' => '2026-04-16T00:00:00Z',
            'source_commit' => str_repeat('a', 40),
        ]);

        $this->assertTrue($result['ok']);
        $this->assertFileExists((string)$result['manifest_path']);
        $this->assertFileExists((string)$result['primary_canonical_path']);
        $this->assertFileExists((string)$result['secondary_canonical_path']);

        $manifest = json_decode((string)file_get_contents((string)$result['manifest_path']), true);
        $this->assertIsArray($manifest);
        $this->assertSame('tmc-reject-audit-inputs.v1', $manifest['schema_version']);
        $this->assertSame('2026-04-16T00:00:00Z', $manifest['generated_at_utc']);
        $this->assertSame(str_repeat('a', 40), $manifest['source_commit']);
        $this->assertSame('scripts/prepare_rejected_iteration_inputs.php', $manifest['producer']['name']);
        $this->assertSame('v1', $manifest['producer']['version']);
        $this->assertSame('reject_events_primary.json', $manifest['sources']['primary']['path']);
        $this->assertSame('reject_events_secondary.json', $manifest['sources']['secondary']['path']);
        $this->assertSame(
            'simulation_output/current-db/comparisons-v3-fast/comparison_tuning-verify-v3-fast-1.json',
            $manifest['sources']['primary']['provenance']['origin_path']
        );
        $this->assertSame(
            'simulation_output/current-db/verification-v2/verification_summary_v2.json',
            $manifest['sources']['secondary']['provenance']['origin_path']
        );
        $this->assertSame(1, $manifest['sources']['primary']['event_count']);
        $this->assertSame(1, $manifest['sources']['secondary']['event_count']);
    }

    public function testChecksumsAreAccurateAndVerifyPassesForFreshCanonicalInputs(): void
    {
        $this->writeDefaultSourceFixtures();
        $generatedAt = gmdate('c');
        $result = AgenticRejectedIterationInputProducer::generate([
            'repo_root' => $this->repoRoot,
            'generated_at_utc' => $generatedAt,
            'source_commit' => str_repeat('b', 40),
        ]);

        $manifest = json_decode((string)file_get_contents((string)$result['manifest_path']), true);
        $primaryCanonical = (string)$result['primary_canonical_path'];
        $secondaryCanonical = (string)$result['secondary_canonical_path'];
        $this->assertSame(hash_file('sha256', $primaryCanonical), $manifest['sources']['primary']['checksum_sha256']);
        $this->assertSame(hash_file('sha256', $secondaryCanonical), $manifest['sources']['secondary']['checksum_sha256']);

        $verify = AgenticRejectedIterationInputProducer::verify([
            'repo_root' => $this->repoRoot,
            'strict_integrity' => true,
            'max_age_seconds' => 3600,
        ]);
        $this->assertTrue($verify['ok']);
        $resolverResult = (array)$verify['resolver_result'];
        $this->assertSame('fresh', $resolverResult['integrity']['freshness']['status']);
    }

    public function testDeterministicOutputForFixedInputsAndMetadata(): void
    {
        $this->writeDefaultSourceFixtures();
        $options = [
            'repo_root' => $this->repoRoot,
            'generated_at_utc' => '2026-04-16T00:00:00Z',
            'source_commit' => str_repeat('c', 40),
        ];

        $first = AgenticRejectedIterationInputProducer::generate($options);
        $manifestOne = (string)file_get_contents((string)$first['manifest_path']);
        $primaryOne = (string)file_get_contents((string)$first['primary_canonical_path']);
        $secondaryOne = (string)file_get_contents((string)$first['secondary_canonical_path']);

        $second = AgenticRejectedIterationInputProducer::generate($options);
        $manifestTwo = (string)file_get_contents((string)$second['manifest_path']);
        $primaryTwo = (string)file_get_contents((string)$second['primary_canonical_path']);
        $secondaryTwo = (string)file_get_contents((string)$second['secondary_canonical_path']);

        $this->assertSame($manifestOne, $manifestTwo);
        $this->assertSame($primaryOne, $primaryTwo);
        $this->assertSame($secondaryOne, $secondaryTwo);
    }

    public function testVerifyFailsOnStaleManifestInStrictMode(): void
    {
        $this->writeDefaultSourceFixtures();
        AgenticRejectedIterationInputProducer::generate([
            'repo_root' => $this->repoRoot,
            'generated_at_utc' => '2020-01-01T00:00:00Z',
            'source_commit' => str_repeat('d', 40),
        ]);

        $verify = AgenticRejectedIterationInputProducer::verify([
            'repo_root' => $this->repoRoot,
            'strict_integrity' => true,
            'max_age_seconds' => 300,
            'freshness_now_utc' => '2026-04-16T00:00:00Z',
        ]);

        $this->assertFalse($verify['ok']);
        $resolverResult = (array)$verify['resolver_result'];
        $this->assertSame('stale', $resolverResult['integrity']['freshness']['status']);
    }

    private function writeDefaultSourceFixtures(): void
    {
        $primaryPath = $this->repoRoot . DIRECTORY_SEPARATOR . 'simulation_output' . DIRECTORY_SEPARATOR
            . 'current-db' . DIRECTORY_SEPARATOR . 'comparisons-v3-fast' . DIRECTORY_SEPARATOR
            . 'comparison_tuning-verify-v3-fast-1.json';
        $secondaryPath = $this->repoRoot . DIRECTORY_SEPARATOR . 'simulation_output' . DIRECTORY_SEPARATOR
            . 'current-db' . DIRECTORY_SEPARATOR . 'verification-v2' . DIRECTORY_SEPARATOR
            . 'verification_summary_v2.json';

        $this->writeJson($primaryPath, [
            'seed' => 'seed-a',
            'scenarios' => [
                [
                    'scenario_name' => 'tuning-a',
                    'recommended_disposition' => 'reject',
                    'wins' => 1,
                    'losses' => 2,
                    'regression_flags' => ['dominant_archetype_shifted'],
                    'confidence_notes' => 'Paired samples: 2',
                ],
            ],
        ]);
        $this->writeJson($secondaryPath, [
            'packages' => [
                [
                    'package_name' => 'balanced',
                    'per_seed' => [
                        [
                            'seed' => 'seed-1',
                            'disposition' => 'reject',
                            'wins' => 1,
                            'losses' => 2,
                            'regression_flags' => ['lock_in_down_but_expiry_dominance_up'],
                            'confidence_notes' => 'Paired samples: 2',
                        ],
                    ],
                ],
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeJson(string $path, array $payload): void
    {
        $this->ensureDir(dirname($path));
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }

    private function removeDirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDirRecursive($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
