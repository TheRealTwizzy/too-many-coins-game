<?php

class AgenticRejectAuditManifestValidator
{
    /**
     * @return array{
     *   ok: bool,
     *   errors: array<int, array{code: string, path: string, message: string}>,
     *   warnings: array<int, array{code: string, path: string, message: string}>,
     *   normalized: array<string, mixed>
     * }
     */
    public static function validate(array $manifest, bool $strictUnknown = true): array
    {
        $errors = [];
        $warnings = [];

        $allowedTopLevel = [
            'schema_version',
            'generated_at_utc',
            'source_commit',
            'producer',
            'sources',
            'policy',
        ];

        if ($strictUnknown) {
            foreach (array_keys($manifest) as $key) {
                if (!in_array((string)$key, $allowedTopLevel, true)) {
                    $errors[] = self::error('unknown_key', '/' . $key, 'Unknown top-level key: ' . $key);
                }
            }
        }

        if (!array_key_exists('schema_version', $manifest)) {
            $errors[] = self::error('required_key_missing', '/schema_version', 'Missing required key: schema_version');
        } elseif (!is_string($manifest['schema_version']) || trim($manifest['schema_version']) === '') {
            $errors[] = self::error('invalid_type', '/schema_version', 'schema_version must be a non-empty string');
        }

        if (!array_key_exists('generated_at_utc', $manifest)) {
            $errors[] = self::error('required_key_missing', '/generated_at_utc', 'Missing required key: generated_at_utc');
        } elseif (!is_string($manifest['generated_at_utc']) || trim($manifest['generated_at_utc']) === '') {
            $errors[] = self::error('invalid_type', '/generated_at_utc', 'generated_at_utc must be a non-empty string');
        }

        if (!array_key_exists('sources', $manifest)) {
            $errors[] = self::error('required_key_missing', '/sources', 'Missing required key: sources');
        } elseif (!is_array($manifest['sources'])) {
            $errors[] = self::error('invalid_type', '/sources', 'sources must be an object');
        }

        $normalizedSources = [
            'primary' => ['path' => ''],
            'secondary' => ['path' => ''],
        ];

        if (is_array($manifest['sources'] ?? null)) {
            $sources = (array)$manifest['sources'];
            $allowedSourceKeys = ['path', 'checksum_sha256', 'event_count', 'provenance'];

            foreach (['primary', 'secondary'] as $sourceKey) {
                if (!array_key_exists($sourceKey, $sources)) {
                    $errors[] = self::error(
                        'required_key_missing',
                        '/sources/' . $sourceKey,
                        'Missing required key: sources.' . $sourceKey
                    );
                    continue;
                }

                if (!is_array($sources[$sourceKey])) {
                    $errors[] = self::error(
                        'invalid_type',
                        '/sources/' . $sourceKey,
                        'sources.' . $sourceKey . ' must be an object'
                    );
                    continue;
                }

                $source = (array)$sources[$sourceKey];
                if ($strictUnknown) {
                    foreach (array_keys($source) as $k) {
                        if (!in_array((string)$k, $allowedSourceKeys, true)) {
                            $errors[] = self::error(
                                'unknown_key',
                                '/sources/' . $sourceKey . '/' . $k,
                                'Unknown source key: sources.' . $sourceKey . '.' . $k
                            );
                        }
                    }
                }

                if (!array_key_exists('path', $source)) {
                    $errors[] = self::error(
                        'required_key_missing',
                        '/sources/' . $sourceKey . '/path',
                        'Missing required key: sources.' . $sourceKey . '.path'
                    );
                } elseif (!is_string($source['path']) || trim($source['path']) === '') {
                    $errors[] = self::error(
                        'invalid_type',
                        '/sources/' . $sourceKey . '/path',
                        'sources.' . $sourceKey . '.path must be a non-empty string'
                    );
                }

                $normalizedSources[$sourceKey] = $source;
            }

            if ($strictUnknown) {
                foreach (array_keys($sources) as $k) {
                    if (!in_array((string)$k, ['primary', 'secondary'], true)) {
                        $errors[] = self::error(
                            'unknown_key',
                            '/sources/' . $k,
                            'Unknown sources key: ' . $k
                        );
                    }
                }
            }
        }

        $normalizedProducer = '';
        if (array_key_exists('producer', $manifest)) {
            if (is_string($manifest['producer'])) {
                $normalizedProducer = (string)$manifest['producer'];
            } elseif (is_array($manifest['producer'])) {
                $normalizedProducer = [
                    'name' => (string)($manifest['producer']['name'] ?? ''),
                    'version' => (string)($manifest['producer']['version'] ?? ''),
                ];
            } else {
                $errors[] = self::error('invalid_type', '/producer', 'producer must be a string or object');
            }
        }

        $normalized = [
            'schema_version' => (string)($manifest['schema_version'] ?? ''),
            'generated_at_utc' => (string)($manifest['generated_at_utc'] ?? ''),
            'source_commit' => (string)($manifest['source_commit'] ?? ''),
            'producer' => $normalizedProducer,
            'sources' => $normalizedSources,
            'policy' => (array)($manifest['policy'] ?? []),
        ];

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'normalized' => $normalized,
        ];
    }

    /**
     * @return array{code: string, path: string, message: string}
     */
    private static function error(string $code, string $path, string $message): array
    {
        return [
            'code' => $code,
            'path' => $path,
            'message' => $message,
        ];
    }
}

class AgenticRejectAuditPathGuard
{
    /**
     * @return array{ok: bool, code?: string, message?: string}
     */
    public static function validateRelativePath(string $path): array
    {
        $value = trim($path);
        if ($value === '') {
            return ['ok' => false, 'code' => 'empty_path', 'message' => 'Path must not be empty'];
        }

        if (strpos($value, "\0") !== false) {
            return ['ok' => false, 'code' => 'null_byte_not_allowed', 'message' => 'Path contains null byte'];
        }

        if (preg_match('/^(?:[A-Za-z]:[\\\\\\/]|[\\\\\\/]{2}|[\\\\\\/])/', $value) === 1) {
            return ['ok' => false, 'code' => 'absolute_path_not_allowed', 'message' => 'Absolute paths are not allowed'];
        }

        $segments = preg_split('/[\\\\\\/]+/', str_replace('\\', '/', $value));
        foreach ((array)$segments as $segment) {
            if ($segment === '..') {
                return ['ok' => false, 'code' => 'traversal_segment_not_allowed', 'message' => 'Path traversal is not allowed'];
            }
            if ($segment === '.') {
                return ['ok' => false, 'code' => 'dot_segment_not_allowed', 'message' => 'Dot path segments are not allowed'];
            }
        }

        return ['ok' => true];
    }

    /**
     * @return array{
     *   ok: bool,
     *   status: string,
     *   resolved_path?: string,
     *   error_code?: string,
     *   message?: string
     * }
     */
    public static function resolveUnderRoot(string $canonicalRoot, string $relativePath): array
    {
        $pathCheck = self::validateRelativePath($relativePath);
        if (!$pathCheck['ok']) {
            return [
                'ok' => false,
                'status' => 'invalid_path',
                'error_code' => (string)($pathCheck['code'] ?? 'invalid_path'),
                'message' => (string)($pathCheck['message'] ?? 'Invalid path'),
            ];
        }

        $rootReal = realpath($canonicalRoot);
        if ($rootReal === false) {
            return [
                'ok' => false,
                'status' => 'canonical_root_missing',
                'error_code' => 'canonical_root_missing',
                'message' => 'Canonical root does not exist',
            ];
        }

        $candidate = rtrim($rootReal, "\\/") . DIRECTORY_SEPARATOR
            . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);

        if (!file_exists($candidate)) {
            return [
                'ok' => true,
                'status' => 'artifact_missing',
                'resolved_path' => $candidate,
                'error_code' => 'artifact_missing',
                'message' => 'Artifact file does not exist at resolved path',
            ];
        }

        $resolvedReal = realpath($candidate);
        if ($resolvedReal === false) {
            return [
                'ok' => false,
                'status' => 'path_resolution_failed',
                'error_code' => 'path_resolution_failed',
                'message' => 'Failed to resolve artifact path',
            ];
        }

        $containment = self::ensureContainedResolvedPath($rootReal, $resolvedReal);
        if (!$containment['ok']) {
            return [
                'ok' => false,
                'status' => 'outside_root',
                'resolved_path' => $resolvedReal,
                'error_code' => (string)($containment['code'] ?? 'outside_root'),
                'message' => (string)($containment['message'] ?? 'Resolved path is outside canonical root'),
            ];
        }

        return [
            'ok' => true,
            'status' => 'present',
            'resolved_path' => $resolvedReal,
        ];
    }

    /**
     * @return array{ok: bool, code?: string, message?: string}
     */
    public static function ensureContainedResolvedPath(string $canonicalRoot, string $resolvedPath): array
    {
        $root = self::normalizeAbsolutePath($canonicalRoot);
        $target = self::normalizeAbsolutePath($resolvedPath);

        if ($target === $root || str_starts_with($target, $root . '/')) {
            return ['ok' => true];
        }

        return [
            'ok' => false,
            'code' => 'outside_root',
            'message' => 'Resolved path is outside canonical root',
        ];
    }

    private static function normalizeAbsolutePath(string $path): string
    {
        $normalized = str_replace('\\', '/', rtrim($path, "\\/"));
        if (preg_match('/^[A-Za-z]:\//', $normalized) === 1) {
            return strtolower($normalized);
        }
        return $normalized;
    }
}

class AgenticRejectAuditManifestResolver
{
    public const SCHEMA_VERSION = 'tmc-reject-audit-inputs.v1';
    public const PRIMARY_SOURCE_KEY = 'primary';
    public const SECONDARY_SOURCE_KEY = 'secondary';

    /**
     * @return array{
     *   ok: bool,
     *   manifest_valid: bool,
     *   errors: array<int, array<string, string>>,
     *   warnings: array<int, array<string, string>>,
     *   canonical_root: string,
     *   manifest: array<string, mixed>,
     *   resolved_sources: array<string, array<string, mixed>>,
     *   missing_sources: array<int, string>,
     *   integrity?: array<string, mixed>
     * }
     */
    public static function resolve(
        string $manifestPath,
        ?string $canonicalRoot = null,
        bool $strictUnknown = true,
        array $integrityOptions = []
    ): array
    {
        $result = [
            'ok' => false,
            'manifest_valid' => false,
            'errors' => [],
            'warnings' => [],
            'canonical_root' => '',
            'manifest' => [],
            'resolved_sources' => [],
            'missing_sources' => [],
        ];

        if (!is_file($manifestPath)) {
            $result['errors'][] = self::error('manifest_missing', '/manifest', 'Manifest file not found: ' . $manifestPath);
            return $result;
        }

        $payload = json_decode((string)file_get_contents($manifestPath), true);
        if (!is_array($payload)) {
            $result['errors'][] = self::error('manifest_invalid_json', '/manifest', 'Manifest JSON is invalid');
            return $result;
        }

        $validated = AgenticRejectAuditManifestValidator::validate($payload, $strictUnknown);
        $result['manifest_valid'] = (bool)$validated['ok'];
        $result['errors'] = array_values((array)$validated['errors']);
        $result['warnings'] = array_values((array)$validated['warnings']);
        $result['manifest'] = (array)($validated['normalized'] ?? []);

        if (!$validated['ok']) {
            return $result;
        }

        $root = $canonicalRoot ?? dirname($manifestPath);
        $rootReal = realpath($root);
        if ($rootReal === false) {
            $result['errors'][] = self::error('canonical_root_missing', '/canonical_root', 'Canonical root not found: ' . $root);
            return $result;
        }
        $result['canonical_root'] = $rootReal;

        foreach ([self::PRIMARY_SOURCE_KEY, self::SECONDARY_SOURCE_KEY] as $sourceName) {
            $source = (array)($result['manifest']['sources'][$sourceName] ?? []);
            $sourcePath = (string)($source['path'] ?? '');

            $resolved = AgenticRejectAuditPathGuard::resolveUnderRoot($rootReal, $sourcePath);
            $result['resolved_sources'][$sourceName] = array_merge(['input_path' => $sourcePath], $resolved);

            if (($resolved['status'] ?? '') === 'artifact_missing') {
                $result['missing_sources'][] = $sourceName;
                $result['warnings'][] = self::error(
                    'artifact_missing',
                    '/sources/' . $sourceName . '/path',
                    'Artifact missing for source: ' . $sourceName
                );
            }

            if (!$resolved['ok']) {
                $result['errors'][] = self::error(
                    (string)($resolved['error_code'] ?? 'path_error'),
                    '/sources/' . $sourceName . '/path',
                    (string)($resolved['message'] ?? 'Path resolution error')
                );
            }
        }

        $integrity = self::runIntegrityChecks(
            $result['manifest'],
            $result['resolved_sources'],
            $manifestPath,
            $integrityOptions
        );
        if ($integrity['enabled']) {
            $result['integrity'] = $integrity;
            $result['warnings'] = array_values(array_merge($result['warnings'], $integrity['warnings']));
            $result['errors'] = array_values(array_merge($result['errors'], $integrity['errors']));
        }

        $result['ok'] = $result['errors'] === [];
        return $result;
    }

    /**
     * @param array<string, mixed> $manifest
     * @param array<string, array<string, mixed>> $resolvedSources
     * @param array<string, mixed> $integrityOptions
     * @return array{
     *   enabled: bool,
     *   errors: array<int, array<string, string>>,
     *   warnings: array<int, array<string, string>>,
     *   provenance: array<string, mixed>,
     *   checksums: array<string, mixed>,
     *   freshness: array<string, mixed>
     * }
     */
    private static function runIntegrityChecks(
        array $manifest,
        array $resolvedSources,
        string $manifestPath,
        array $integrityOptions
    ): array {
        $enabled = (bool)($integrityOptions['require_canonical_contract'] ?? false)
            || (bool)($integrityOptions['validate_provenance'] ?? false)
            || (bool)($integrityOptions['validate_checksums'] ?? false)
            || (bool)($integrityOptions['validate_freshness'] ?? false);

        $result = [
            'enabled' => $enabled,
            'errors' => [],
            'warnings' => [],
            'provenance' => [],
            'checksums' => [],
            'freshness' => ['status' => 'not_checked'],
        ];
        if (!$enabled) {
            return $result;
        }

        if ((bool)($integrityOptions['require_canonical_contract'] ?? false)) {
            if ((string)($manifest['schema_version'] ?? '') !== self::SCHEMA_VERSION) {
                $result['errors'][] = self::error(
                    'schema_version_mismatch',
                    '/schema_version',
                    'schema_version must be ' . self::SCHEMA_VERSION
                );
            }
            $commit = (string)($manifest['source_commit'] ?? '');
            if ($commit === '' || preg_match('/^[0-9a-f]{40}$/', $commit) !== 1) {
                $result['errors'][] = self::error(
                    'invalid_source_commit',
                    '/source_commit',
                    'source_commit must be a 40-character lowercase hex SHA'
                );
            }
            $producer = $manifest['producer'] ?? null;
            if (!is_array($producer)
                || trim((string)($producer['name'] ?? '')) === ''
                || trim((string)($producer['version'] ?? '')) === '') {
                $result['errors'][] = self::error(
                    'invalid_producer',
                    '/producer',
                    'producer must include non-empty name and version'
                );
            }
        }

        $strictIntegrity = (bool)($integrityOptions['strict_integrity'] ?? false);
        $repoRoot = (string)($integrityOptions['repo_root'] ?? '');
        $maxAgeSeconds = max(0, (int)($integrityOptions['max_age_seconds'] ?? 86400));
        $freshnessNow = (string)($integrityOptions['freshness_now_utc'] ?? '');
        foreach ([self::PRIMARY_SOURCE_KEY, self::SECONDARY_SOURCE_KEY] as $sourceName) {
            $source = (array)($manifest['sources'][$sourceName] ?? []);
            $resolved = (array)($resolvedSources[$sourceName] ?? []);
            $path = '/sources/' . $sourceName;

            if ((bool)($integrityOptions['validate_provenance'] ?? false)) {
                $provenance = (array)($source['provenance'] ?? []);
                $originPath = trim((string)($provenance['origin_path'] ?? ''));
                $originChecksum = trim((string)($provenance['origin_checksum_sha256'] ?? ''));
                $status = 'ok';
                if ($originPath === '') {
                    $status = 'missing_origin_path';
                    $result[$strictIntegrity ? 'errors' : 'warnings'][] = self::error(
                        'provenance_origin_missing',
                        $path . '/provenance/origin_path',
                        'provenance.origin_path is required'
                    );
                }

                if ($originChecksum !== '' && preg_match('/^[0-9a-f]{64}$/', $originChecksum) !== 1) {
                    $status = 'invalid_origin_checksum';
                    $result[$strictIntegrity ? 'errors' : 'warnings'][] = self::error(
                        'invalid_origin_checksum',
                        $path . '/provenance/origin_checksum_sha256',
                        'provenance.origin_checksum_sha256 must be lowercase hex sha256'
                    );
                }

                if ($originPath !== '' && $repoRoot !== '') {
                    $absoluteOrigin = self::resolvePath($originPath, $repoRoot);
                    if (!is_file($absoluteOrigin)) {
                        $status = 'origin_missing';
                        $result[$strictIntegrity ? 'errors' : 'warnings'][] = self::error(
                            'provenance_origin_missing_file',
                            $path . '/provenance/origin_path',
                            'Origin file not found: ' . $originPath
                        );
                    } elseif ($originChecksum !== '') {
                        $actual = strtolower((string)(hash_file('sha256', $absoluteOrigin) ?: ''));
                        if ($actual !== strtolower($originChecksum)) {
                            $status = 'origin_checksum_mismatch';
                            $result[$strictIntegrity ? 'errors' : 'warnings'][] = self::error(
                                'provenance_origin_checksum_mismatch',
                                $path . '/provenance/origin_checksum_sha256',
                                'Origin checksum mismatch'
                            );
                        }
                    }
                }
                $result['provenance'][$sourceName] = ['status' => $status];
            }

            if ((bool)($integrityOptions['validate_checksums'] ?? false)) {
                $expected = strtolower(trim((string)($source['checksum_sha256'] ?? '')));
                $status = 'ok';
                if ($expected === '' || preg_match('/^[0-9a-f]{64}$/', $expected) !== 1) {
                    $status = 'missing_or_invalid_expected';
                    $result[$strictIntegrity ? 'errors' : 'warnings'][] = self::error(
                        'missing_or_invalid_checksum',
                        $path . '/checksum_sha256',
                        'checksum_sha256 must be a 64-character lowercase hex string'
                    );
                } else {
                    $resolvedPath = (string)($resolved['resolved_path'] ?? '');
                    if ($resolvedPath === '' || !is_file($resolvedPath)) {
                        $status = 'artifact_missing';
                        $result[$strictIntegrity ? 'errors' : 'warnings'][] = self::error(
                            'artifact_missing_for_checksum',
                            $path . '/path',
                            'Artifact missing for checksum validation'
                        );
                    } else {
                        $actual = strtolower((string)(hash_file('sha256', $resolvedPath) ?: ''));
                        if ($actual !== $expected) {
                            $status = 'checksum_mismatch';
                            $result[$strictIntegrity ? 'errors' : 'warnings'][] = self::error(
                                'checksum_mismatch',
                                $path . '/checksum_sha256',
                                'checksum_sha256 does not match artifact contents'
                            );
                        }
                    }
                }
                $result['checksums'][$sourceName] = ['status' => $status];
            }
        }

        if ((bool)($integrityOptions['validate_freshness'] ?? false)) {
            $generatedAtUtc = (string)($manifest['generated_at_utc'] ?? '');
            $generatedTs = strtotime($generatedAtUtc);
            $manifestMtime = @filemtime($manifestPath);
            $nowTs = $freshnessNow !== '' ? strtotime($freshnessNow) : time();

            if ($generatedTs === false || $nowTs === false) {
                $result['freshness'] = ['status' => 'invalid_timestamp'];
                $result[$strictIntegrity ? 'errors' : 'warnings'][] = self::error(
                    'invalid_generated_at_utc',
                    '/generated_at_utc',
                    'generated_at_utc must be a valid UTC timestamp'
                );
            } else {
                $status = 'fresh';
                $reasons = [];
                if (($nowTs - $generatedTs) > $maxAgeSeconds) {
                    $status = 'stale';
                    $reasons[] = 'stale_by_age';
                }
                if (is_int($manifestMtime)) {
                    foreach ([self::PRIMARY_SOURCE_KEY, self::SECONDARY_SOURCE_KEY] as $sourceName) {
                        $source = (array)($manifest['sources'][$sourceName] ?? []);
                        $provenance = (array)($source['provenance'] ?? []);
                        $originPath = trim((string)($provenance['origin_path'] ?? ''));
                        if ($originPath === '' || $repoRoot === '') {
                            continue;
                        }
                        $absoluteOrigin = self::resolvePath($originPath, $repoRoot);
                        $originMtime = @filemtime($absoluteOrigin);
                        if (is_int($originMtime) && $originMtime > $manifestMtime) {
                            $status = 'stale';
                            $reasons[] = 'stale_by_source_newer';
                            break;
                        }
                    }
                }
                $result['freshness'] = [
                    'status' => $status,
                    'reasons' => array_values(array_unique($reasons)),
                    'max_age_seconds' => $maxAgeSeconds,
                ];
                if ($status !== 'fresh') {
                    $result[$strictIntegrity ? 'errors' : 'warnings'][] = self::error(
                        'manifest_stale',
                        '/generated_at_utc',
                        'Manifest freshness check failed: ' . implode(', ', $reasons)
                    );
                }
            }
        }

        return $result;
    }

    private static function resolvePath(string $path, string $repoRoot): string
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
        return rtrim($repoRoot, "\\/") . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $trimmed);
    }

    /**
     * @return array{code: string, path: string, message: string}
     */
    private static function error(string $code, string $path, string $message): array
    {
        return [
            'code' => $code,
            'path' => $path,
            'message' => $message,
        ];
    }
}
