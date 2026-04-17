<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/optimization/AgenticOptimization.php';

class AgenticOptimizationManifestStrictModeTest extends TestCase
{
    private string $tempRoot;
    private string $repoRoot;
    private string $auditDir;
    private string $overrideRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tmc-manifest-strict-' . bin2hex(random_bytes(6));
        $this->repoRoot = $this->tempRoot . DIRECTORY_SEPARATOR . 'repo';
        $this->auditDir = $this->tempRoot . DIRECTORY_SEPARATOR . 'audit';
        $this->overrideRoot = $this->tempRoot . DIRECTORY_SEPARATOR . 'override';
        $this->ensureDir($this->repoRoot);
        $this->ensureDir($this->auditDir);
        $this->ensureDir($this->overrideRoot);
    }

    protected function tearDown(): void
    {
        $this->removeDirRecursive($this->tempRoot);
        parent::tearDown();
    }

    public function testDefaultModeRemainsLegacyAndStrictModeIsRecognized(): void
    {
        $this->assertSame('legacy', AgenticOptimizationCoordinator::resolveRejectAuditMode([]));
        $this->assertSame('manifest_strict', AgenticOptimizationCoordinator::resolveRejectAuditMode([
            'reject_audit_mode' => 'manifest_strict',
        ]));
    }

    public function testManifestStrictSuccessWithCanonicalDefault(): void
    {
        $legacyAudit = $this->buildLegacyAuditFromRepoInputs();
        $this->writeCanonicalManifestAndSources($this->primaryPayload(), $this->secondaryPayload(), gmdate('c'));

        $result = AgenticRejectedIterationShadowParity::runManifestStrict($this->repoRoot, $this->auditDir, $legacyAudit, null);
        $diagnostic = (array)$result['diagnostic'];

        $this->assertTrue($result['strict_success']);
        $this->assertSame(0, $result['exit_code']);
        $this->assertSame('manifest', $result['authoritative_source']);
        $this->assertSame('manifest', $diagnostic['authoritative_source']);
        $this->assertSame('success', $diagnostic['strict_result']);
        $this->assertNull($diagnostic['failure_reason']);
        $this->assertFalse($diagnostic['fallback_occurred']);
        $this->assertSame('pass', $diagnostic['parity_result']);
        $this->assertTrue($diagnostic['parity_pass']);
        $this->assertRequiredStrictDiagnosticFields($diagnostic);
    }

    public function testManifestStrictSuccessWithOverrideManifest(): void
    {
        $legacyAudit = $this->buildLegacyAuditFromRepoInputs();
        $canonicalMismatch = $this->primaryPayload();
        $canonicalMismatch['scenarios'][0]['wins'] = 99;
        $this->writeCanonicalManifestAndSources($canonicalMismatch, $this->secondaryPayload(), gmdate('c'));

        $overrideManifestPath = $this->writeOverrideManifestAndSources($this->primaryPayload(), $this->secondaryPayload(), gmdate('c'));
        $result = AgenticRejectedIterationShadowParity::runManifestStrict($this->repoRoot, $this->auditDir, $legacyAudit, $overrideManifestPath);
        $diagnostic = (array)$result['diagnostic'];

        $this->assertTrue($result['strict_success']);
        $this->assertSame(0, $result['exit_code']);
        $this->assertSame('manifest', $diagnostic['authoritative_source']);
        $this->assertSame('override', $diagnostic['manifest_source']);
        $this->assertSame($overrideManifestPath, $diagnostic['selected_manifest_path']);
        $this->assertFalse($diagnostic['fallback_occurred']);
    }

    public function testManifestMissingHardFailure(): void
    {
        $legacyAudit = $this->buildLegacyAuditFromRepoInputs();
        $result = AgenticRejectedIterationShadowParity::runManifestStrict($this->repoRoot, $this->auditDir, $legacyAudit, null);
        $this->assertStrictFailure($result, 'canonical_manifest_missing', 20);
    }

    public function testManifestInvalidHardFailure(): void
    {
        $legacyAudit = $this->buildLegacyAuditFromRepoInputs();
        $this->ensureDir($this->canonicalRoot());
        file_put_contents($this->canonicalManifestPath(), '{"schema_version":"bad"}');

        $result = AgenticRejectedIterationShadowParity::runManifestStrict($this->repoRoot, $this->auditDir, $legacyAudit, null);
        $this->assertStrictFailure($result, 'canonical_manifest_invalid', 21);
    }

    public function testCanonicalStaleHardFailure(): void
    {
        $legacyAudit = $this->buildLegacyAuditFromRepoInputs();
        $this->writeCanonicalManifestAndSources($this->primaryPayload(), $this->secondaryPayload(), '2020-01-01T00:00:00Z');

        $result = AgenticRejectedIterationShadowParity::runManifestStrict($this->repoRoot, $this->auditDir, $legacyAudit, null);
        $this->assertStrictFailure($result, 'canonical_manifest_stale', 22);
    }

    public function testIntegrityChecksumHardFailure(): void
    {
        $legacyAudit = $this->buildLegacyAuditFromRepoInputs();
        $this->writeCanonicalManifestAndSources($this->primaryPayload(), $this->secondaryPayload(), gmdate('c'), true);

        $result = AgenticRejectedIterationShadowParity::runManifestStrict($this->repoRoot, $this->auditDir, $legacyAudit, null);
        $this->assertStrictFailure($result, 'integrity_failed', 23);
    }

    public function testPrimaryMissingHardFailure(): void
    {
        $legacyAudit = $this->buildLegacyAuditFromRepoInputs();
        $this->ensureDir($this->overrideRoot);
        $secondaryPath = $this->overrideRoot . DIRECTORY_SEPARATOR . 'reject_events_secondary.json';
        $this->writeJson($secondaryPath, $this->secondaryPayload());
        $manifestPath = $this->overrideRoot . DIRECTORY_SEPARATOR . 'manifest.json';
        $this->writeJson($manifestPath, [
            'schema_version' => 'tmc-reject-audit-inputs.v1',
            'generated_at_utc' => gmdate('c'),
            'source_commit' => str_repeat('a', 40),
            'producer' => ['name' => 'test', 'version' => 'v1'],
            'sources' => [
                'primary' => [
                    'path' => 'missing_primary.json',
                    'checksum_sha256' => str_repeat('0', 64),
                    'event_count' => 1,
                    'provenance' => [
                        'origin_path' => 'simulation_output/current-db/comparisons-v3-fast/comparison_tuning-verify-v3-fast-1.json',
                        'origin_checksum_sha256' => hash_file('sha256', $this->legacyPrimaryPath()),
                    ],
                ],
                'secondary' => [
                    'path' => 'reject_events_secondary.json',
                    'checksum_sha256' => hash_file('sha256', $secondaryPath),
                    'event_count' => 1,
                    'provenance' => [
                        'origin_path' => 'simulation_output/current-db/verification-v2/verification_summary_v2.json',
                        'origin_checksum_sha256' => hash_file('sha256', $this->legacySecondaryPath()),
                    ],
                ],
            ],
            'policy' => ['mode' => 'manifest_strict'],
        ]);

        $result = AgenticRejectedIterationShadowParity::runManifestStrict($this->repoRoot, $this->auditDir, $legacyAudit, $manifestPath);
        $this->assertStrictFailure($result, 'primary_missing', 24);
    }

    public function testSecondaryMissingHardFailure(): void
    {
        $legacyAudit = $this->buildLegacyAuditFromRepoInputs();
        $this->ensureDir($this->overrideRoot);
        $primaryPath = $this->overrideRoot . DIRECTORY_SEPARATOR . 'reject_events_primary.json';
        $this->writeJson($primaryPath, $this->primaryPayload());
        $manifestPath = $this->overrideRoot . DIRECTORY_SEPARATOR . 'manifest.json';
        $this->writeJson($manifestPath, [
            'schema_version' => 'tmc-reject-audit-inputs.v1',
            'generated_at_utc' => gmdate('c'),
            'source_commit' => str_repeat('a', 40),
            'producer' => ['name' => 'test', 'version' => 'v1'],
            'sources' => [
                'primary' => [
                    'path' => 'reject_events_primary.json',
                    'checksum_sha256' => hash_file('sha256', $primaryPath),
                    'event_count' => 1,
                    'provenance' => [
                        'origin_path' => 'simulation_output/current-db/comparisons-v3-fast/comparison_tuning-verify-v3-fast-1.json',
                        'origin_checksum_sha256' => hash_file('sha256', $this->legacyPrimaryPath()),
                    ],
                ],
                'secondary' => [
                    'path' => 'missing_secondary.json',
                    'checksum_sha256' => str_repeat('0', 64),
                    'event_count' => 1,
                    'provenance' => [
                        'origin_path' => 'simulation_output/current-db/verification-v2/verification_summary_v2.json',
                        'origin_checksum_sha256' => hash_file('sha256', $this->legacySecondaryPath()),
                    ],
                ],
            ],
            'policy' => ['mode' => 'manifest_strict'],
        ]);

        $result = AgenticRejectedIterationShadowParity::runManifestStrict($this->repoRoot, $this->auditDir, $legacyAudit, $manifestPath);
        $this->assertStrictFailure($result, 'secondary_missing', 25);
    }

    public function testManifestBuildFailureHardFailure(): void
    {
        $legacyAudit = $this->buildLegacyAuditFromRepoInputs();
        $this->ensureDir($this->overrideRoot);
        $primaryPath = $this->overrideRoot . DIRECTORY_SEPARATOR . 'reject_events_primary.json';
        $secondaryPath = $this->overrideRoot . DIRECTORY_SEPARATOR . 'reject_events_secondary.json';
        file_put_contents($primaryPath, '{bad-json}');
        $this->writeJson($secondaryPath, $this->secondaryPayload());
        $manifestPath = $this->overrideRoot . DIRECTORY_SEPARATOR . 'manifest.json';
        $this->writeJson($manifestPath, [
            'schema_version' => 'tmc-reject-audit-inputs.v1',
            'generated_at_utc' => gmdate('c'),
            'source_commit' => str_repeat('a', 40),
            'producer' => ['name' => 'test', 'version' => 'v1'],
            'sources' => [
                'primary' => [
                    'path' => 'reject_events_primary.json',
                    'checksum_sha256' => hash_file('sha256', $primaryPath),
                    'event_count' => 1,
                    'provenance' => [
                        'origin_path' => 'simulation_output/current-db/comparisons-v3-fast/comparison_tuning-verify-v3-fast-1.json',
                        'origin_checksum_sha256' => hash_file('sha256', $this->legacyPrimaryPath()),
                    ],
                ],
                'secondary' => [
                    'path' => 'reject_events_secondary.json',
                    'checksum_sha256' => hash_file('sha256', $secondaryPath),
                    'event_count' => 1,
                    'provenance' => [
                        'origin_path' => 'simulation_output/current-db/verification-v2/verification_summary_v2.json',
                        'origin_checksum_sha256' => hash_file('sha256', $this->legacySecondaryPath()),
                    ],
                ],
            ],
            'policy' => ['mode' => 'manifest_strict'],
        ]);

        $result = AgenticRejectedIterationShadowParity::runManifestStrict($this->repoRoot, $this->auditDir, $legacyAudit, $manifestPath);
        $this->assertStrictFailure($result, 'manifest_build_failed', 26);
    }

    public function testParityMismatchHardFailure(): void
    {
        $legacyAudit = $this->buildLegacyAuditFromRepoInputs();
        $mismatchPrimary = $this->primaryPayload();
        $mismatchPrimary['scenarios'][0]['wins'] = 99;
        $this->writeCanonicalManifestAndSources($mismatchPrimary, $this->secondaryPayload(), gmdate('c'));

        $result = AgenticRejectedIterationShadowParity::runManifestStrict($this->repoRoot, $this->auditDir, $legacyAudit, null);
        $this->assertStrictFailure($result, 'parity_mismatch', 27);
        $diagnostic = (array)$result['diagnostic'];
        $this->assertSame('fail', $diagnostic['parity_result']);
        $this->assertFalse($diagnostic['parity_pass']);
        $this->assertNotEmpty((array)$diagnostic['mismatches']);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildLegacyAuditFromRepoInputs(): array
    {
        $legacyV2Path = dirname($this->legacySecondaryPath());
        $legacyV3Path = dirname($this->legacyPrimaryPath());
        $this->ensureDir($legacyV2Path);
        $this->ensureDir($legacyV3Path);
        $this->writeJson($this->legacySecondaryPath(), $this->secondaryPayload());
        $this->writeJson($this->legacyPrimaryPath(), $this->primaryPayload());

        return AgenticRejectedIterationAuditor::run($this->repoRoot, $this->auditDir . DIRECTORY_SEPARATOR . 'legacy');
    }

    /**
     * @param array<string, mixed> $primary
     * @param array<string, mixed> $secondary
     */
    private function writeCanonicalManifestAndSources(
        array $primary,
        array $secondary,
        string $generatedAtUtc,
        bool $forceChecksumMismatch = false
    ): void {
        $this->ensureDir($this->canonicalRoot());
        $primaryPath = $this->canonicalRoot() . DIRECTORY_SEPARATOR . 'reject_events_primary.json';
        $secondaryPath = $this->canonicalRoot() . DIRECTORY_SEPARATOR . 'reject_events_secondary.json';
        $this->writeJson($primaryPath, $primary);
        $this->writeJson($secondaryPath, $secondary);

        $primaryChecksum = hash_file('sha256', $primaryPath);
        if ($forceChecksumMismatch) {
            $primaryChecksum = str_repeat('0', 64);
        }

        $this->writeJson($this->canonicalManifestPath(), [
            'schema_version' => 'tmc-reject-audit-inputs.v1',
            'generated_at_utc' => $generatedAtUtc,
            'source_commit' => str_repeat('a', 40),
            'producer' => ['name' => 'test', 'version' => 'v1'],
            'sources' => [
                'primary' => [
                    'path' => 'reject_events_primary.json',
                    'checksum_sha256' => $primaryChecksum,
                    'event_count' => 1,
                    'provenance' => [
                        'origin_path' => 'simulation_output/current-db/comparisons-v3-fast/comparison_tuning-verify-v3-fast-1.json',
                        'origin_checksum_sha256' => hash_file('sha256', $this->legacyPrimaryPath()),
                    ],
                ],
                'secondary' => [
                    'path' => 'reject_events_secondary.json',
                    'checksum_sha256' => hash_file('sha256', $secondaryPath),
                    'event_count' => 1,
                    'provenance' => [
                        'origin_path' => 'simulation_output/current-db/verification-v2/verification_summary_v2.json',
                        'origin_checksum_sha256' => hash_file('sha256', $this->legacySecondaryPath()),
                    ],
                ],
            ],
            'policy' => ['mode' => 'manifest_strict'],
        ]);
    }

    /**
     * @param array<string, mixed> $primary
     * @param array<string, mixed> $secondary
     */
    private function writeOverrideManifestAndSources(array $primary, array $secondary, string $generatedAtUtc): string
    {
        $primaryPath = $this->overrideRoot . DIRECTORY_SEPARATOR . 'reject_events_primary.json';
        $secondaryPath = $this->overrideRoot . DIRECTORY_SEPARATOR . 'reject_events_secondary.json';
        $manifestPath = $this->overrideRoot . DIRECTORY_SEPARATOR . 'manifest.json';
        $this->writeJson($primaryPath, $primary);
        $this->writeJson($secondaryPath, $secondary);
        $this->writeJson($manifestPath, [
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
                        'origin_checksum_sha256' => hash_file('sha256', $this->legacyPrimaryPath()),
                    ],
                ],
                'secondary' => [
                    'path' => 'reject_events_secondary.json',
                    'checksum_sha256' => hash_file('sha256', $secondaryPath),
                    'event_count' => 1,
                    'provenance' => [
                        'origin_path' => 'simulation_output/current-db/verification-v2/verification_summary_v2.json',
                        'origin_checksum_sha256' => hash_file('sha256', $this->legacySecondaryPath()),
                    ],
                ],
            ],
            'policy' => ['mode' => 'manifest_strict'],
        ]);
        return $manifestPath;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function assertStrictFailure(array $result, string $expectedReason, int $expectedExitCode): void
    {
        $this->assertFalse((bool)$result['strict_success']);
        $this->assertSame($expectedExitCode, (int)$result['exit_code']);
        $this->assertSame('none_due_to_failure', (string)$result['authoritative_source']);

        $diagnostic = (array)$result['diagnostic'];
        $this->assertSame('failure', $diagnostic['strict_result']);
        $this->assertSame($expectedReason, $diagnostic['failure_reason']);
        $this->assertSame($expectedExitCode, (int)$diagnostic['exit_code']);
        $this->assertFalse((bool)$diagnostic['fallback_occurred']);
        $this->assertFileExists($this->strictDiagnosticPath());
        $this->assertRequiredStrictDiagnosticFields($diagnostic);
    }

    private function assertRequiredStrictDiagnosticFields(array $diagnostic): void
    {
        foreach ([
            'mode',
            'manifest_source',
            'requested_manifest_path',
            'selected_manifest_path',
            'authoritative_source',
            'strict_result',
            'failure_reason',
            'fallback_occurred',
            'freshness_status',
            'freshness_reasons',
            'parity_result',
            'parity_pass',
            'mismatches',
            'exit_code',
        ] as $field) {
            $this->assertArrayHasKey($field, $diagnostic);
        }
        $this->assertSame('manifest_strict', $diagnostic['mode']);
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

    private function strictDiagnosticPath(): string
    {
        return $this->auditDir . DIRECTORY_SEPARATOR . 'rejected_iteration_manifest_strict_diagnostic.json';
    }

    private function legacyPrimaryPath(): string
    {
        return $this->repoRoot . DIRECTORY_SEPARATOR . 'simulation_output'
            . DIRECTORY_SEPARATOR . 'current-db'
            . DIRECTORY_SEPARATOR . 'comparisons-v3-fast'
            . DIRECTORY_SEPARATOR . 'comparison_tuning-verify-v3-fast-1.json';
    }

    private function legacySecondaryPath(): string
    {
        return $this->repoRoot . DIRECTORY_SEPARATOR . 'simulation_output'
            . DIRECTORY_SEPARATOR . 'current-db'
            . DIRECTORY_SEPARATOR . 'verification-v2'
            . DIRECTORY_SEPARATOR . 'verification_summary_v2.json';
    }

    /**
     * @return array<string, mixed>
     */
    private function primaryPayload(): array
    {
        return [
            'seed' => 'strict-seed-1',
            'scenarios' => [
                [
                    'scenario_name' => 'strict-scenario-a',
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
                    'package_name' => 'strict-balanced',
                    'per_seed' => [
                        [
                            'seed' => 'strict-v2-seed-1',
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
