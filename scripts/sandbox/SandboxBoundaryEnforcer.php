<?php

declare(strict_types=1);

require_once __DIR__ . '/SandboxBoundaryViolationException.php';

class SandboxBoundaryEnforcer
{
    private const SANDBOX_STAGES = [1, 2, 3, 4, 5];
    private const BLOCKED_STAGES = [6, 7, 8, 9, 10];
    private const SANDBOX_ROOT = 'simulation_output/sandbox/sessions/';
    private const LIVE_OUTPUT_DIRS = [
        'simulation_output/season/',
        'simulation_output/lifetime/',
        'simulation_output/promotion/',
    ];

    /**
     * Asserts that the given path is inside the expected sandbox session directory
     * and does not point into any live output directory.
     *
     * Strategy:
     *   1. Try resolving the input path via realpath() (works when the file exists).
     *   2. If that fails, try resolving dirname($path) via realpath() to collapse any
     *      ".." traversal segments even when the target file does not yet exist.
     *   3. Wherever realpath() resolves something, also resolve the sandbox session dir
     *      via realpath() so both sides of the comparison use the same absolute space.
     *   4. When neither the path nor its parent can be resolved (e.g., no dirs created
     *      yet), fall back to a normalized relative-string prefix check.
     *
     * This approach handles Windows drive-letter absolute paths, UNC paths, and the
     * most common traversal attack ("/../") without requiring the target file to exist.
     */
    public static function assertSandboxOutputDir(string $path, string $sessionId): void
    {
        $relativeSessionPrefix = str_replace('\\', '/', self::SANDBOX_ROOT . $sessionId . '/');

        // --- Step 1: build a normalized form of the input path ---

        $resolvedPath   = realpath($path);
        $resolvedParent = ($resolvedPath === false) ? realpath(dirname($path)) : false;

        if ($resolvedPath !== false) {
            // File exists — fully resolved absolute path.
            $normalizedInput = str_replace('\\', '/', $resolvedPath) . '/';
            $useAbsolute     = true;
        } elseif ($resolvedParent !== false) {
            // Parent dir exists but file does not — reconstruct from parent + basename.
            // This collapses ".." segments that realpath() resolved in the parent portion.
            $normalizedInput = str_replace('\\', '/', $resolvedParent) . '/' . basename($path) . '/';
            $useAbsolute     = true;
        } else {
            // Neither exists — relative string normalization (no ".." collapsing).
            $normalizedInput = str_replace('\\', '/', $path);
            if (!str_ends_with($normalizedInput, '/')) {
                $normalizedInput .= '/';
            }
            $useAbsolute = false;
        }

        // --- Step 2: build the expected prefix in the same coordinate space ---

        if ($useAbsolute) {
            // Resolve the session directory to an absolute path.
            // If the session dir exists (created by startSession), realpath will work.
            // If not (edge case: check before session is created), fall back to cwd-relative.
            $resolvedSessionDir = realpath(self::SANDBOX_ROOT . $sessionId);
            if ($resolvedSessionDir !== false) {
                $expectedPrefix = str_replace('\\', '/', $resolvedSessionDir) . '/';
            } else {
                // Session dir doesn't exist yet — construct absolute prefix from cwd.
                $cwd            = str_replace('\\', '/', (string)getcwd());
                $expectedPrefix = rtrim($cwd, '/') . '/' . $relativeSessionPrefix;
            }
        } else {
            $expectedPrefix = $relativeSessionPrefix;
        }

        // --- Step 3: enforce sandbox prefix ---

        if (!str_starts_with($normalizedInput, $expectedPrefix)) {
            throw new SandboxBoundaryViolationException(
                "Path '{$path}' is outside the sandbox session directory '{$relativeSessionPrefix}'.",
                'path_outside_sandbox'
            );
        }

        // --- Step 4: enforce no live output dir ---

        foreach (self::LIVE_OUTPUT_DIRS as $liveDir) {
            if ($useAbsolute) {
                $resolvedLive = realpath($liveDir);
                if ($resolvedLive !== false) {
                    $livePrefixToCheck = str_replace('\\', '/', $resolvedLive) . '/';
                } else {
                    // Dir doesn't exist — can't be a live-dir violation yet.
                    continue;
                }
            } else {
                $livePrefixToCheck = str_replace('\\', '/', $liveDir);
                if (!str_ends_with($livePrefixToCheck, '/')) {
                    $livePrefixToCheck .= '/';
                }
            }

            if (str_starts_with($normalizedInput, $livePrefixToCheck)) {
                throw new SandboxBoundaryViolationException(
                    "Path '{$path}' targets a live output directory '{$liveDir}', which is not permitted in sandbox sessions.",
                    'path_outside_sandbox'
                );
            }
        }
    }

    /**
     * Checks the candidate patch keys for any indicators of live system coupling.
     * Throws if any live coupling indicators are found.
     */
    public static function assertNoCandidateLiveCoupling(array $candidatePatch): void
    {
        // Check for debug_allow_inactive_candidate=true specifically
        if (array_key_exists('debug_allow_inactive_candidate', $candidatePatch)
            && $candidatePatch['debug_allow_inactive_candidate'] === true
        ) {
            throw new SandboxBoundaryViolationException(
                "Candidate patch contains 'debug_allow_inactive_candidate=true', which indicates live candidate coupling and is not permitted in sandbox sessions.",
                'live_candidate_coupling'
            );
        }

        // Check all keys for live coupling indicator prefixes/patterns
        $livePrefixes = ['live_', 'db_', 'production_'];
        foreach (array_keys($candidatePatch) as $key) {
            $keyStr = (string)$key;

            // Check exact key match for the debug bypass flag (any value — covered above for true,
            // but we also block having the key at all when coupled to live systems)
            if ($keyStr === 'debug_allow_inactive_candidate') {
                // Already handled the true case above; only block if actually set to true
                continue;
            }

            foreach ($livePrefixes as $prefix) {
                if (str_starts_with($keyStr, $prefix)) {
                    throw new SandboxBoundaryViolationException(
                        "Candidate patch key '{$keyStr}' indicates live system coupling (matches prefix '{$prefix}'). Live-coupled keys are not permitted in sandbox sessions.",
                        'live_candidate_coupling'
                    );
                }
            }
        }
    }

    /**
     * Asserts that the given stage index is one of the permitted sandbox stages (1–5).
     */
    public static function assertSandboxStageAllowed(int $stageIndex): void
    {
        if (!in_array($stageIndex, self::SANDBOX_STAGES, true)) {
            throw new SandboxBoundaryViolationException(
                "Stage {$stageIndex} is not permitted in sandbox sessions. Permitted stages: 1-5.",
                'stage_not_permitted'
            );
        }
    }
}
