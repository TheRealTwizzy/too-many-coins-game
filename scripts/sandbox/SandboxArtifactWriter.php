<?php

declare(strict_types=1);

require_once __DIR__ . '/SandboxBoundaryViolationException.php';
require_once __DIR__ . '/SandboxBoundaryEnforcer.php';
require_once __DIR__ . '/../../scripts/optimization/AgenticOptimization.php';

class SandboxArtifactWriter
{
    /**
     * Writes a JSON-encoded array to the given path inside the sandbox session.
     *
     * Enforces that $path is within the expected sandbox session directory before writing.
     * Creates the parent directory if it does not already exist.
     *
     * @throws SandboxBoundaryViolationException if the path is outside the sandbox boundary.
     */
    public static function write(string $path, array $data, string $sessionId): void
    {
        SandboxBoundaryEnforcer::assertSandboxOutputDir($path, $sessionId);
        AgenticOptimizationUtils::ensureDir(dirname($path));
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Writes raw string content to the given path inside the sandbox session.
     *
     * Used for non-JSON artifacts such as Markdown reports.
     * Enforces the same path boundary as write().
     *
     * @throws SandboxBoundaryViolationException if the path is outside the sandbox boundary.
     */
    public static function writeRaw(string $path, string $content, string $sessionId): void
    {
        SandboxBoundaryEnforcer::assertSandboxOutputDir($path, $sessionId);
        AgenticOptimizationUtils::ensureDir(dirname($path));
        file_put_contents($path, $content);
    }

    /**
     * Returns the canonical sandbox session directory path for the given session ID.
     *
     * Example: sessionDir('abc-123') => 'simulation_output/sandbox/sessions/abc-123'
     */
    public static function sessionDir(string $sessionId): string
    {
        return 'simulation_output/sandbox/sessions/' . $sessionId;
    }
}
