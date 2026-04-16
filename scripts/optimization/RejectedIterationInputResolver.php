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

        $normalized = [
            'schema_version' => (string)($manifest['schema_version'] ?? ''),
            'generated_at_utc' => (string)($manifest['generated_at_utc'] ?? ''),
            'source_commit' => (string)($manifest['source_commit'] ?? ''),
            'producer' => (string)($manifest['producer'] ?? ''),
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
    /**
     * @return array{
     *   ok: bool,
     *   manifest_valid: bool,
     *   errors: array<int, array<string, string>>,
     *   warnings: array<int, array<string, string>>,
     *   canonical_root: string,
     *   manifest: array<string, mixed>,
     *   resolved_sources: array<string, array<string, mixed>>,
     *   missing_sources: array<int, string>
     * }
     */
    public static function resolve(string $manifestPath, ?string $canonicalRoot = null, bool $strictUnknown = true): array
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

        foreach (['primary', 'secondary'] as $sourceName) {
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

        $result['ok'] = $result['errors'] === [];
        return $result;
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
