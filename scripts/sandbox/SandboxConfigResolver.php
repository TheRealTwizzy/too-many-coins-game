<?php

declare(strict_types=1);

require_once __DIR__ . '/SandboxBoundaryViolationException.php';
require_once __DIR__ . '/SandboxArtifactWriter.php';
require_once __DIR__ . '/../../scripts/simulation/SimulationConfigPreflight.php';
require_once __DIR__ . '/../../scripts/simulation/CanonicalEconomyConfigContract.php';

class SandboxConfigResolver
{
    /**
     * Resolves a simulation config for use inside a sandbox session.
     *
     * Steps:
     *  1. Blocks debug_allow_inactive_candidate=true (throws with type 'debug_bypass_not_allowed').
     *  2. Overrides artifact_dir to the sandbox session's stage2 subdirectory.
     *  3. Strips candidate_patch keys that are not in CanonicalEconomyConfigContract::patchableParameters().
     *  4. Defaults simulator to 'sandbox' if not provided.
     *  5. Delegates to SimulationConfigPreflight::resolve().
     *  6. Injects sandbox_meta into the returned result.
     *
     * @param array  $options    The raw options array (same shape as SimulationConfigPreflight::resolve()).
     * @param string $sessionId  The active sandbox session ID.
     * @param string $mechanicId Optional mechanic identifier; appended as a subdirectory of stage2/.
     *
     * @return array The preflight result with an additional 'sandbox_meta' key.
     *
     * @throws SandboxBoundaryViolationException if debug bypass is enabled.
     * @throws SimulationConfigPreflightException if the preflight fails.
     */
    public static function resolve(array $options, string $sessionId, string $mechanicId = ''): array
    {
        // 1. Block debug bypass
        if (!empty($options['debug_allow_inactive_candidate'])) {
            throw new SandboxBoundaryViolationException(
                "debug_allow_inactive_candidate is not permitted in sandbox sessions. "
                . "Remove this flag before running the sandbox resolver.",
                'debug_bypass_not_allowed'
            );
        }

        // 2. Override artifact_dir to the sandbox stage2 directory
        $artifactDir = SandboxArtifactWriter::sessionDir($sessionId) . '/stage2/';
        if ($mechanicId !== '') {
            $artifactDir .= $mechanicId . '/';
        }

        $sandboxOptions = $options;
        $sandboxOptions['artifact_dir'] = $artifactDir;

        // 3. Strip candidate_patch keys that are not in patchableParameters()
        $strippedKeys = [];
        if (isset($sandboxOptions['candidate_patch']) && is_array($sandboxOptions['candidate_patch'])) {
            $allowedPatchKeys = array_fill_keys(CanonicalEconomyConfigContract::patchableParameters(), true);
            $filteredPatch = [];
            foreach ($sandboxOptions['candidate_patch'] as $key => $value) {
                if (isset($allowedPatchKeys[$key])) {
                    $filteredPatch[$key] = $value;
                } else {
                    $strippedKeys[] = $key;
                }
            }
            $sandboxOptions['candidate_patch'] = $filteredPatch;
        }

        // 4. Default simulator to 'sandbox'
        if (!isset($sandboxOptions['simulator']) || $sandboxOptions['simulator'] === '') {
            $sandboxOptions['simulator'] = 'sandbox';
        }

        // 5. Delegate to SimulationConfigPreflight
        $result = SimulationConfigPreflight::resolve($sandboxOptions);

        // 6. Inject sandbox_meta into the result
        $result['sandbox_meta'] = [
            'session_id'   => $sessionId,
            'mechanic_id'  => $mechanicId,
            'stripped_keys' => $strippedKeys,
            'sandbox_stage' => 2,
        ];

        return $result;
    }
}
