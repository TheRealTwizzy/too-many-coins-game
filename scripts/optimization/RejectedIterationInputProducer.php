<?php

require_once __DIR__ . '/RejectedIterationInputResolver.php';

class AgenticRejectedIterationInputProducer
{
    public const SCHEMA_VERSION = 'tmc-reject-audit-inputs.v1';
    public const PRODUCER_NAME = 'scripts/prepare_rejected_iteration_inputs.php';
    public const PRODUCER_VERSION = 'v1';
    public const CANONICAL_RELATIVE_ROOT = 'simulation_output/current-db/rejected-iteration-inputs/current';
    public const PRIMARY_SOURCE_RELATIVE = 'simulation_output/current-db/comparisons-v3-fast/comparison_tuning-verify-v3-fast-1.json';
    public const SECONDARY_SOURCE_RELATIVE = 'simulation_output/current-db/verification-v2/verification_summary_v2.json';
    public const PRIMARY_CANONICAL_FILE = 'reject_events_primary.json';
    public const SECONDARY_CANONICAL_FILE = 'reject_events_secondary.json';
    public const MANIFEST_FILE = 'manifest.json';

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function generate(array $options = []): array
    {
        $repoRoot = self::resolveRepoRoot((string)($options['repo_root'] ?? dirname(__DIR__, 2)));
        $canonicalRoot = self::resolveAbsolutePath(
            (string)($options['canonical_root'] ?? self::CANONICAL_RELATIVE_ROOT),
            $repoRoot
        );
        $primarySource = self::resolveAbsolutePath(
            (string)($options['primary_source'] ?? self::PRIMARY_SOURCE_RELATIVE),
            $repoRoot
        );
        $secondarySource = self::resolveAbsolutePath(
            (string)($options['secondary_source'] ?? self::SECONDARY_SOURCE_RELATIVE),
            $repoRoot
        );
        $generatedAtUtc = (string)($options['generated_at_utc'] ?? gmdate('c'));
        $sourceCommit = trim((string)($options['source_commit'] ?? self::detectGitCommit($repoRoot)));
        $maxAgeSeconds = (int)($options['max_age_seconds'] ?? 86400);

        self::ensureDir($canonicalRoot);

        $primaryPayload = self::loadJsonFile($primarySource, 'primary_source_missing', 'primary_source_invalid_json');
        $secondaryPayload = self::loadJsonFile($secondarySource, 'secondary_source_missing', 'secondary_source_invalid_json');

        $primaryCanonicalPath = $canonicalRoot . DIRECTORY_SEPARATOR . self::PRIMARY_CANONICAL_FILE;
        $secondaryCanonicalPath = $canonicalRoot . DIRECTORY_SEPARATOR . self::SECONDARY_CANONICAL_FILE;
        self::writeDeterministicJson($primaryCanonicalPath, $primaryPayload);
        self::writeDeterministicJson($secondaryCanonicalPath, $secondaryPayload);

        $manifestPath = $canonicalRoot . DIRECTORY_SEPARATOR . self::MANIFEST_FILE;
        $manifest = self::buildManifest(
            $repoRoot,
            $generatedAtUtc,
            $sourceCommit,
            $primarySource,
            $secondarySource,
            $primaryCanonicalPath,
            $secondaryCanonicalPath
        );
        self::writeDeterministicJson($manifestPath, $manifest);

        $verification = AgenticRejectAuditManifestResolver::resolve(
            $manifestPath,
            $canonicalRoot,
            true,
            [
                'require_canonical_contract' => true,
                'validate_provenance' => true,
                'validate_checksums' => true,
                'validate_freshness' => true,
                'max_age_seconds' => $maxAgeSeconds,
                'repo_root' => $repoRoot,
                'freshness_now_utc' => $generatedAtUtc,
                'strict_integrity' => true,
            ]
        );
        if (!(bool)($verification['ok'] ?? false)) {
            throw new RuntimeException('Generated manifest failed resolver validation');
        }

        return [
            'ok' => true,
            'repo_root' => $repoRoot,
            'canonical_root' => $canonicalRoot,
            'manifest_path' => $manifestPath,
            'primary_canonical_path' => $primaryCanonicalPath,
            'secondary_canonical_path' => $secondaryCanonicalPath,
            'manifest' => $manifest,
            'verification' => $verification,
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function verify(array $options = []): array
    {
        $repoRoot = self::resolveRepoRoot((string)($options['repo_root'] ?? dirname(__DIR__, 2)));
        $canonicalRoot = self::resolveAbsolutePath(
            (string)($options['canonical_root'] ?? self::CANONICAL_RELATIVE_ROOT),
            $repoRoot
        );
        $manifestPath = self::resolveAbsolutePath(
            (string)($options['manifest_path'] ?? ($canonicalRoot . DIRECTORY_SEPARATOR . self::MANIFEST_FILE)),
            $repoRoot
        );
        $maxAgeSeconds = (int)($options['max_age_seconds'] ?? 86400);
        $strictIntegrity = (bool)($options['strict_integrity'] ?? false);

        $result = AgenticRejectAuditManifestResolver::resolve(
            $manifestPath,
            $canonicalRoot,
            true,
            [
                'require_canonical_contract' => true,
                'validate_provenance' => true,
                'validate_checksums' => true,
                'validate_freshness' => true,
                'max_age_seconds' => $maxAgeSeconds,
                'repo_root' => $repoRoot,
                'freshness_now_utc' => (string)($options['freshness_now_utc'] ?? ''),
                'strict_integrity' => $strictIntegrity,
            ]
        );

        return [
            'ok' => (bool)($result['ok'] ?? false),
            'manifest_path' => $manifestPath,
            'canonical_root' => $canonicalRoot,
            'resolver_result' => $result,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildManifest(
        string $repoRoot,
        string $generatedAtUtc,
        string $sourceCommit,
        string $primarySource,
        string $secondarySource,
        string $primaryCanonicalPath,
        string $secondaryCanonicalPath
    ): array {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at_utc' => $generatedAtUtc,
            'source_commit' => $sourceCommit,
            'producer' => [
                'name' => self::PRODUCER_NAME,
                'version' => self::PRODUCER_VERSION,
            ],
            'sources' => [
                'primary' => [
                    'path' => self::PRIMARY_CANONICAL_FILE,
                    'checksum_sha256' => hash_file('sha256', $primaryCanonicalPath) ?: '',
                    'event_count' => self::countPrimaryEvents($primaryCanonicalPath),
                    'provenance' => [
                        'origin_path' => self::relativePath($repoRoot, $primarySource),
                        'origin_checksum_sha256' => hash_file('sha256', $primarySource) ?: '',
                        'origin_size_bytes' => filesize($primarySource) ?: 0,
                    ],
                ],
                'secondary' => [
                    'path' => self::SECONDARY_CANONICAL_FILE,
                    'checksum_sha256' => hash_file('sha256', $secondaryCanonicalPath) ?: '',
                    'event_count' => self::countSecondaryEvents($secondaryCanonicalPath),
                    'provenance' => [
                        'origin_path' => self::relativePath($repoRoot, $secondarySource),
                        'origin_checksum_sha256' => hash_file('sha256', $secondarySource) ?: '',
                        'origin_size_bytes' => filesize($secondarySource) ?: 0,
                    ],
                ],
            ],
            'policy' => [
                'mode' => 'shadow-manifest',
                'strict_unknown_keys' => true,
                'missing_manifest' => 'fallback_legacy_or_fail_by_mode',
                'missing_primary' => 'fallback_legacy_or_fail_by_mode',
                'missing_secondary' => 'fallback_legacy_or_fail_by_mode',
            ],
        ];
    }

    private static function countPrimaryEvents(string $jsonPath): int
    {
        $payload = json_decode((string)file_get_contents($jsonPath), true);
        if (!is_array($payload)) {
            return 0;
        }
        $count = 0;
        foreach ((array)($payload['scenarios'] ?? []) as $scenario) {
            if ((string)($scenario['recommended_disposition'] ?? '') === 'reject') {
                $count++;
            }
        }
        return $count;
    }

    private static function countSecondaryEvents(string $jsonPath): int
    {
        $payload = json_decode((string)file_get_contents($jsonPath), true);
        if (!is_array($payload)) {
            return 0;
        }
        $count = 0;
        foreach ((array)($payload['packages'] ?? []) as $package) {
            foreach ((array)($package['per_seed'] ?? []) as $seedRow) {
                if ((string)($seedRow['disposition'] ?? '') === 'reject') {
                    $count++;
                }
            }
        }
        return $count;
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadJsonFile(string $path, string $missingCode, string $invalidCode): array
    {
        if (!is_file($path)) {
            throw new RuntimeException($missingCode . ': ' . $path);
        }
        $payload = json_decode((string)file_get_contents($path), true);
        if (!is_array($payload)) {
            throw new RuntimeException($invalidCode . ': ' . $path);
        }
        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function writeDeterministicJson(string $path, array $payload): void
    {
        self::ensureDir(dirname($path));
        $stable = self::sortKeysRecursive($payload);
        $json = json_encode($stable, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('Failed to encode JSON: ' . $path);
        }
        file_put_contents($path, $json . PHP_EOL);
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function sortKeysRecursive($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            foreach ($value as $i => $item) {
                $value[$i] = self::sortKeysRecursive($item);
            }
            return $value;
        }

        ksort($value);
        foreach ($value as $k => $item) {
            $value[$k] = self::sortKeysRecursive($item);
        }
        return $value;
    }

    private static function resolveRepoRoot(string $repoRoot): string
    {
        $real = realpath($repoRoot);
        if ($real === false) {
            throw new RuntimeException('Failed to resolve repo root: ' . $repoRoot);
        }
        return $real;
    }

    private static function resolveAbsolutePath(string $path, string $repoRoot): string
    {
        $trimmed = trim($path);
        if ($trimmed === '') {
            return $repoRoot;
        }
        $isWindowsAbsolute = preg_match('/^[A-Za-z]:[\\\\\\/]/', $trimmed) === 1;
        $isUnixAbsolute = str_starts_with($trimmed, '/');
        if ($isWindowsAbsolute || $isUnixAbsolute) {
            return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $trimmed);
        }
        return $repoRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $trimmed);
    }

    private static function relativePath(string $root, string $path): string
    {
        $rootNorm = str_replace('\\', '/', rtrim($root, "\\/")) . '/';
        $pathNorm = str_replace('\\', '/', $path);
        if (str_starts_with($pathNorm, $rootNorm)) {
            return substr($pathNorm, strlen($rootNorm));
        }
        return $pathNorm;
    }

    private static function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create directory: ' . $dir);
        }
    }

    private static function detectGitCommit(string $repoRoot): string
    {
        $cmd = 'git -C ' . escapeshellarg($repoRoot) . ' rev-parse HEAD 2>NUL';
        $output = shell_exec($cmd);
        if (!is_string($output)) {
            return '';
        }
        $sha = trim($output);
        if (preg_match('/^[0-9a-f]{40}$/', $sha) === 1) {
            return $sha;
        }
        return '';
    }
}
