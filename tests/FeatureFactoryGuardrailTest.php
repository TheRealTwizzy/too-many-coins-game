<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/feature_factory/FeaturePatchClassifier.php';
require_once __DIR__ . '/../scripts/feature_factory/ApprovalManifest.php';

class FeatureFactoryGuardrailTest extends TestCase
{
    public function testPreApprovalBlocksRuntimeApiFrontendDeploymentDatabaseAndUnknownPaths(): void
    {
        $classification = FeaturePatchClassifier::classifyPaths([
            'simulation_output/feature_factory/example/mechanic_brief.json',
            'scripts/simulation/RuntimeParityCertification.php',
            'includes/economy.php',
            'api/index.php',
            'public/js/app.js',
            'public/css/style.css',
            'Dockerfile',
            'migration_20260425_example.sql',
            'mystery/path.txt',
        ]);

        $this->assertSame('scaffolding', $classification['paths'][0]['class']);
        $this->assertSame('mechanic_contract', $classification['paths'][1]['class']);
        $this->assertSame('runtime', $classification['paths'][2]['class']);
        $this->assertSame('api', $classification['paths'][3]['class']);
        $this->assertSame('frontend', $classification['paths'][4]['class']);
        $this->assertSame('frontend', $classification['paths'][5]['class']);
        $this->assertSame('deployment', $classification['paths'][6]['class']);
        $this->assertSame('database', $classification['paths'][7]['class']);
        $this->assertSame('unknown', $classification['paths'][8]['class']);

        $blocked = FeaturePatchClassifier::blockedPreApproval($classification);
        $this->assertSame(
            ['includes/economy.php', 'api/index.php', 'public/js/app.js', 'public/css/style.css', 'Dockerfile', 'migration_20260425_example.sql', 'mystery/path.txt'],
            array_column($blocked, 'path')
        );
    }

    public function testApprovalManifestAllowsOnlyMatchingMechanicBundleClassAndPath(): void
    {
        $manifest = [
            'schema_version' => 'tmc-feature-approval.v1',
            'mechanic_id' => 'daily_streak_pressure',
            'bundle_hash' => 'abc123',
            'allowed_path_classes' => ['runtime'],
            'allowed_paths' => ['includes/economy.php'],
            'approval_reason' => 'Runtime implementation approved after scaffolding review.',
            'approver' => 'trent',
            'approved_at' => '2026-04-25T12:00:00Z',
        ];

        $failures = ApprovalManifest::validateForPaths(
            $manifest,
            'daily_streak_pressure',
            'abc123',
            FeaturePatchClassifier::classifyPaths(['includes/economy.php'])
        );

        $this->assertSame([], $failures);

        $failures = ApprovalManifest::validateForPaths(
            $manifest,
            'daily_streak_pressure',
            'abc123',
            FeaturePatchClassifier::classifyPaths(['public/js/app.js'])
        );

        $this->assertSame('approval_class_not_allowed', $failures[0]['reason_code']);
    }
}
