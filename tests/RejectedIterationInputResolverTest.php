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
