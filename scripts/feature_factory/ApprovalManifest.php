<?php

require_once __DIR__ . '/FeatureFactoryException.php';

class ApprovalManifest
{
    public static function example(string $mechanicId, string $bundleHash): array
    {
        return [
            'schema_version' => 'tmc-feature-approval.v1',
            'mechanic_id' => $mechanicId,
            'bundle_hash' => $bundleHash,
            'allowed_path_classes' => ['runtime'],
            'allowed_paths' => ['includes/economy.php'],
            'approval_reason' => 'Replace with the reviewed reason before use.',
            'approver' => 'human-reviewer',
            'approved_at' => gmdate('c'),
        ];
    }

    public static function validateForPaths(array $manifest, string $mechanicId, string $bundleHash, array $classification): array
    {
        $failures = [];

        if (($manifest['schema_version'] ?? '') !== 'tmc-feature-approval.v1') {
            $failures[] = self::failure('schema_version', 'approval_schema_mismatch', 'Approval schema version must be tmc-feature-approval.v1.');
        }
        if ((string)($manifest['mechanic_id'] ?? '') !== $mechanicId) {
            $failures[] = self::failure('mechanic_id', 'approval_mechanic_mismatch', 'Approval mechanic id does not match this bundle.');
        }
        if ((string)($manifest['bundle_hash'] ?? '') !== $bundleHash) {
            $failures[] = self::failure('bundle_hash', 'approval_bundle_hash_mismatch', 'Approval bundle hash does not match this bundle.');
        }
        foreach (['approval_reason', 'approver', 'approved_at'] as $field) {
            if (trim((string)($manifest[$field] ?? '')) === '') {
                $failures[] = self::failure($field, 'approval_required_field_missing', 'Approval field is required.');
            }
        }

        $allowedClasses = array_fill_keys(array_map('strval', (array)($manifest['allowed_path_classes'] ?? [])), true);
        $allowedPaths = array_fill_keys(array_map([self::class, 'normalizePath'], (array)($manifest['allowed_paths'] ?? [])), true);

        foreach ((array)($classification['paths'] ?? []) as $row) {
            $class = (string)($row['class'] ?? 'unknown');
            $path = self::normalizePath((string)($row['path'] ?? ''));
            if (!isset($allowedClasses[$class])) {
                $failures[] = self::failure($path, 'approval_class_not_allowed', 'Approval does not allow path class ' . $class . '.');
                continue;
            }
            if (!isset($allowedPaths[$path])) {
                $failures[] = self::failure($path, 'approval_path_not_allowed', 'Approval does not allow this path.');
            }
        }

        return $failures;
    }

    private static function failure(string $path, string $code, string $detail): array
    {
        return [
            'path' => $path,
            'reason_code' => $code,
            'reason_detail' => $detail,
        ];
    }

    private static function normalizePath(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
    }
}
