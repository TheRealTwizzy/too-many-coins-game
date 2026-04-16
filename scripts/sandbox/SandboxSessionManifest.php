<?php

declare(strict_types=1);

require_once __DIR__ . '/SandboxBoundaryViolationException.php';
require_once __DIR__ . '/../../scripts/optimization/AgenticOptimization.php';

class SandboxSessionManifest
{
    public const SCHEMA_VERSION = 'tmc-sandbox-session-manifest.v1';
    public const MANIFEST_FILENAME = 'session_manifest.json';

    /**
     * Valid state machine transitions for sandbox sessions.
     * Key = from-state, value = allowed to-states.
     */
    private const VALID_TRANSITIONS = [
        'created'  => ['running'],
        'running'  => ['complete'],
        'complete' => ['approved'],
    ];

    /**
     * Creates a new session manifest array.
     *
     * The options array is sanitized: debug_allow_inactive_candidate=true is stripped.
     */
    public static function create(string $sessionId, array $options = []): array
    {
        // Sanitize options — strip the debug bypass flag if set to true
        $sanitizedOptions = $options;
        if (isset($sanitizedOptions['debug_allow_inactive_candidate'])
            && $sanitizedOptions['debug_allow_inactive_candidate'] === true
        ) {
            unset($sanitizedOptions['debug_allow_inactive_candidate']);
        }

        $now = date('c');

        return [
            'schema_version'           => self::SCHEMA_VERSION,
            'session_id'               => $sessionId,
            'state'                    => 'created',
            'created_at'               => $now,
            'updated_at'               => $now,
            'options'                  => $sanitizedOptions,
            'artifact_paths'           => [],
            'baseline_snapshot_hash'   => null,
            'provenance'               => [
                'created_by' => 'SandboxRegistry',
                'phase'      => 0,
            ],
        ];
    }

    /**
     * Merges $changes into $manifest, always refreshing updated_at.
     */
    public static function update(array $manifest, array $changes): array
    {
        $updated = array_merge($manifest, $changes);
        $updated['updated_at'] = date('c');
        return $updated;
    }

    /**
     * Transitions the session state according to the valid state machine.
     *
     * Valid transitions:
     *   created  → running
     *   running  → complete
     *   complete → approved
     *
     * Throws SandboxBoundaryViolationException with type 'invalid_state_transition' if the
     * requested transition is not valid from the current state.
     */
    public static function transitionState(array $manifest, string $newState): array
    {
        $currentState = (string)($manifest['state'] ?? '');
        $allowedNextStates = self::VALID_TRANSITIONS[$currentState] ?? [];

        if (!in_array($newState, $allowedNextStates, true)) {
            throw new SandboxBoundaryViolationException(
                "Invalid state transition: cannot move from '{$currentState}' to '{$newState}'. "
                . "Allowed transitions from '{$currentState}': "
                . (empty($allowedNextStates) ? 'none' : implode(', ', $allowedNextStates)) . '.',
                'invalid_state_transition'
            );
        }

        return self::update($manifest, ['state' => $newState]);
    }

    /**
     * Appends $path to the manifest's artifact_paths list and refreshes updated_at.
     */
    public static function addArtifactPath(array $manifest, string $path): array
    {
        $artifactPaths = (array)($manifest['artifact_paths'] ?? []);
        $artifactPaths[] = $path;

        return self::update($manifest, ['artifact_paths' => $artifactPaths]);
    }

    /**
     * Returns the absolute path to the manifest file inside the given session directory.
     */
    public static function manifestPath(string $sessionDir): string
    {
        return $sessionDir . '/' . self::MANIFEST_FILENAME;
    }
}
