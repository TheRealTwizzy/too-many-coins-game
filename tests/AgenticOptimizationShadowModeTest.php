<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/optimization/AgenticOptimization.php';
require_once __DIR__ . '/../scripts/optimization/RejectedIterationInputResolver.php';

class AgenticOptimizationShadowModeTest extends TestCase
{
    private string $tempRoot;
    private string $repoRoot;
    private string $auditDir;
    private string $manifestRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tmc-shadow-mode-' . bin2hex(random_bytes(6));
        $this->repoRoot = $this->tempRoot . DIRECTORY_SEPARATOR . 'repo';
        $this->auditDir = $this->tempRoot . DIRECTORY_SEPARATOR . 'audit';
        $this->manifestRoot = $this->tempRoot . DIRECTORY_SEPARATOR . 'manifest';

        $this->ensureDir($this->repoRoot);
        $this->ensureDir($this->auditDir);
        $this->ensureDir($this->manifestRoot);
    }

    protected function tearDown(): void
    {
        $this->removeDirRecursive($this->tempRoot);
        parent::tearDown();
    }

    public function testRejectAuditModeDefaultsToLegacyWhenFlagMissing(): void
    {
        $this->assertSame('legacy', AgenticOptimizationCoordinator::resolveRejectAuditMode([]));
        $this->assertSame('legacy', AgenticOptimizationCoordinator::resolveRejectAuditMode(['reject_audit_mode' => '']));
    }

    public function testRejectAuditModeEnablesShadowOnlyWhenExplicit(): void
    {
        $this->assertSame('shadow-manifest', AgenticOptimizationCoordinator::resolveRejectAuditMode([
            'reject_audit_mode' => 'shadow-manifest',
        ]));
        $this->assertSame('legacy', AgenticOptimizationCoordinator::resolveRejectAuditMode([
            'reject_audit_mode' => 'manifest-strict',
        ]));
    }

    public function testManifestMissingStatusIsReported(): void
    {
        $legacyAudit = $this->buildLegacyAuditFromRepoInputs();
        $diagnostic = AgenticRejectedIterationShadowParity::run(
            $this->repoRoot,
            $this->auditDir,
            $legacyAudit,
            $this->manifestRoot . DIRECTORY_SEPARATOR . 'missing.json'
        );

        $this->assertSame('manifest_missing', $diagnostic['shadow_status']);
        $this->assertTrue($diagnostic['fallback_occurred']);
        $this->assertSame('not_computed', $diagnostic['parity_result']);
        $this->assertFileExists($this->auditDir . DIRECTORY_SEPARATOR . 'rejected_iteration_shadow_parity.json');
    }

    public function testManifestInvalidStatusIsReported(): void
    {
        $legacyAudit = $this->buildLegacyAuditFromRepoInputs();
        $manifestPath = $this->manifestRoot . DIRECTORY_SEPARATOR . 'manifest.invalid.json';
        file_put_contents($manifestPath, '{"schema_version":"x"}');

        $diagnostic = AgenticRejectedIterationShadowParity::run($this->repoRoot, $this->auditDir, $legacyAudit, $manifestPath);

        $this->assertSame('manifest_invalid', $diagnostic['shadow_status']);
        $this->assertTrue($diagnostic['fallback_occurred']);
        $this->assertSame('not_computed', $diagnostic['parity_result']);
    }

    public function testPrimaryMissingStatusIsReported(): void
    {
        $legacyAudit = $this->buildLegacyAuditFromRepoInputs();
        $this->writeJson($this->manifestRoot . DIRECTORY_SEPARATOR . 'reject_events_secondary.json', $this->secondaryPayload());
        $manifestPath = $this->manifestRoot . DIRECTORY_SEPARATOR . 'manifest.json';
        $this->writeJson($manifestPath, [
            'schema_version' => 'tmc-reject-audit-inputs.v1',
            'generated_at_utc' => '2026-04-16T00:00:00Z',
            'sources' => [
                'primary' => ['path' => 'missing_primary.json'],
                'secondary' => ['path' => 'reject_events_secondary.json'],
            ],
        ]);

        $diagnostic = AgenticRejectedIterationShadowParity::run($this->repoRoot, $this->auditDir, $legacyAudit, $manifestPath);

        $this->assertSame('primary_missing', $diagnostic['shadow_status']);
        $this->assertContains('primary', (array)$diagnostic['missing_sources']);
    }

    public function testSecondaryMissingStatusIsReported(): void
    {
        $legacyAudit = $this->buildLegacyAuditFromRepoInputs();
        $this->writeJson($this->manifestRoot . DIRECTORY_SEPARATOR . 'reject_events_primary.json', $this->primaryPayload());
        $manifestPath = $this->manifestRoot . DIRECTORY_SEPARATOR . 'manifest.json';
        $this->writeJson($manifestPath, [
            'schema_version' => 'tmc-reject-audit-inputs.v1',
            'generated_at_utc' => '2026-04-16T00:00:00Z',
            'sources' => [
                'primary' => ['path' => 'reject_events_primary.json'],
                'secondary' => ['path' => 'missing_secondary.json'],
            ],
        ]);

        $diagnostic = AgenticRejectedIterationShadowParity::run($this->repoRoot, $this->auditDir, $legacyAudit, $manifestPath);

        $this->assertSame('secondary_missing', $diagnostic['shadow_status']);
        $this->assertContains('secondary', (array)$diagnostic['missing_sources']);
    }

    public function testParitySuccessPathInShadowMode(): void
    {
        $legacyAudit = $this->buildLegacyAuditFromRepoInputs();
        $this->writeManifestAndSourceFiles($this->primaryPayload(), $this->secondaryPayload());

        $diagnostic = AgenticRejectedIterationShadowParity::run(
            $this->repoRoot,
            $this->auditDir,
            $legacyAudit,
            $this->manifestRoot . DIRECTORY_SEPARATOR . 'manifest.json'
        );

        $this->assertSame('parity_pass', $diagnostic['shadow_status']);
        $this->assertSame('pass', $diagnostic['parity_result']);
        $this->assertTrue($diagnostic['parity_pass']);
        $this->assertSame([], $diagnostic['mismatches']);
    }

    public function testParityMismatchSurfacingInShadowMode(): void
    {
        $legacyAudit = $this->buildLegacyAuditFromRepoInputs();
        $modifiedPrimary = $this->primaryPayload();
        $modifiedPrimary['scenarios'][0]['wins'] = 99;
        $this->writeManifestAndSourceFiles($modifiedPrimary, $this->secondaryPayload());

        $diagnostic = AgenticRejectedIterationShadowParity::run(
            $this->repoRoot,
            $this->auditDir,
            $legacyAudit,
            $this->manifestRoot . DIRECTORY_SEPARATOR . 'manifest.json'
        );

        $this->assertSame('parity_mismatch', $diagnostic['shadow_status']);
        $this->assertSame('fail', $diagnostic['parity_result']);
        $this->assertFalse($diagnostic['parity_pass']);
        $this->assertNotEmpty($diagnostic['mismatches']);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildLegacyAuditFromRepoInputs(): array
    {
        $legacyV2Path = $this->repoRoot . DIRECTORY_SEPARATOR . 'simulation_output'
            . DIRECTORY_SEPARATOR . 'current-db' . DIRECTORY_SEPARATOR . 'verification-v2';
        $legacyV3Path = $this->repoRoot . DIRECTORY_SEPARATOR . 'simulation_output'
            . DIRECTORY_SEPARATOR . 'current-db' . DIRECTORY_SEPARATOR . 'comparisons-v3-fast';
        $this->ensureDir($legacyV2Path);
        $this->ensureDir($legacyV3Path);

        $this->writeJson($legacyV2Path . DIRECTORY_SEPARATOR . 'verification_summary_v2.json', $this->secondaryPayload());
        $this->writeJson($legacyV3Path . DIRECTORY_SEPARATOR . 'comparison_tuning-verify-v3-fast-1.json', $this->primaryPayload());

        return AgenticRejectedIterationAuditor::run($this->repoRoot, $this->auditDir . DIRECTORY_SEPARATOR . 'legacy');
    }

    /**
     * @param array<string, mixed> $primary
     * @param array<string, mixed> $secondary
     */
    private function writeManifestAndSourceFiles(array $primary, array $secondary): void
    {
        $this->writeJson($this->manifestRoot . DIRECTORY_SEPARATOR . 'reject_events_primary.json', $primary);
        $this->writeJson($this->manifestRoot . DIRECTORY_SEPARATOR . 'reject_events_secondary.json', $secondary);
        $this->writeJson($this->manifestRoot . DIRECTORY_SEPARATOR . 'manifest.json', [
            'schema_version' => 'tmc-reject-audit-inputs.v1',
            'generated_at_utc' => '2026-04-16T00:00:00Z',
            'sources' => [
                'primary' => ['path' => 'reject_events_primary.json'],
                'secondary' => ['path' => 'reject_events_secondary.json'],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function primaryPayload(): array
    {
        return [
            'seed' => 'shadow-seed-1',
            'scenarios' => [
                [
                    'scenario_name' => 'shadow-scenario-a',
                    'wins' => 1,
                    'losses' => 2,
                    'regression_flags' => ['lock_in_down_but_expiry_dominance_up'],
                    'recommended_disposition' => 'reject',
                    'confidence_notes' => 'Paired samples: 2 group comparisons across 2 simulator group(s).',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function secondaryPayload(): array
    {
        return [
            'packages' => [
                [
                    'package_name' => 'shadow-balanced',
                    'per_seed' => [
                        [
                            'seed' => 'shadow-v2-seed-1',
                            'wins' => 1,
                            'losses' => 2,
                            'regression_flags' => ['dominant_archetype_shifted'],
                            'disposition' => 'reject',
                            'confidence_notes' => 'Paired samples: 2 group comparisons across 2 simulator group(s).',
                        ],
                    ],
                ],
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
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
