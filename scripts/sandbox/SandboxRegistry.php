<?php

declare(strict_types=1);

require_once __DIR__ . '/SandboxBoundaryViolationException.php';
require_once __DIR__ . '/SandboxSessionManifest.php';
require_once __DIR__ . '/SandboxArtifactWriter.php';
require_once __DIR__ . '/../../scripts/optimization/AgenticOptimization.php';

class SandboxRegistry
{
    /**
     * Starts a new sandbox session.
     *
     * - Validates the session ID format ([a-z0-9_-]+).
     * - Asserts the session does not already exist.
     * - Creates the session directory tree.
     * - Writes an initial manifest in the 'running' state.
     *
     * @param string $sessionId A lowercase alphanumeric/dash/underscore session identifier.
     * @param array  $options   Optional initial options stored in the manifest.
     *
     * @return array The session manifest array.
     *
     * @throws \InvalidArgumentException if $sessionId contains invalid characters.
     * @throws SandboxBoundaryViolationException if the session already exists.
     */
    public static function startSession(string $sessionId, array $options = []): array
    {
        // Validate session ID format
        if (!preg_match('/^[a-z0-9_-]+$/', $sessionId)) {
            throw new \InvalidArgumentException(
                "Invalid sandbox session ID '{$sessionId}'. "
                . "Session IDs must match [a-z0-9_-]+."
            );
        }

        $sessionDir = 'simulation_output/sandbox/sessions/' . $sessionId;

        // Guard against session ID collisions
        if (is_dir($sessionDir)) {
            throw new SandboxBoundaryViolationException(
                "Sandbox session '{$sessionId}' already exists at '{$sessionDir}'. "
                . "Use a unique session ID to avoid state collisions.",
                'session_id_collision'
            );
        }

        // Create session directory and standard subdirectories
        AgenticOptimizationUtils::ensureDir($sessionDir . '/stage1');
        AgenticOptimizationUtils::ensureDir($sessionDir . '/stage2');
        AgenticOptimizationUtils::ensureDir($sessionDir . '/mechanics');
        AgenticOptimizationUtils::ensureDir($sessionDir . '/approval');

        // Build manifest and immediately transition to 'running'
        $manifest = SandboxSessionManifest::create($sessionId, $options);
        $manifest = SandboxSessionManifest::transitionState($manifest, 'running');

        // Persist manifest
        $manifestPath = $sessionDir . '/' . SandboxSessionManifest::MANIFEST_FILENAME;
        AgenticOptimizationUtils::writeJson($manifestPath, $manifest);

        return $manifest;
    }

    /**
     * Ends a sandbox session by transitioning its state to 'complete'.
     *
     * @param string $sessionId The session to close.
     *
     * @throws \RuntimeException if the session is not found.
     * @throws SandboxBoundaryViolationException if the current state does not permit the transition.
     */
    public static function endSession(string $sessionId): void
    {
        $manifest = self::loadManifest($sessionId);
        $manifest = SandboxSessionManifest::transitionState($manifest, 'complete');
        self::saveManifest($sessionId, $manifest);
    }

    /**
     * Returns the manifest array for an existing session.
     *
     * @param string $sessionId The session to look up.
     *
     * @return array The session manifest.
     *
     * @throws \RuntimeException if the session does not exist.
     */
    public static function getSession(string $sessionId): array
    {
        return self::loadManifest($sessionId);
    }

    /**
     * Returns true if the session directory exists.
     *
     * @param string $sessionId The session to check.
     */
    public static function sessionExists(string $sessionId): bool
    {
        $sessionDir = 'simulation_output/sandbox/sessions/' . $sessionId;
        return is_dir($sessionDir);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Reads and decodes the session_manifest.json for the given session.
     *
     * @throws \RuntimeException if the manifest file is missing or contains invalid JSON.
     */
    private static function loadManifest(string $sessionId): array
    {
        $manifestPath = 'simulation_output/sandbox/sessions/' . $sessionId
            . '/' . SandboxSessionManifest::MANIFEST_FILENAME;

        if (!is_file($manifestPath)) {
            throw new \RuntimeException(
                "Sandbox session manifest not found for session '{$sessionId}' at path '{$manifestPath}'."
            );
        }

        $raw = file_get_contents($manifestPath);
        if ($raw === false) {
            throw new \RuntimeException(
                "Failed to read sandbox session manifest for session '{$sessionId}' at path '{$manifestPath}'."
            );
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException(
                "Sandbox session manifest for session '{$sessionId}' contains invalid JSON at path '{$manifestPath}'."
            );
        }

        return $decoded;
    }

    /**
     * Writes the given manifest array back to disk for the given session.
     */
    private static function saveManifest(string $sessionId, array $manifest): void
    {
        $manifestPath = 'simulation_output/sandbox/sessions/' . $sessionId
            . '/' . SandboxSessionManifest::MANIFEST_FILENAME;

        AgenticOptimizationUtils::writeJson($manifestPath, $manifest);
    }
}
