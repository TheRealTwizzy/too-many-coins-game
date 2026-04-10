<?php
/**
 * RunArtifactBuilder — builds a structured, machine-readable run artifact
 * for fresh-lifecycle simulation runs.
 *
 * Milestone 4A: Core run artifact and metrics collection.
 * Milestone 4B: Stakeholder summaries, mechanic classifications, parity ledger.
 *
 * The artifact captures:
 *   - authoritative run metadata (version, seed, cohort, season)
 *   - core execution metrics (phase durations, action counts, determinism hash)
 *   - adapted paths and unmodeled mechanics
 *   - parity status and interpretation constraints
 *   - clear section boundaries between execution facts, adapted paths,
 *     unmodeled mechanics, and assumptions
 */

require_once __DIR__ . '/SimulationRandom.php';
require_once __DIR__ . '/StakeholderSummaryBuilder.php';
require_once __DIR__ . '/ParityLedger.php';

class RunArtifactBuilder
{
    public const ARTIFACT_SCHEMA_VERSION = 'tmc-fresh-run-artifact.v1';
    public const SIMULATOR_VERSION = '0.4.1-alpha';

    /**
     * Build a complete fresh-run artifact from runner state.
     *
     * @param array $runResult      The return value from FreshLifecycleRunner::run()
     * @param array $config         The runner config (seed, cohort_size, db_name, etc.)
     * @param array $phaseTimings   Per-phase duration measurements in ms:
     *                              bootstrap_duration_ms, cohort_creation_duration_ms,
     *                              season_setup_duration_ms, join_duration_ms,
     *                              tick_loop_duration_ms, total_run_duration_ms
     * @param array $seasonConfig   The season config array from the runner
     * @param array $runLog         The full run log from the runner
     * @param array $extraSeasons   Season IDs found in disposable DB beyond the target season
     * @return array                The structured run artifact
     */
    public static function build(
        array $runResult,
        array $config,
        array $phaseTimings,
        ?array $seasonConfig,
        array $runLog,
        array $extraSeasons = []
    ): array {
        $artifact = [
            'schema_version' => self::ARTIFACT_SCHEMA_VERSION,
            'simulator'      => 'fresh-lifecycle',
            'generated_at'   => gmdate('c'),

            // --- Section 1: Run metadata ---
            'metadata' => self::buildMetadata($config, $seasonConfig, $extraSeasons),

            // --- Section 2: Execution metrics ---
            'execution_metrics' => self::buildExecutionMetrics(
                $runResult,
                $phaseTimings,
                $config
            ),

            // --- Section 3: Adapted paths ---
            'adapted_paths' => self::buildAdaptedPaths($runResult['adapted_paths'] ?? []),

            // --- Section 4: Unmodeled mechanics ---
            'unmodeled_mechanics' => self::buildUnmodeledMechanics($runResult['unmodeled_mechanics'] ?? []),

            // --- Section 5: Assumptions and interpretation constraints ---
            'assumptions' => self::buildAssumptions($runResult, $extraSeasons),

            // --- Section 6: Parity status ---
            'parity_status' => self::buildParityStatus($runResult, $extraSeasons),

            // --- Section 7: Lifecycle termination ---
            'termination' => self::buildTermination($runResult),

            // --- Section 8 (4B): Stakeholder summary ---
            'stakeholder_summary' => StakeholderSummaryBuilder::build(
                $runResult,
                $config,
                $phaseTimings,
                $seasonConfig
            ),

            // --- Section 9 (4B): Mechanic classifications ---
            'mechanic_classifications' => self::buildMechanicClassifications(
                $runResult['adapted_paths'] ?? [],
                $runResult['unmodeled_mechanics'] ?? []
            ),

            // --- Section 10 (4B): Parity ledger ---
            'parity_ledger' => ParityLedger::buildEmpty(
                self::SIMULATOR_VERSION,
                self::resolveProductionCommit(),
                $config['seed'] ?? 42,
                (int)($config['cohort_size'] ?? 100)
            ),
        ];

        // --- Section 11: Determinism fingerprint ---
        $artifact['determinism_fingerprint'] = self::computeDeterminismFingerprint($artifact);

        return $artifact;
    }

    /**
     * Build the metadata section.
     */
    private static function buildMetadata(array $config, ?array $seasonConfig, array $extraSeasons): array
    {
        $metadata = [
            'simulator_version'     => self::SIMULATOR_VERSION,
            'production_commit'     => self::resolveProductionCommit(),
            'deterministic_seed'    => $config['seed'] ?? 42,
            'cohort_definition'     => self::buildCohortDefinition($config),
            'season'                => self::buildSeasonSummary($seasonConfig, $extraSeasons),
        ];

        return $metadata;
    }

    /**
     * Resolve the current git commit hash if available.
     */
    private static function resolveProductionCommit(): ?string
    {
        // Try git rev-parse from the repo root.
        $repoRoot = realpath(__DIR__ . '/../../');
        if ($repoRoot === false) {
            return null;
        }

        $gitHead = $repoRoot . DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR . 'HEAD';
        if (!file_exists($gitHead)) {
            return null;
        }

        // Read HEAD to resolve the current ref.
        $headContent = trim(file_get_contents($gitHead));
        if (strpos($headContent, 'ref: ') === 0) {
            $refPath = $repoRoot . DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR
                     . str_replace('/', DIRECTORY_SEPARATOR, substr($headContent, 5));
            if (file_exists($refPath)) {
                $commit = trim(file_get_contents($refPath));
                return strlen($commit) >= 7 ? substr($commit, 0, 12) : null;
            }
            // Might be in packed-refs.
            $packedRefs = $repoRoot . DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR . 'packed-refs';
            if (file_exists($packedRefs)) {
                $ref = substr($headContent, 5);
                $lines = file($packedRefs, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    if (strpos($line, '#') === 0) continue;
                    $parts = preg_split('/\s+/', $line, 2);
                    if (count($parts) === 2 && trim($parts[1]) === $ref) {
                        return substr(trim($parts[0]), 0, 12);
                    }
                }
            }
            return null;
        }

        // Detached HEAD — raw commit hash.
        return strlen($headContent) >= 7 ? substr($headContent, 0, 12) : null;
    }

    /**
     * Build cohort definition summary.
     */
    private static function buildCohortDefinition(array $config): array
    {
        $cohortSize = (int)($config['cohort_size'] ?? 100);
        // Archetypes count comes from the Archetypes class if loaded.
        $archetypeCount = class_exists('Archetypes') ? count(Archetypes::all()) : 11;

        return [
            'players_per_archetype' => $cohortSize,
            'archetype_count'       => $archetypeCount,
            'total_players'         => $cohortSize * $archetypeCount,
        ];
    }

    /**
     * Build season summary for the artifact, explicitly isolating the target
     * season from any incidental extra seasons.
     */
    private static function buildSeasonSummary(?array $seasonConfig, array $extraSeasons): array
    {
        $summary = [
            'target_season_id'      => $seasonConfig ? (int)($seasonConfig['season_id'] ?? 0) : null,
            'start_time'            => $seasonConfig['start_time'] ?? null,
            'end_time'              => $seasonConfig['end_time'] ?? null,
            'blackout_time'         => $seasonConfig['blackout_time'] ?? null,
            'season_seed'           => $seasonConfig['season_seed'] ?? null,
            'setup_method'          => 'direct_insert',
            'extra_seasons_present' => count($extraSeasons) > 0,
            'extra_season_ids'      => $extraSeasons,
            'extra_seasons_note'    => count($extraSeasons) > 0
                ? 'Extra seasons were found in the disposable DB beyond the target simulated season. '
                  . 'These may be phantom seasons created by ensureSeasons() or leftover from prior runs. '
                  . 'Only the target season was simulated; extra seasons are incidental.'
                : null,
        ];

        return $summary;
    }

    /**
     * Build the execution metrics section.
     */
    private static function buildExecutionMetrics(
        array $runResult,
        array $phaseTimings,
        array $config
    ): array {
        $metrics = $runResult['metrics'] ?? [];
        $tickLoop = $metrics['tick_loop'] ?? [];
        $join = $metrics['join'] ?? [];

        return [
            'phase_durations_ms' => [
                'bootstrap'        => $phaseTimings['bootstrap_duration_ms'] ?? null,
                'cohort_creation'  => $phaseTimings['cohort_creation_duration_ms'] ?? null,
                'season_setup'     => $phaseTimings['season_setup_duration_ms'] ?? null,
                'join'             => $join['duration_ms'] ?? $phaseTimings['join_duration_ms'] ?? null,
                'tick_loop'        => $tickLoop['duration_ms'] ?? $phaseTimings['tick_loop_duration_ms'] ?? null,
                'total_run'        => $metrics['total_duration_ms'] ?? $phaseTimings['total_run_duration_ms'] ?? null,
            ],
            'action_counts' => self::buildActionCounts($tickLoop),
            'ticks_processed'    => (int)($tickLoop['ticks_processed'] ?? 0),
            'players_joined'     => (int)($join['joined'] ?? 0),
            'players_join_failed'=> (int)($join['failed'] ?? 0),
        ];
    }

    /**
     * Build per-action-type attempted/succeeded counts.
     */
    private static function buildActionCounts(array $tickLoop): array
    {
        $actionsExecuted = $tickLoop['actions_executed'] ?? [];
        $out = [];
        foreach ($actionsExecuted as $actionType => $counts) {
            $out[$actionType] = [
                'attempted' => (int)($counts['attempted'] ?? 0),
                'succeeded' => (int)($counts['succeeded'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Build the adapted paths section with descriptions.
     */
    private static function buildAdaptedPaths(array $adaptedPaths): array
    {
        $descriptions = [
            'season_setup_direct_insert' => [
                'label'       => 'Direct season INSERT',
                'description' => 'Season row inserted directly into disposable DB, bypassing GameTime::ensureSeasons() to guarantee deterministic timing and economy config.',
                'classification' => 'Adapted',
            ],
            'database_singleton_redirect' => [
                'label'       => 'Database singleton redirect',
                'description' => 'Database::resetInstance() forces a fresh PDO singleton connecting to the disposable simulation DB instead of production.',
                'classification' => 'Adapted',
            ],
            'simulated_player_keepalive' => [
                'label'       => 'Simulated player keepalive',
                'description' => 'Synthetic players get periodic last_activity_tick updates to prevent idle timeout. Production players refresh this via HTTP requests.',
                'classification' => 'Adapted',
            ],
        ];

        $result = [];
        foreach (array_unique($adaptedPaths) as $path) {
            if (isset($descriptions[$path])) {
                $result[] = array_merge(['key' => $path], $descriptions[$path]);
            } else {
                $result[] = [
                    'key'            => $path,
                    'label'          => $path,
                    'description'    => 'Adapted path recorded during run (no description available).',
                    'classification' => 'Adapted',
                ];
            }
        }
        return $result;
    }

    /**
     * Build the unmodeled mechanics section with descriptions.
     */
    private static function buildUnmodeledMechanics(array $unmodeledMechanics): array
    {
        $descriptions = [
            'boost_purchase_not_dispatched' => [
                'label'          => 'Boost purchase not dispatched',
                'description'    => 'No production endpoint exists for standalone boost activation. TickEngine processes active_boosts from DB faithfully; creation of boost entries is deferred.',
                'classification' => 'Not modeled',
                'severity'       => 'Major',
            ],
            'self_melt_freeze_no_policy_decision' => [
                'label'          => 'Self-melt freeze not modeled',
                'description'    => 'PolicyBehavior lacks a decision function for self-melt freeze. No synthetic player will self-melt in this run.',
                'classification' => 'Not modeled',
                'severity'       => 'Minor',
            ],
        ];

        $result = [];
        foreach (array_unique($unmodeledMechanics) as $mechanic) {
            if (isset($descriptions[$mechanic])) {
                $result[] = array_merge(['key' => $mechanic], $descriptions[$mechanic]);
            } else {
                $result[] = [
                    'key'            => $mechanic,
                    'label'          => $mechanic,
                    'description'    => 'Unmodeled mechanic recorded during run (no description available).',
                    'classification' => 'Not modeled',
                    'severity'       => 'Unknown',
                ];
            }
        }
        return $result;
    }

    /**
     * Build assumptions and interpretation constraints.
     */
    private static function buildAssumptions(array $runResult, array $extraSeasons): array
    {
        $assumptions = [
            [
                'key'         => 'single_season_scope',
                'description' => 'Only one season is simulated per run. Cross-season carryover, migration, and multi-season dynamics are not modeled.',
            ],
            [
                'key'         => 'deterministic_behavior',
                'description' => 'All synthetic player behavior is deterministic under the given seed. Bounded stochastic variation is sourced exclusively from SimulationRandom.',
            ],
            [
                'key'         => 'disposable_db_isolation',
                'description' => 'Run executes against a disposable local DB. No production, staging, or shared test data is touched.',
            ],
            [
                'key'         => 'tick_by_tick_execution',
                'description' => 'Season lifecycle is driven tick-by-tick. No fast-forward or batch-skip mechanics are used.',
            ],
            [
                'key'         => 'synthetic_keepalive',
                'description' => 'Synthetic players receive periodic activity refreshes to prevent idle timeout, unlike production where HTTP requests drive keepalive.',
            ],
        ];

        if (count($extraSeasons) > 0) {
            $assumptions[] = [
                'key'         => 'phantom_seasons_present',
                'description' => 'Extra seasons beyond the target were found in the disposable DB. '
                               . 'Only the target season was simulated. Extra seasons are incidental and may affect global state queries.',
            ];
        }

        return $assumptions;
    }

    /**
     * Build parity status section.
     */
    private static function buildParityStatus(array $runResult, array $extraSeasons): array
    {
        $adaptedCount = count(array_unique($runResult['adapted_paths'] ?? []));
        $unmodeledCount = count(array_unique($runResult['unmodeled_mechanics'] ?? []));
        $hasExtraSeasons = count($extraSeasons) > 0;

        // Conservative parity assessment.
        $overallStatus = 'conservative';
        $notes = [];

        if ($unmodeledCount > 0) {
            $overallStatus = 'partial';
            $notes[] = "$unmodeledCount unmodeled mechanic(s) present; results may diverge from production for affected action types.";
        }
        if ($adaptedCount > 0) {
            $notes[] = "$adaptedCount adapted path(s) present; behavior is functionally equivalent but execution path differs from production.";
        }
        if ($hasExtraSeasons) {
            $notes[] = 'Extra seasons detected in disposable DB; global state queries may include incidental data.';
        }

        $notes[] = 'Disposable-DB end-to-end validation is still pending; parity status is assessed conservatively.';

        return [
            'overall'          => $overallStatus,
            'adapted_count'    => $adaptedCount,
            'unmodeled_count'  => $unmodeledCount,
            'e2e_validated'    => false,
            'notes'            => $notes,
        ];
    }

    /**
     * Build termination info.
     */
    private static function buildTermination(array $runResult): array
    {
        $status = $runResult['status'] ?? 'unknown';
        $tickLoop = $runResult['metrics']['tick_loop'] ?? [];

        if ($status === 'completed' && !empty($tickLoop['season_finalized'])) {
            $reason = 'season_finalized';
        } elseif ($status === 'completed') {
            $reason = 'max_tick_reached';
        } elseif ($status === 'failed') {
            $reason = 'error';
        } else {
            $reason = 'unknown';
        }

        return [
            'reason'  => $reason,
            'status'  => $status,
            'message' => $runResult['message'] ?? null,
        ];
    }

    /**
     * Build mechanic classification labels (Milestone 4B).
     *
     * Each mechanic area is labeled with one of:
     *   - Modeled faithfully
     *   - Adapted
     *   - Approximated
     *   - Not modeled
     *
     * The list covers the major runtime mechanics and their classification
     * based on how the simulator handles them.
     */
    private static function buildMechanicClassifications(array $adaptedPaths, array $unmodeledMechanics): array
    {
        $adaptedSet = array_flip(array_unique($adaptedPaths));
        $unmodeledSet = array_flip(array_unique($unmodeledMechanics));

        // Define all major mechanic areas and their classifications.
        $mechanics = [
            [
                'mechanic'       => 'UBI coin accrual',
                'classification' => 'Modeled faithfully',
                'note'           => 'TickEngine processes UBI accrual via production path.',
            ],
            [
                'mechanic'       => 'Star purchase',
                'classification' => 'Modeled faithfully',
                'note'           => 'Actions::purchaseStars() called via production path.',
            ],
            [
                'mechanic'       => 'Lock-in',
                'classification' => 'Modeled faithfully',
                'note'           => 'Actions::lockIn() called via production path.',
            ],
            [
                'mechanic'       => 'Sigil drops',
                'classification' => 'Modeled faithfully',
                'note'           => 'TickEngine processes deterministic sigil drops via production path.',
            ],
            [
                'mechanic'       => 'Sigil combine',
                'classification' => 'Modeled faithfully',
                'note'           => 'Actions::combineSigils() called via production path.',
            ],
            [
                'mechanic'       => 'Sigil theft',
                'classification' => 'Modeled faithfully',
                'note'           => 'Actions::attemptSigilTheft() called via production path.',
            ],
            [
                'mechanic'       => 'Freeze',
                'classification' => 'Modeled faithfully',
                'note'           => 'Actions::freezePlayerUbi() called via production path.',
            ],
            [
                'mechanic'       => 'Season setup',
                'classification' => isset($adaptedSet['season_setup_direct_insert']) ? 'Adapted' : 'Modeled faithfully',
                'note'           => isset($adaptedSet['season_setup_direct_insert'])
                    ? 'Season row inserted directly; bypasses ensureSeasons() for deterministic timing.'
                    : 'Season created via production path.',
            ],
            [
                'mechanic'       => 'Database singleton',
                'classification' => isset($adaptedSet['database_singleton_redirect']) ? 'Adapted' : 'Modeled faithfully',
                'note'           => isset($adaptedSet['database_singleton_redirect'])
                    ? 'Database singleton redirected to disposable DB.'
                    : 'Database uses default singleton.',
            ],
            [
                'mechanic'       => 'Player keepalive',
                'classification' => isset($adaptedSet['simulated_player_keepalive']) ? 'Adapted' : 'Modeled faithfully',
                'note'           => isset($adaptedSet['simulated_player_keepalive'])
                    ? 'Synthetic players receive periodic keepalive updates instead of HTTP-driven activity.'
                    : 'Player keepalive uses default path.',
            ],
            [
                'mechanic'       => 'Boost purchase/activation',
                'classification' => isset($unmodeledSet['boost_purchase_not_dispatched']) ? 'Not modeled' : 'Modeled faithfully',
                'note'           => isset($unmodeledSet['boost_purchase_not_dispatched'])
                    ? 'No production endpoint for standalone boost activation. TickEngine processes existing active_boosts faithfully.'
                    : 'Boost purchase via production path.',
            ],
            [
                'mechanic'       => 'Self-melt freeze',
                'classification' => isset($unmodeledSet['self_melt_freeze_no_policy_decision']) ? 'Not modeled' : 'Modeled faithfully',
                'note'           => isset($unmodeledSet['self_melt_freeze_no_policy_decision'])
                    ? 'PolicyBehavior lacks a decision function for self-melt. No synthetic player will self-melt.'
                    : 'Self-melt via production path.',
            ],
            [
                'mechanic'       => 'Expiry settlement',
                'classification' => 'Modeled faithfully',
                'note'           => 'TickEngine processes season expiration and settlement via production path.',
            ],
            [
                'mechanic'       => 'Effective score / ranking',
                'classification' => 'Modeled faithfully',
                'note'           => 'Effective score and ranking computed via production settlement path.',
            ],
        ];

        // Count by classification.
        $counts = ['Modeled faithfully' => 0, 'Adapted' => 0, 'Approximated' => 0, 'Not modeled' => 0];
        foreach ($mechanics as $m) {
            $c = $m['classification'];
            if (isset($counts[$c])) {
                $counts[$c]++;
            }
        }

        return [
            'classification_labels' => ['Modeled faithfully', 'Adapted', 'Approximated', 'Not modeled'],
            'counts'                => $counts,
            'mechanics'             => $mechanics,
        ];
    }

    /**
     * Compute a determinism fingerprint / reproducibility hash.
     *
     * Hashes the stable parts of the artifact (excluding generated_at and the
     * fingerprint itself) to produce a reproducibility check value.
     * Two runs with the same seed, cohort, and season config should produce
     * the same fingerprint if execution is fully deterministic.
     */
    public static function computeDeterminismFingerprint(array $artifact): string
    {
        // Extract the stable subset of the artifact for hashing.
        $stable = [
            'schema_version'           => $artifact['schema_version'],
            'metadata'                 => $artifact['metadata'],
            'execution_metrics'        => $artifact['execution_metrics'],
            'adapted_paths'            => $artifact['adapted_paths'],
            'unmodeled_mechanics'      => $artifact['unmodeled_mechanics'],
            'termination'              => $artifact['termination'],
            'mechanic_classifications' => $artifact['mechanic_classifications'] ?? null,
        ];

        // Remove non-deterministic fields from the stable subset.
        unset($stable['metadata']['production_commit']);

        // Remove non-deterministic season fields: extra_season_ids are auto-
        // increment values that can drift between identical-seed runs.
        unset($stable['metadata']['season']['extra_season_ids']);
        unset($stable['metadata']['season']['extra_seasons_present']);
        unset($stable['metadata']['season']['extra_seasons_note']);

        // Remove timing measurements (these vary between runs even with same seed).
        unset($stable['execution_metrics']['phase_durations_ms']);

        // Use JSON_UNESCAPED_SLASHES only — JSON_NUMERIC_CHECK can convert
        // numeric-looking strings to numbers, causing false fingerprint drift.
        $json = json_encode($stable, JSON_UNESCAPED_SLASHES);
        return hash('sha256', $json);
    }

    /**
     * Write the run artifact to a JSON file.
     *
     * @return string  The path to the written file.
     */
    public static function writeArtifact(array $artifact, string $outputDir, string $baseName = 'fresh_run_artifact'): string
    {
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        $path = rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $baseName . '.json';
        file_put_contents(
            $path,
            json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
        return $path;
    }
}
