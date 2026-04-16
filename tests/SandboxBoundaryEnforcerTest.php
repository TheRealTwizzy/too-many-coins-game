<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/sandbox/SandboxBoundaryEnforcer.php';

class SandboxBoundaryEnforcerTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Stage enforcement
    // -------------------------------------------------------------------------

    public function testStage6ThrowsViolation(): void
    {
        $this->expectException(SandboxBoundaryViolationException::class);

        try {
            SandboxBoundaryEnforcer::assertSandboxStageAllowed(6);
        } catch (SandboxBoundaryViolationException $e) {
            $this->assertSame('stage_not_permitted', $e->violationType());
            throw $e;
        }
    }

    public function testStage7ThrowsViolation(): void
    {
        $this->expectException(SandboxBoundaryViolationException::class);

        try {
            SandboxBoundaryEnforcer::assertSandboxStageAllowed(7);
        } catch (SandboxBoundaryViolationException $e) {
            $this->assertSame('stage_not_permitted', $e->violationType());
            throw $e;
        }
    }

    public function testStages1Through5AreAllowed(): void
    {
        // None of these should throw
        foreach ([1, 2, 3, 4, 5] as $stage) {
            SandboxBoundaryEnforcer::assertSandboxStageAllowed($stage);
        }

        // If we get here, the assertion is satisfied
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // Path boundary enforcement
    // -------------------------------------------------------------------------

    public function testPathOutsideSandboxThrowsViolation(): void
    {
        $this->expectException(SandboxBoundaryViolationException::class);

        try {
            SandboxBoundaryEnforcer::assertSandboxOutputDir(
                'simulation_output/season/something.json',
                'test-session'
            );
        } catch (SandboxBoundaryViolationException $e) {
            $this->assertSame('path_outside_sandbox', $e->violationType());
            throw $e;
        }
    }

    public function testPathInsideSandboxAllowed(): void
    {
        // Should not throw
        SandboxBoundaryEnforcer::assertSandboxOutputDir(
            'simulation_output/sandbox/sessions/test-session/results.json',
            'test-session'
        );

        $this->assertTrue(true);
    }

    public function testPathTraversalThrowsViolation(): void
    {
        // When a traversal path resolves via realpath() to a location outside the sandbox
        // session directory, SandboxBoundaryEnforcer must throw.
        //
        // realpath() requires ALL intermediate path components to exist on disk to resolve
        // ".." segments. So we must create:
        //   1. The sandbox session directory (so realpath can walk into and back out of it).
        //   2. The traversal-target file in simulation_output/season/ (so realpath resolves it).
        //
        // The string-normalization fallback (used when the file does NOT exist) does NOT
        // collapse ".." segments, so a non-existent traversal path whose raw string still
        // begins with the session prefix would slip through. This test exercises the
        // realpath()-based guard, which is the reliable path-escape defence.

        $sessionId  = 'test-traversal-' . uniqid();
        $sessionDir = 'simulation_output/sandbox/sessions/' . $sessionId;
        $tempFile   = 'simulation_output/season/__traversal_test_' . uniqid() . '.json';

        // Create the session directory so realpath can resolve the traversal path
        mkdir($sessionDir, 0777, true);
        file_put_contents($tempFile, '{}');

        try {
            // Build a traversal path: starts inside the session dir but escapes via ".."
            // into simulation_output/season/
            // From sessions/<id>/ we need 3 levels up to reach simulation_output/
            $traversalPath = $sessionDir . '/../../../season/' . basename($tempFile);

            $this->expectException(SandboxBoundaryViolationException::class);

            try {
                SandboxBoundaryEnforcer::assertSandboxOutputDir($traversalPath, $sessionId);
            } catch (SandboxBoundaryViolationException $e) {
                $this->assertSame('path_outside_sandbox', $e->violationType());
                throw $e;
            }
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
            // Remove the temporary session dir tree
            if (is_dir($sessionDir)) {
                rmdir($sessionDir);
            }
            // Clean up parent dirs only if empty
            $sessionsDir = 'simulation_output/sandbox/sessions';
            $sandboxDir  = 'simulation_output/sandbox';
            if (is_dir($sessionsDir) && count(array_diff(scandir($sessionsDir), ['.', '..'])) === 0) {
                rmdir($sessionsDir);
            }
            if (is_dir($sandboxDir) && count(array_diff(scandir($sandboxDir), ['.', '..'])) === 0) {
                rmdir($sandboxDir);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Live candidate coupling enforcement
    // -------------------------------------------------------------------------

    public function testLiveCandidateCouplingThrowsViolation(): void
    {
        $this->expectException(SandboxBoundaryViolationException::class);

        try {
            SandboxBoundaryEnforcer::assertNoCandidateLiveCoupling([
                'debug_allow_inactive_candidate' => true,
            ]);
        } catch (SandboxBoundaryViolationException $e) {
            $this->assertSame('live_candidate_coupling', $e->violationType());
            throw $e;
        }
    }

    public function testLivePrefixKeyThrowsViolation(): void
    {
        $this->expectException(SandboxBoundaryViolationException::class);

        try {
            SandboxBoundaryEnforcer::assertNoCandidateLiveCoupling([
                'live_mode' => 'enabled',
            ]);
        } catch (SandboxBoundaryViolationException $e) {
            $this->assertSame('live_candidate_coupling', $e->violationType());
            throw $e;
        }
    }

    public function testCleanCandidatePatchAllowed(): void
    {
        // Should not throw
        SandboxBoundaryEnforcer::assertNoCandidateLiveCoupling([
            'base_ubi_active_per_tick' => 500000,
        ]);

        $this->assertTrue(true);
    }
}
