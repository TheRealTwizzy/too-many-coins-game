<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/optimization/AgenticOptimization.php';

class AgenticOptimizationManifestPreferredModeTest extends TestCase
{
    private string $tempRoot;
    private string $repoRoot;
    private string $auditDir;
    private string $manifestRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tmc-manifest-preferred-' . bin2hex(random_bytes(6));
        $this->repoRoot = $this->tempRoot . DIRECTORY_SEPARATOR . 'repo';
        $this->auditDir = $this->tempRoot . DIRECTORY_SEPARATOR . 'audit';
        $this->manifestRoot = $this->tempRoot . DIRECTORY_SEPARATOR . 'override';
        $this->ensureDir($this->repoRoot);
        $this->ensureDir($this->auditDir);
        $this->ensureDir($this->manifestRoot);
    }

    protected function tearDown(): void
    {
        $this->removeDirRecursive($this->tempRoot);
        parent::tearDown();
    }

    public function testRejectAuditModeDefaultsToLegacyAndSupportsManifestPreferred(): void
    {
        $this->assertSame('legacy', AgenticOptimizationCoordinator::resolveRejectAuditMode([]));
        $this->assertSame('legacy', AgenticOptimizationCoordinator::resolveRejectAuditMode(['reject_audit_mode' => '']));
        $this->assertSame(
            'manifest_preferred',
            AgenticOptimizationCoordinator::resolveRejectAuditMode(['reject_audit_mode' => 'manifest_preferred'])
        );
    }

    public function testManifestPreferredSuccessUsesManifestAsAuthority(): void
    {
        $legacyAudit = $this->buildLegacyAuditFromRepoInputs();
        $this->writeCanonicalManifestAndSourceFiles($this->primaryPayload(), $this->secondaryPayload());

        $result = AgenticRejectedIterationShadowParity::runManifestPreferred($this->repoRoot, $this->auditDir, $legacyAudit, null);
        $diagnostic = (array)$result['diagnostic'];

        $this->assertSame('manifest', $result['authoritative_source']);
        $this->assertSame('manifest', $diagnostic['authoritative_source']);
        $this->assertFalse($diagnostic['fallback_occurred']);
        $this->assertNull($diagnostic['fallback_reason']);
        $this->assertSame('pass', $diagnostic['parity_result']);
        $this->assertTrue($diagnostic['parity_pass']);
        $this->assertSame('canonical_default', $diagnostic['manifest_source']);
        $this->assertSame($this->canonicalManifestPath(), $diagnostic['selected_manifest_path']);
        $this->assertFileExists($this->manifestPreferredDiagnosticPath());
        $this->assertRequiredDiagnosticFields($diagnostic);
    }

    public function testManifestPreferredOverrideWinsWhenProvided(): void
    {
        $legacyAudit = $this->buildLegacyAuditFromRepoInputs();

        $mismatchPrimary = $this->primaryPayload();
        $mismatchPrimary['scenarios'][0]['wins'] = 99;
        $this->writeCanonicalManifestAndSourceFiles($mismatchPrimary, $this->secondaryPayload());
        $overrideManifestPath = $this->writeOverrideManifestAndSourceFiles($this->primaryPayload(), $this->secondaryPayload());

        $result = AgenticRejectedIterationShadowParity::runManifestPreferred(
            $this->repoRoot,
            $this->auditDir,
            $legacyAudit,
            $overrideManifestPath
        );
        $diagnostic = (array)$result['diagnostic'];

        $this->assertSame('manifest', $result['authoritative_source']);
        $this->assertSame('override', $diagnostic['manifest_source']);
        $this->assertSame($overrideManifestPath, $diagnostic['requested_manifest_path']);
        $this->assertSame($overrideManifestPath, $diagnostic['selected_manifest_path']);
        $this->assertSame('pass', $diagnostic['parity_result']);
    }

    public function testManifestMissingFallsBackToLegacy(): void
    {
        $legacyAudit = $this->buildLegacyAuditFromRepoInputs();

        $result = AgenticRejectedIterationShadowParity::runManifestPreferred($this->repoRoot, $this->auditDir, $legacyAudit, null);
        $diagnostic = (array)$result['diagnostic'];

        $this->assertSame('legacy_fallback', $result['authoritative_source']);
        $this->assertTrue($diagnostic['fallback_occurred']);
        $this->assertSame('canonical_manifest_missing', $diagnostic['fallback_reason']);
        $this->assertSame('not_computed', $diagnostic['parity_result']);
    }

    public function testManifestInvalidFallsBackToLegacy(): void
    {
        $legacyAudit = $this->buildLegacyAuditFromRepoInputs();
        $this->ensureDir($this->canonicalRoot());
        file_put_contents($this->canonicalManifestPath(), '{"schema_version":"bad"}');

        $result = AgenticRejectedIterationShadowParity::runManifestPreferred($this->repoRoot, $this->auditDir, $legacyAudit, null);
        $diagnostic = (array)$result['diagnostic'];

        $this->assertSame('legacy_fallback', $result['authoritative_source']);
        $this->assertTrue($diagnostic['fallback_occurred']);
        $this->assertSame('canonical_manifest_invalid', $diagnostic['fallback_reason']);
        $this->assertSame('not_computed', $diagnostic['parity_result']);
    }

    public function testCanonicalStaleFallsBackToLegacy(): void
    {
        $legacyAudit = $this->buildLegacyAuditFromRepoInputs();
        $this->writeCanonicalManifestAndSourceFiles(
            $this->primaryPayload(),
            $this->secondaryPayload(),
            '2020-01-01T00:00:00Z'
        );

        $result = AgenticRejectedIterationShadowParity::runManifestPreferred($this->repoRoot, $this->auditDir, $legacyAudit, null);
        $diagnostic = (array)$result['diagnostic'];

        $this->assertSame('legacy_fallback', $result['authoritative_source']);
        $this->assertSame('canonical_manifest_stale', $diagnostic['fallback_reason']);
        $this->assertSame('stale', $diagnostic['freshness_status']);
        $this->assertNotEmpty((array)$diagnostic['freshness_reasons']);
    }

    public function testPrimaryMissingFallsBackToLegacy(): void
    {
        $legacyAudit = $this->buildLegacyAuditFromRepoInputs();
        $this->writeJson($this->manifestRoot . DIRECTORY_SEPARATOR . 'reject_events_secondary.json', $this->secondaryPayload());
        $manifestPath = $this->manifestRoot . DIRECTORY_SEPARATOR . 'manifest.json';
        $this->writeJson($manifestPath, [
            'schema_version' => 'tmc-reject-audit-inputs.v1',
            'generated_at_utc' => '2026-04-17T00:00:00Z',
            'sources' => [
                'primary' => ['path' => 'missing_primary.json'],
                'secondary' => ['path' => 'reject_events_secondary.json'],
            ],
        ]);

        $result = AgenticRejectedIterationShadowParity::runManifestPreferred($this->repoRoot, $this->auditDir, $legacyAudit, $manifestPath);
        $diagnostic = (array)$result['diagnostic'];

        $this->assertSame('legacy_fallback', $result['authoritative_source']);
        $this->assertSame('primary_missing', $diagnostic['fallback_reason']);
    }

    public function testSecondaryMissingFallsBackToLegacy(): void
    {
        $legacyAudit = $this->buildLegacyAuditFromRepoInputs();
        $this->writeJson($this->manifestRoot . DIRECTORY_SEPARATOR . 'reject_events_primary.json', $this->primaryPayload());
        $manifestPath = $this->manifestRoot . DIRECTORY_SEPARATOR . 'manifest.json';
        $this->writeJson($manifestPath, [
            'schema_version' => 'tmc-reject-audit-inputs.v1',
            'generated_at_utc' => '2026-04-17T00:00:00Z',
            'sources' => [
                'primary' => ['path' => 'reject_events_primary.json'],
                'secondary' => ['path' => 'missing_secondary.json'],
            ],
        ]);

        $result = AgenticRejectedIterationShadowParity::runManifestPreferred($this->repoRoot, $this->auditDir, $legacyAudit, $manifestPath);
        $diagnostic = (array)$result['diagnostic'];

        $this->assertSame('legacy_fallback', $result['authoritative_source']);
        $this->assertSame('secondary_missing', $diagnostic['fallback_reason']);
    }

    public function testManifestBuildFailureFallsBackToLegacy(): void
    {
        $legacyAudit = $this->buildLegacyAuditFromRepoInputs();
        $this->ensureDir($this->manifestRoot);
        $this->writeJson($this->manifestRoot . DIRECTORY_SEPARATOR . 'reject_events_secondary.json', $this->secondaryPayload());
        file_put_contents($this->manifestRoot . DIRECTORY_SEPARATOR . 'reject_events_primary.json', '{invalid json}');
        $manifestPath = $this->manifestRoot . DIRECTORY_SEPARATOR . 'manifest.json';
        $this->writeJson($manifestPath, [
            'schema_version' => 'tmc-reject-audit-inputs.v1',
            'generated_at_utc' => '2026-04-17T00:00:00Z',
            'sources' => [
                'primary' => ['path' => 'reject_events_primary.json'],
                'secondary' => ['path' => 'reject_events_secondary.json'],
            ],
        ]);

        $result = AgenticRejectedIterationShadowParity::runManifestPreferred($this->repoRoot, $this->auditDir, $legacyAudit, $manifestPath);
        $diagnostic = (array)$result['diagnostic'];

        $this->assertSame('legacy_fallback', $result['authoritative_source']);
        $this->assertSame('manifest_build_failed', $diagnostic['fallback_reason']);
    }

    public function testParityMismatchFallsBackToLegacyWithMismatches(): void
    {
        $legacyAudit = $this->buildLegacyAuditFromRepoInputs();
        $mismatchPrimary = $this->primaryPayload();
        $mismatchPrimary['scenarios'][0]['wins'] = 99;
        $this->writeCanonicalManifestAndSourceFiles($mismatchPrimary, $this->secondaryPayload());

        $result = AgenticRejectedIterationShadowParity::runManifestPreferred($this->repoRoot, $this->auditDir, $legacyAudit, null);
        $diagnostic = (array)$result['diagnostic'];

        $this->assertSame('legacy_fallback', $result['authoritative_source']);
        $this->assertSame('parity_mismatch', $diagnostic['fallback_reason']);
        $this->assertSame('fail', $diagnostic['parity_result']);
        $this->assertFalse($diagnostic['parity_pass']);
        $this->assertNotEmpty((array)$diagnostic['mismatches']);
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
    private function writeCanonicalManifestAndSourceFiles(
        array $primary,
        array $secondary,
        string $generatedAtUtc = '2026-04-17T00:00:00Z'
    ): void {
        $canonicalRoot = $this->canonicalRoot();
        $this->ensureDir($canonicalRoot);
        $primaryPath = $canonicalRoot . DIRECTORY_SEPARATOR . 'reject_events_primary.json';
        $secondaryPath = $canonicalRoot . DIRECTORY_SEPARATOR . 'reject_events_secondary.json';
        $this->writeJson($primaryPath, $primary);
        $this->writeJson($secondaryPath, $secondary);

        $this->writeJson($this->canonicalManifestPath(), [
            'schema_version' => 'tmc-reject-audit-inputs.v1',
            'generated_at_utc' => $generatedAtUtc,
            'source_commit' => str_repeat('a', 40),
            'producer' => ['name' => 'test', 'version' => 'v1'],
            'sources' => [
                'primary' => [
                    'path' => 'reject_events_primary.json',
                    'checksum_sha256' => hash_file('sha256', $primaryPath),
                    'event_count' => 1,
                    'provenance' => [
                        'origin_path' => 'simulation_output/current-db/comparisons-v3-fast/comparison_tuning-verify-v3-fast-1.json',
                        'origin_checksum_sha256' => hash_file(
                            'sha256',
                            $this->repoRoot . DIRECTORY_SEPARATOR . 'simulation_output' . DIRECTORY_SEPARATOR
                            . 'current-db' . DIRECTORY_SEPARATOR . 'comparisons-v3-fast' . DIRECTORY_SEPARATOR
                            . 'comparison_tuning-verify-v3-fast-1.json'
                        ),
                    ],
                ],
                'secondary' => [
                    'path' => 'reject_events_secondary.json',
                    'checksum_sha256' => hash_file('sha256', $secondaryPath),
                    'event_count' => 1,
                    'provenance' => [
                        'origin_path' => 'simulation_output/current-db/verification-v2/verification_summary_v2.json',
                        'origin_checksum_sha256' => hash_file(
                            'sha256',
                            $this->repoRoot . DIRECTORY_SEPARATOR . 'simulation_output' . DIRECTORY_SEPARATOR
                            . 'current-db' . DIRECTORY_SEPARATOR . 'verification-v2' . DIRECTORY_SEPARATOR
                            . 'verification_summary_v2.json'
                        ),
                    ],
                ],
            ],
            'policy' => ['mode' => 'manifest_preferred'],
        ]);
    }

    /**
     * @param array<string, mixed> $primary
     * @param array<string, mixed> $secondary
     */
    private function writeOverrideManifestAndSourceFiles(array $primary, array $secondary): string
    {
        $primaryPath = $this->manifestRoot . DIRECTORY_SEPARATOR . 'reject_events_primary.json';
        $secondaryPath = $this->manifestRoot . DIRECTORY_SEPARATOR . 'reject_events_secondary.json';
        $manifestPath = $this->manifestRoot . DIRECTORY_SEPARATOR . 'manifest.json';
        $this->writeJson($primaryPath, $primary);
        $this->writeJson($secondaryPath, $secondary);
        $this->writeJson($manifestPath, [
            'schema_version' => 'tmc-reject-audit-inputs.v1',
            'generated_at_utc' => '2026-04-17T00:00:00Z',
            'sources' => [
                'primary' => ['path' => 'reject_events_primary.json'],
                'secondary' => ['path' => 'reject_events_secondary.json'],
            ],
        ]);
        return $manifestPath;
    }

    private function assertRequiredDiagnosticFields(array $diagnostic): void
    {
        foreach ([
            'mode',
            'manifest_source',
            'requested_manifest_path',
            'selected_manifest_path',
            'authoritative_source',
            'fallback_occurred',
            'fallback_reason',
            'freshness_status',
            'freshness_reasons',
            'parity_result',
            'parity_pass',
            'mismatches',
        ] as $field) {
            $this->assertArrayHasKey($field, $diagnostic);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function primaryPayload(): array
    {
        return [
            'seed' => 'seed-a',
            'scenarios' => [
                [
                    'scenario_name' => 'balanced-a',
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
                    'package_name' => 'balanced',
                    'per_seed' => [
                        [
                            'seed' => 'seed-1',
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

    private function canonicalRoot(): string
    {
        return $this->repoRoot . DIRECTORY_SEPARATOR . 'simulation_output'
            . DIRECTORY_SEPARATOR . 'current-db'
            . DIRECTORY_SEPARATOR . 'rejected-iteration-inputs'
            . DIRECTORY_SEPARATOR . 'current';
    }

    private function canonicalManifestPath(): string
    {
        return $this->canonicalRoot() . DIRECTORY_SEPARATOR . 'manifest.json';
    }

    private function manifestPreferredDiagnosticPath(): string
    {
        return $this->auditDir . DIRECTORY_SEPARATOR . 'rejected_iteration_manifest_preferred_diagnostic.json';
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
