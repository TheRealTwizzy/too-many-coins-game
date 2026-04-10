<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/simulation/PlayabilityGateValidator.php';

/**
 * Milestone 5B — Playability gate validation tests.
 *
 * Validates that the PlayabilityGateValidator produces coherent, machine-readable
 * results and that all economy gates pass with default simulation season config.
 */
class PlayabilityGateValidatorTest extends TestCase
{
    /**
     * Economy-only gates must all pass with no run artifact.
     */
    public function testEconomyGatesAllPassWithoutArtifact(): void
    {
        $results = PlayabilityGateValidator::evaluate('gate-test', null);

        $this->assertSame('tmc-playability-gates.v1', $results['schema_version']);
        $this->assertNotEmpty($results['gates']);

        // Economy gates should pass; lifecycle gates should be SKIP.
        foreach ($results['gates'] as $gate) {
            if ($gate['result'] === 'SKIP') {
                // Lifecycle gates are skipped without artifact — expected.
                continue;
            }
            $this->assertSame('PASS', $gate['result'],
                "Gate '{$gate['gate']}' failed: {$gate['detail']}");
        }
    }

    /**
     * Results must indicate release is not blocked when only economy gates are evaluated.
     */
    public function testReleaseNotBlockedWithPassingEconomyGates(): void
    {
        $results = PlayabilityGateValidator::evaluate('gate-test', null);

        // SKIP gates are not failures, so release should not be blocked.
        $this->assertFalse($results['release_blocked']);
        $this->assertSame(0, $results['summary']['critical_failures']);
    }

    /**
     * Lifecycle gates should pass when given a valid completed artifact.
     */
    public function testLifecycleGatesPassWithValidArtifact(): void
    {
        $artifact = [
            'termination' => [
                'status' => 'completed',
                'reason' => 'season_finalized',
            ],
            'execution_metrics' => [
                'players_joined' => 100,
                'players_join_failed' => 0,
            ],
            'metadata' => [
                'season' => [
                    'extra_season_ids' => [],
                ],
            ],
            'determinism_fingerprint' => str_repeat('a', 64),
        ];

        $results = PlayabilityGateValidator::evaluate('gate-test', $artifact);

        $this->assertFalse($results['release_blocked']);
        $this->assertSame(0, $results['summary']['fail']);

        foreach ($results['gates'] as $gate) {
            $this->assertContains($gate['result'], ['PASS', 'WARN'],
                "Gate '{$gate['gate']}' unexpected result: {$gate['result']} — {$gate['detail']}");
        }
    }

    /**
     * Failed lifecycle must result in release-blocking critical failure.
     */
    public function testFailedLifecycleBlocksRelease(): void
    {
        $artifact = [
            'termination' => [
                'status' => 'failed',
                'reason' => 'error',
            ],
            'execution_metrics' => [
                'players_joined' => 0,
                'players_join_failed' => 10,
            ],
            'metadata' => [
                'season' => [
                    'extra_season_ids' => [],
                ],
            ],
            'determinism_fingerprint' => 'short',
        ];

        $results = PlayabilityGateValidator::evaluate('gate-test', $artifact);

        $this->assertTrue($results['release_blocked']);
        $this->assertGreaterThan(0, $results['summary']['critical_failures']);
    }

    /**
     * Gate results must be JSON-serializable.
     */
    public function testGateResultsAreJsonSerializable(): void
    {
        $results = PlayabilityGateValidator::evaluate('json-test', null);
        $json = json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $this->assertNotFalse($json, 'Gate results must produce valid JSON');
        $decoded = json_decode($json, true);
        $this->assertSame($results['summary'], $decoded['summary']);
    }
}
