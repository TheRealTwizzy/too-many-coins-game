<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/optimization/RejectedIterationInputResolver.php';

class RejectedIterationInputResolverTest extends TestCase
{
    private string $tempDir;
    private string $canonicalRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tmc-reject-resolver-' . bin2hex(random_bytes(6));
        $this->canonicalRoot = $this->tempDir . DIRECTORY_SEPARATOR . 'current';
        $this->ensureDir($this->canonicalRoot);
    }

    protected function tearDown(): void
    {
        $this->removeDirRecursive($this->tempDir);
        parent::tearDown();
    }

    public function testValidManifestResolvesPrimaryAndSecondary(): void
    {
        $this->writeJson($this->canonicalRoot . DIRECTORY_SEPARATOR . 'reject_events_primary.json', [['event_id' => 'p1']]);
        $this->writeJson($this->canonicalRoot . DIRECTORY_SEPARATOR . 'reject_events_secondary.json', [['event_id' => 's1']]);

        $manifestPath = $this->canonicalRoot . DIRECTORY_SEPARATOR . 'manifest.json';
        $this->writeJson($manifestPath, $this->validManifest());

        $result = AgenticRejectAuditManifestResolver::resolve($manifestPath);

        $this->assertTrue($result['manifest_valid']);
        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['errors']);
        $this->assertSame([], $result['missing_sources']);
        $this->assertSame('present', $result['resolved_sources']['primary']['status']);
        $this->assertSame('present', $result['resolved_sources']['secondary']['status']);
    }

    public function testInvalidSchemaMissingRequiredTopLevelFieldsIsRejected(): void
    {
        $manifestPath = $this->canonicalRoot . DIRECTORY_SEPARATOR . 'manifest.json';
        $this->writeJson($manifestPath, [
            'schema_version' => 'tmc-reject-audit-inputs.v1',
        ]);

        $result = AgenticRejectAuditManifestResolver::resolve($manifestPath);

        $this->assertFalse($result['manifest_valid']);
        $this->assertFalse($result['ok']);
        $this->assertErrorCodePresent($result['errors'], 'required_key_missing');
    }

    public function testUnknownTopLevelFieldIsRejectedInStrictValidation(): void
    {
        $manifest = $this->validManifest();
        $manifest['unexpected_key_name'] = true;
        $manifestPath = $this->canonicalRoot . DIRECTORY_SEPARATOR . 'manifest.json';
        $this->writeJson($manifestPath, $manifest);

        $result = AgenticRejectAuditManifestResolver::resolve($manifestPath, null, true);

        $this->assertFalse($result['manifest_valid']);
        $this->assertFalse($result['ok']);
        $this->assertErrorCodePresent($result['errors'], 'unknown_key');
    }

    public function testMissingRequiredSourcePathIsRejected(): void
    {
        $manifest = $this->validManifest();
        $manifest['sources']['secondary'] = [];
        $manifestPath = $this->canonicalRoot . DIRECTORY_SEPARATOR . 'manifest.json';
        $this->writeJson($manifestPath, $manifest);

        $result = AgenticRejectAuditManifestResolver::resolve($manifestPath);

        $this->assertFalse($result['manifest_valid']);
        $this->assertFalse($result['ok']);
        $this->assertErrorCodePresent($result['errors'], 'required_key_missing');
    }

    public function testAbsolutePathIsRejected(): void
    {
        $this->writeJson($this->canonicalRoot . DIRECTORY_SEPARATOR . 'reject_events_secondary.json', [['event_id' => 's1']]);

        $manifest = $this->validManifest();
        $manifest['sources']['primary']['path'] = 'C:\\outside\\reject_events_primary.json';
        $manifestPath = $this->canonicalRoot . DIRECTORY_SEPARATOR . 'manifest.json';
        $this->writeJson($manifestPath, $manifest);

        $result = AgenticRejectAuditManifestResolver::resolve($manifestPath);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_path', $result['resolved_sources']['primary']['status']);
        $this->assertSame('absolute_path_not_allowed', $result['resolved_sources']['primary']['error_code']);
    }

    public function testTraversalPathIsRejected(): void
    {
        $this->writeJson($this->canonicalRoot . DIRECTORY_SEPARATOR . 'reject_events_secondary.json', [['event_id' => 's1']]);

        $manifest = $this->validManifest();
        $manifest['sources']['primary']['path'] = '../outside/reject_events_primary.json';
        $manifestPath = $this->canonicalRoot . DIRECTORY_SEPARATOR . 'manifest.json';
        $this->writeJson($manifestPath, $manifest);

        $result = AgenticRejectAuditManifestResolver::resolve($manifestPath);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_path', $result['resolved_sources']['primary']['status']);
        $this->assertSame('traversal_segment_not_allowed', $result['resolved_sources']['primary']['error_code']);
    }

    public function testOutsideRootResolvedPathIsRejected(): void
    {
        $outsideDir = $this->tempDir . DIRECTORY_SEPARATOR . 'outside';
        $this->ensureDir($outsideDir);
        $outsideFile = $outsideDir . DIRECTORY_SEPARATOR . 'foreign.json';
        $this->writeJson($outsideFile, ['x' => 1]);

        $containment = AgenticRejectAuditPathGuard::ensureContainedResolvedPath($this->canonicalRoot, $outsideFile);

        $this->assertFalse($containment['ok']);
        $this->assertSame('outside_root', $containment['code']);
    }

    public function testMissingArtifactIsReportedExplicitly(): void
    {
        $this->writeJson($this->canonicalRoot . DIRECTORY_SEPARATOR . 'reject_events_secondary.json', [['event_id' => 's1']]);

        $manifest = $this->validManifest();
        $manifest['sources']['primary']['path'] = 'missing_primary.json';
        $manifestPath = $this->canonicalRoot . DIRECTORY_SEPARATOR . 'manifest.json';
        $this->writeJson($manifestPath, $manifest);

        $result = AgenticRejectAuditManifestResolver::resolve($manifestPath);

        $this->assertTrue($result['manifest_valid']);
        $this->assertTrue($result['ok'], 'Missing artifacts are reported explicitly but do not fail resolver');
        $this->assertSame(['primary'], $result['missing_sources']);
        $this->assertSame('artifact_missing', $result['resolved_sources']['primary']['status']);
        $this->assertErrorCodePresent($result['warnings'], 'artifact_missing');
    }

    public function testCanonicalContractValidationAcceptsValidProvenanceChecksumsAndFreshness(): void
    {
        $primaryPath = $this->canonicalRoot . DIRECTORY_SEPARATOR . 'reject_events_primary.json';
        $secondaryPath = $this->canonicalRoot . DIRECTORY_SEPARATOR . 'reject_events_secondary.json';
        $this->writeJson($primaryPath, ['scenarios' => []]);
        $this->writeJson($secondaryPath, ['packages' => []]);

        $manifest = [
            'schema_version' => 'tmc-reject-audit-inputs.v1',
            'generated_at_utc' => '2026-04-16T00:00:00Z',
            'source_commit' => str_repeat('a', 40),
            'producer' => ['name' => 'producer', 'version' => 'v1'],
            'sources' => [
                'primary' => [
                    'path' => 'reject_events_primary.json',
                    'checksum_sha256' => hash_file('sha256', $primaryPath),
                    'event_count' => 0,
                    'provenance' => [
                        'origin_path' => 'origin_primary.json',
                        'origin_checksum_sha256' => hash_file('sha256', $primaryPath),
                    ],
                ],
                'secondary' => [
                    'path' => 'reject_events_secondary.json',
                    'checksum_sha256' => hash_file('sha256', $secondaryPath),
                    'event_count' => 0,
                    'provenance' => [
                        'origin_path' => 'origin_secondary.json',
                        'origin_checksum_sha256' => hash_file('sha256', $secondaryPath),
                    ],
                ],
            ],
            'policy' => ['mode' => 'shadow-manifest'],
        ];

        // Copy canonical files to provenance paths for deterministic checksum match in test.
        copy($primaryPath, $this->canonicalRoot . DIRECTORY_SEPARATOR . 'origin_primary.json');
        copy($secondaryPath, $this->canonicalRoot . DIRECTORY_SEPARATOR . 'origin_secondary.json');

        $manifestPath = $this->canonicalRoot . DIRECTORY_SEPARATOR . 'manifest.json';
        $this->writeJson($manifestPath, $manifest);

        $result = AgenticRejectAuditManifestResolver::resolve(
            $manifestPath,
            $this->canonicalRoot,
            true,
            [
                'require_canonical_contract' => true,
                'validate_provenance' => true,
                'validate_checksums' => true,
                'validate_freshness' => true,
                'repo_root' => $this->canonicalRoot,
                'max_age_seconds' => 86400,
                'freshness_now_utc' => '2026-04-16T00:00:00Z',
                'strict_integrity' => true,
            ]
        );

        $this->assertTrue($result['ok']);
        $this->assertSame('fresh', $result['integrity']['freshness']['status']);
        $this->assertSame('ok', $result['integrity']['checksums']['primary']['status']);
        $this->assertSame('ok', $result['integrity']['checksums']['secondary']['status']);
        $this->assertSame('ok', $result['integrity']['provenance']['primary']['status']);
        $this->assertSame('ok', $result['integrity']['provenance']['secondary']['status']);
    }

    public function testCanonicalContractValidationFlagsStaleAndChecksumMismatchInStrictMode(): void
    {
        $primaryPath = $this->canonicalRoot . DIRECTORY_SEPARATOR . 'reject_events_primary.json';
        $secondaryPath = $this->canonicalRoot . DIRECTORY_SEPARATOR . 'reject_events_secondary.json';
        $this->writeJson($primaryPath, ['scenarios' => [['recommended_disposition' => 'reject']]]);
        $this->writeJson($secondaryPath, ['packages' => []]);

        $manifest = [
            'schema_version' => 'tmc-reject-audit-inputs.v1',
            'generated_at_utc' => '2020-01-01T00:00:00Z',
            'source_commit' => str_repeat('b', 40),
            'producer' => ['name' => 'producer', 'version' => 'v1'],
            'sources' => [
                'primary' => [
                    'path' => 'reject_events_primary.json',
                    'checksum_sha256' => str_repeat('0', 64),
                    'event_count' => 1,
                    'provenance' => [
                        'origin_path' => 'origin_primary.json',
                        'origin_checksum_sha256' => str_repeat('0', 64),
                    ],
                ],
                'secondary' => [
                    'path' => 'reject_events_secondary.json',
                    'checksum_sha256' => hash_file('sha256', $secondaryPath),
                    'event_count' => 0,
                    'provenance' => [
                        'origin_path' => 'origin_secondary.json',
                        'origin_checksum_sha256' => hash_file('sha256', $secondaryPath),
                    ],
                ],
            ],
            'policy' => ['mode' => 'shadow-manifest'],
        ];
        copy($primaryPath, $this->canonicalRoot . DIRECTORY_SEPARATOR . 'origin_primary.json');
        copy($secondaryPath, $this->canonicalRoot . DIRECTORY_SEPARATOR . 'origin_secondary.json');
        $manifestPath = $this->canonicalRoot . DIRECTORY_SEPARATOR . 'manifest.json';
        $this->writeJson($manifestPath, $manifest);

        $result = AgenticRejectAuditManifestResolver::resolve(
            $manifestPath,
            $this->canonicalRoot,
            true,
            [
                'require_canonical_contract' => true,
                'validate_provenance' => true,
                'validate_checksums' => true,
                'validate_freshness' => true,
                'repo_root' => $this->canonicalRoot,
                'max_age_seconds' => 300,
                'freshness_now_utc' => '2026-04-16T00:00:00Z',
                'strict_integrity' => true,
            ]
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('stale', $result['integrity']['freshness']['status']);
        $this->assertSame('checksum_mismatch', $result['integrity']['checksums']['primary']['status']);
        $this->assertSame('origin_checksum_mismatch', $result['integrity']['provenance']['primary']['status']);
        $this->assertErrorCodePresent($result['errors'], 'manifest_stale');
        $this->assertErrorCodePresent($result['errors'], 'checksum_mismatch');
        $this->assertErrorCodePresent($result['errors'], 'provenance_origin_checksum_mismatch');
    }

    /**
     * @return array<string, mixed>
     */
    private function validManifest(): array
    {
        return [
            'schema_version' => 'tmc-reject-audit-inputs.v1',
            'generated_at_utc' => '2026-04-16T00:00:00Z',
            'sources' => [
                'primary' => ['path' => 'reject_events_primary.json'],
                'secondary' => ['path' => 'reject_events_secondary.json'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeJson(string $path, array $payload): void
    {
        $this->ensureDir(dirname($path));
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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
                continue;
            }

            @unlink($path);
        }

        @rmdir($dir);
    }

    /**
     * @param array<int, array<string, string>> $errors
     */
    private function assertErrorCodePresent(array $errors, string $expectedCode): void
    {
        $codes = array_map(static function ($e) {
            return (string)($e['code'] ?? '');
        }, $errors);
        $this->assertContains($expectedCode, $codes, 'Expected error code not found: ' . $expectedCode);
    }
}
