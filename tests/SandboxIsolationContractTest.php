<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/sandbox/SandboxRegistry.php';
require_once __DIR__ . '/../scripts/sandbox/SandboxArtifactWriter.php';
require_once __DIR__ . '/../scripts/sandbox/SandboxBoundaryEnforcer.php';

class SandboxIsolationContractTest extends TestCase
{
    /** @var string[] Session directories created during this test class run, to clean up. */
    private array $sessionDirsToClean = [];

    /** @var array<string,int> Snapshot of file counts in live output dirs before each test. */
    private array $liveOutputFileCountsBefore = [];

    private const LIVE_OUTPUT_DIRS = [
        'simulation_output/season',
        'simulation_output/lifetime',
        'simulation_output/promotion',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Snapshot file counts in live output dirs
        $this->liveOutputFileCountsBefore = [];
        foreach (self::LIVE_OUTPUT_DIRS as $dir) {
            $this->liveOutputFileCountsBefore[$dir] = $this->countFilesInDir($dir);
        }
    }

    protected function tearDown(): void
    {
        // Clean up any session directories created by this test
        foreach ($this->sessionDirsToClean as $dir) {
            $this->removeDir($dir);
        }
        $this->sessionDirsToClean = [];

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helper: count files in a directory (0 if dir does not exist)
    // -------------------------------------------------------------------------

    private function countFilesInDir(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $count = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $count++;
            }
        }
        return $count;
    }

    // -------------------------------------------------------------------------
    // Helper: recursively remove a directory
    // -------------------------------------------------------------------------

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    // -------------------------------------------------------------------------
    // Helper: make a unique test session ID and register it for cleanup
    // -------------------------------------------------------------------------

    private function makeSessionId(): string
    {
        $sessionId = 'phase0-isolation-' . uniqid();
        $this->sessionDirsToClean[] = SandboxArtifactWriter::sessionDir($sessionId);
        return $sessionId;
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    public function testMinimalSessionStartAndStop(): void
    {
        $sessionId = $this->makeSessionId();

        // Start the session
        $manifest = SandboxRegistry::startSession($sessionId);

        $this->assertIsArray($manifest, 'startSession() should return an array manifest');
        $this->assertSame('running', $manifest['state'], 'Manifest state should be running after startSession()');

        // Session should now exist
        $this->assertTrue(
            SandboxRegistry::sessionExists($sessionId),
            'sessionExists() should return true after startSession()'
        );

        // End the session
        SandboxRegistry::endSession($sessionId);

        // Load manifest and assert state is complete
        $finalManifest = SandboxRegistry::getSession($sessionId);
        $this->assertSame('complete', $finalManifest['state'], 'Manifest state should be complete after endSession()');
    }

    public function testManifestPathIsInsideSandboxDir(): void
    {
        $sessionId = $this->makeSessionId();

        SandboxRegistry::startSession($sessionId);

        // The manifest should be at the expected path
        $expectedManifestPath = 'simulation_output/sandbox/sessions/' . $sessionId . '/session_manifest.json';

        $this->assertFileExists(
            $expectedManifestPath,
            'session_manifest.json should exist at the expected sandbox path'
        );

        // The path must start with the session-specific sandbox prefix
        $expectedPrefix = 'simulation_output/sandbox/sessions/' . $sessionId . '/';
        $this->assertTrue(
            str_starts_with($expectedManifestPath, $expectedPrefix),
            "Manifest path '{$expectedManifestPath}' should start with '{$expectedPrefix}'"
        );

        // Load and inspect manifest — artifact_paths should be empty after plain start (no mechanic)
        $manifest = SandboxRegistry::getSession($sessionId);
        $this->assertSame(
            [],
            $manifest['artifact_paths'],
            'artifact_paths should be empty after a plain startSession() with no mechanic'
        );

        SandboxRegistry::endSession($sessionId);
    }

    public function testNoArtifactsWrittenToLiveOutputDirs(): void
    {
        $sessionId = $this->makeSessionId();

        // Start and end a sandbox session
        SandboxRegistry::startSession($sessionId);
        SandboxRegistry::endSession($sessionId);

        // Re-count files in live output dirs — none should have changed
        foreach (self::LIVE_OUTPUT_DIRS as $dir) {
            $countBefore = $this->liveOutputFileCountsBefore[$dir];
            $countAfter  = $this->countFilesInDir($dir);

            $this->assertSame(
                $countBefore,
                $countAfter,
                "File count in live output dir '{$dir}' changed from {$countBefore} to {$countAfter}. "
                . "Sandbox session must not write to live output directories."
            );
        }
    }

    public function testArtifactWriterEnforcesSessionBoundary(): void
    {
        $sessionId = $this->makeSessionId();

        SandboxRegistry::startSession($sessionId);

        // Write a valid artifact inside the sandbox session dir
        $artifactPath = SandboxArtifactWriter::sessionDir($sessionId) . '/test_artifact.json';
        SandboxArtifactWriter::write($artifactPath, ['test' => true], $sessionId);

        $this->assertFileExists(
            $artifactPath,
            'Artifact should exist after writing inside the sandbox session dir'
        );

        $expectedPrefix = 'simulation_output/sandbox/sessions/' . $sessionId . '/';
        $this->assertTrue(
            str_starts_with($artifactPath, $expectedPrefix),
            "Artifact path '{$artifactPath}' should start with '{$expectedPrefix}'"
        );

        // Attempt to write outside sandbox — should throw
        $evilPath = 'simulation_output/season/evil.json';

        $exceptionThrown = false;
        try {
            SandboxArtifactWriter::write($evilPath, ['evil' => true], $sessionId);
        } catch (SandboxBoundaryViolationException $e) {
            $exceptionThrown = true;
        }

        $this->assertTrue(
            $exceptionThrown,
            'SandboxBoundaryViolationException should be thrown when writing outside the sandbox boundary'
        );

        $this->assertFileDoesNotExist(
            $evilPath,
            'No file should have been written to the live season output dir'
        );

        SandboxRegistry::endSession($sessionId);
    }

    public function testSessionIdCollisionThrows(): void
    {
        $sessionId = $this->makeSessionId();

        // Start the session once
        SandboxRegistry::startSession($sessionId);

        // Starting the same session again should throw with 'session_id_collision'
        $exceptionThrown = false;
        $violationType   = null;
        try {
            SandboxRegistry::startSession($sessionId);
        } catch (SandboxBoundaryViolationException $e) {
            $exceptionThrown = true;
            $violationType   = $e->violationType();
        }

        $this->assertTrue(
            $exceptionThrown,
            'SandboxBoundaryViolationException should be thrown on duplicate session ID'
        );
        $this->assertSame(
            'session_id_collision',
            $violationType,
            "violationType() should be 'session_id_collision' on duplicate session start"
        );

        SandboxRegistry::endSession($sessionId);
    }
}
