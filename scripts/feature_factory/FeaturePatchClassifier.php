<?php

require_once __DIR__ . '/FeatureFactoryException.php';

class FeaturePatchClassifier
{
    private const BLOCKED_PRE_APPROVAL = ['runtime', 'api', 'frontend', 'database', 'deployment', 'unknown'];

    public static function classifyPaths(array $paths): array
    {
        $rows = [];
        foreach ($paths as $path) {
            $normalized = self::normalizePath((string)$path);
            $rows[] = [
                'path' => $normalized,
                'class' => self::classifyPath($normalized),
            ];
        }

        return [
            'schema_version' => 'tmc-feature-patch-classification.v1',
            'paths' => $rows,
            'blocked_pre_approval' => self::blockedPreApproval(['paths' => $rows]),
        ];
    }

    public static function blockedPreApproval(array $classification): array
    {
        $blocked = [];
        foreach ((array)($classification['paths'] ?? []) as $row) {
            if (in_array((string)($row['class'] ?? 'unknown'), self::BLOCKED_PRE_APPROVAL, true)) {
                $blocked[] = $row;
            }
        }
        return $blocked;
    }

    public static function assertPreApprovalAllowed(array $classification): void
    {
        $blocked = self::blockedPreApproval($classification);
        if ($blocked !== []) {
            throw new FeatureFactoryException('Feature factory pre-approval guard blocked player-facing or unsafe paths.', $blocked);
        }
    }

    private static function classifyPath(string $path): string
    {
        if ($path === '' || str_contains($path, '..')) {
            return 'unknown';
        }
        if (preg_match('#^(simulation_output/feature_factory/|docs/superpowers/|tmp/feature_factory/)#', $path)) {
            return 'scaffolding';
        }
        if (preg_match('#^(scripts/simulation/|scripts/optimization/|tools/export-season-config\.php|scripts/lint_candidate_packages\.php)#', $path)) {
            return 'mechanic_contract';
        }
        if (preg_match('#^(includes/)#', $path)) {
            return 'runtime';
        }
        if ($path === 'api/index.php' || str_starts_with($path, 'api/')) {
            return 'api';
        }
        if (preg_match('#^(public/).*\.(js|css|html)$#', $path)) {
            return 'frontend';
        }
        if (preg_match('#^(migration_.*\.sql|schema\.sql|seed_data\.sql|init_db\.php)$#', $path)) {
            return 'database';
        }
        if (preg_match('#^(\.github/|docker/|Dockerfile|docker-compose\.yml|DEPLOY_|setup\.sh)#', $path)) {
            return 'deployment';
        }
        return 'unknown';
    }

    private static function normalizePath(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
    }
}
