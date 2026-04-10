<?php
/**
 * StakeholderSummaryBuilder — builds concise, stakeholder-friendly summary
 * fields from fresh-lifecycle run data.
 *
 * Milestone 4B: Structured summaries derived from existing run result data.
 *
 * All summaries are conservative: fields that cannot be faithfully derived
 * from the current execution data are included with explicit limitation notes
 * rather than fabricated values.
 */

class StakeholderSummaryBuilder
{
    /**
     * Build the complete stakeholder summary block for a run artifact.
     *
     * @param array $runResult     Return value from FreshLifecycleRunner::run()
     * @param array $config        Runner config (seed, cohort_size, etc.)
     * @param array $phaseTimings  Per-phase duration measurements
     * @param array|null $seasonConfig  Season config from the runner
     * @return array  Structured stakeholder summary
     */
    public static function build(
        array $runResult,
        array $config,
        array $phaseTimings,
        ?array $seasonConfig
    ): array {
        return [
            'run_summary'              => self::buildRunSummary($runResult, $config, $phaseTimings),
            'economy_phase_summary'    => self::buildEconomyPhaseSummary($runResult, $seasonConfig),
            'lock_in_vs_expiry'        => self::buildLockInVsExpirySummary($runResult),
            'archetype_comparison'     => self::buildArchetypeComparison($runResult),
            'outcome_distribution'     => self::buildOutcomeDistribution($runResult),
            'limitations_and_parity'   => self::buildLimitationsSummary($runResult),
        ];
    }

    /**
     * Concise run summary: one-paragraph status of what happened.
     */
    private static function buildRunSummary(array $runResult, array $config, array $phaseTimings): array
    {
        $status = $runResult['status'] ?? 'unknown';
        $metrics = $runResult['metrics'] ?? [];
        $tickLoop = $metrics['tick_loop'] ?? [];
        $join = $metrics['join'] ?? [];

        $totalPlayers = (int)($join['joined'] ?? 0);
        $ticksProcessed = (int)($tickLoop['ticks_processed'] ?? 0);
        $finalized = !empty($tickLoop['season_finalized']);
        $totalDurationMs = $metrics['total_duration_ms'] ?? $phaseTimings['total_run_duration_ms'] ?? null;
        $seed = $config['seed'] ?? 42;
        $cohortSize = $config['cohort_size'] ?? 100;

        // Compute total action attempts and successes.
        $totalAttempted = 0;
        $totalSucceeded = 0;
        foreach ($tickLoop['actions_executed'] ?? [] as $counts) {
            $totalAttempted += (int)($counts['attempted'] ?? 0);
            $totalSucceeded += (int)($counts['succeeded'] ?? 0);
        }

        $durationLabel = $totalDurationMs !== null
            ? round($totalDurationMs / 1000, 1) . 's'
            : 'unknown';

        $narrative = sprintf(
            'Fresh lifecycle run (seed=%d, %d players/archetype): %s. '
            . '%d players joined, %d ticks processed, %d actions attempted (%d succeeded). '
            . 'Season %s in %s.',
            $seed,
            $cohortSize,
            $status,
            $totalPlayers,
            $ticksProcessed,
            $totalAttempted,
            $totalSucceeded,
            $finalized ? 'finalized normally' : 'did not finalize',
            $durationLabel
        );

        return [
            'status'            => $status,
            'seed'              => $seed,
            'players_per_archetype' => $cohortSize,
            'total_players'     => $totalPlayers,
            'ticks_processed'   => $ticksProcessed,
            'season_finalized'  => $finalized,
            'total_actions'     => $totalAttempted,
            'successful_actions'=> $totalSucceeded,
            'duration_seconds'  => $totalDurationMs !== null ? round($totalDurationMs / 1000, 1) : null,
            'narrative'         => $narrative,
        ];
    }

    /**
     * Economy phase summary: action volume by phase from tick loop data.
     *
     * Note: Per-phase economy flow (source/sink breakdowns) requires data not
     * currently collected by the fresh-run tick loop. This field is derived
     * from the action counts available, with a limitation note.
     */
    private static function buildEconomyPhaseSummary(array $runResult, ?array $seasonConfig): array
    {
        $tickLoop = $runResult['metrics']['tick_loop'] ?? [];
        $actionsExecuted = $tickLoop['actions_executed'] ?? [];

        // Phase-level action breakdown is not currently tracked by
        // FreshLifecycleRunner's tick loop (it aggregates globally).
        // Report what we have with a limitation note.
        $actionSummary = [];
        foreach ($actionsExecuted as $actionType => $counts) {
            $actionSummary[$actionType] = [
                'attempted' => (int)($counts['attempted'] ?? 0),
                'succeeded' => (int)($counts['succeeded'] ?? 0),
            ];
        }

        $phases = ['EARLY', 'MID', 'LATE_ACTIVE', 'BLACKOUT'];
        $seasonDuration = null;
        if ($seasonConfig) {
            $start = (int)($seasonConfig['start_time'] ?? 0);
            $end = (int)($seasonConfig['end_time'] ?? 0);
            $blackout = (int)($seasonConfig['blackout_time'] ?? 0);
            $seasonDuration = [
                'start_tick'    => $start,
                'end_tick'      => $end,
                'blackout_tick' => $blackout,
                'total_ticks'   => $end - $start,
            ];
        }

        return [
            'phases'            => $phases,
            'season_duration'   => $seasonDuration,
            'global_action_totals' => $actionSummary,
            'per_phase_breakdown'  => null,
            'limitation'        => 'Per-phase action breakdown is not currently collected by the fresh-run tick loop. '
                                 . 'Global action totals are reported. Per-phase granularity requires '
                                 . 'extending the tick loop to track actions by economy phase.',
        ];
    }

    /**
     * Lock-in vs expiry summary derived from action counts.
     *
     * Lock-in timing distribution by phase is not yet tracked in the fresh-run
     * tick loop (only total lock-in attempted/succeeded). This summary reports
     * what is available with a conservative limitation note.
     */
    private static function buildLockInVsExpirySummary(array $runResult): array
    {
        $tickLoop = $runResult['metrics']['tick_loop'] ?? [];
        $actionCounts = $tickLoop['actions_executed'] ?? [];
        $lockIn = $actionCounts['lock_in'] ?? ['attempted' => 0, 'succeeded' => 0];
        $join = $runResult['metrics']['join'] ?? [];

        $totalPlayers = (int)($join['joined'] ?? 0);
        $lockedIn = (int)($lockIn['succeeded'] ?? 0);
        $expired = max(0, $totalPlayers - $lockedIn);
        $lockInRate = $totalPlayers > 0 ? round($lockedIn / $totalPlayers, 3) : 0;
        $expiryRate = $totalPlayers > 0 ? round($expired / $totalPlayers, 3) : 0;

        return [
            'total_players'   => $totalPlayers,
            'locked_in'       => $lockedIn,
            'expired'         => $expired,
            'lock_in_rate'    => $lockInRate,
            'expiry_rate'     => $expiryRate,
            'timing_distribution_by_phase' => null,
            'limitation'      => 'Lock-in timing distribution by phase is not tracked in the current fresh-run tick loop. '
                               . 'Only aggregate lock-in/expiry counts are available. '
                               . 'Phase-level lock-in timing requires extending the tick loop to record lock-in tick per player.',
        ];
    }

    /**
     * Archetype comparison summary table.
     *
     * Derived from action counts by archetype when available. The fresh-run
     * tick loop currently does not break down action counts by archetype,
     * so this provides the structural format with a limitation note.
     */
    private static function buildArchetypeComparison(array $runResult): array
    {
        $cohort = $runResult['cohort'] ?? [];
        $archetypes = $cohort['archetypes'] ?? [];

        $rows = [];
        foreach ($archetypes as $key => $info) {
            $rows[] = [
                'archetype_key'   => $key,
                'label'           => $info['label'] ?? $key,
                'player_count'    => (int)($info['count'] ?? 0),
                // Per-archetype action counts, scores, and ranking data are not
                // tracked by the tick loop; these require a post-run DB query or
                // extending the tick loop to partition action counts by archetype.
                'action_counts'   => null,
                'avg_final_score' => null,
                'lock_in_count'   => null,
                'expiry_count'    => null,
            ];
        }

        return [
            'archetypes'  => $rows,
            'limitation'  => 'Per-archetype action counts, scores, and lock-in data require either a post-run DB query '
                           . 'or extending the tick loop to track per-archetype partitions. '
                           . 'Only cohort membership counts are available from current run data.',
        ];
    }

    /**
     * Final outcome distribution summary.
     *
     * Without per-player final state from a post-run query, this provides
     * aggregate lock-in vs. expiry counts and the structural format for
     * percentile/ranking data.
     */
    private static function buildOutcomeDistribution(array $runResult): array
    {
        $tickLoop = $runResult['metrics']['tick_loop'] ?? [];
        $actionCounts = $tickLoop['actions_executed'] ?? [];
        $lockIn = $actionCounts['lock_in'] ?? ['attempted' => 0, 'succeeded' => 0];
        $join = $runResult['metrics']['join'] ?? [];

        $totalPlayers = (int)($join['joined'] ?? 0);
        $lockedIn = (int)($lockIn['succeeded'] ?? 0);
        $expired = max(0, $totalPlayers - $lockedIn);

        return [
            'total_players'    => $totalPlayers,
            'locked_in'        => $lockedIn,
            'expired'          => $expired,
            'score_percentiles' => null,
            'ranking_summary'   => null,
            'concentration'     => null,
            'limitation'        => 'Score percentiles, ranking summary, and concentration metrics (top 1%/5%/10%) '
                                 . 'require a post-run DB query against season_participation/players tables. '
                                 . 'Only aggregate lock-in/expiry counts are derivable from current tick loop output.',
        ];
    }

    /**
     * Known limitations and parity status section for stakeholders.
     */
    private static function buildLimitationsSummary(array $runResult): array
    {
        $adaptedCount = count(array_unique($runResult['adapted_paths'] ?? []));
        $unmodeledCount = count(array_unique($runResult['unmodeled_mechanics'] ?? []));

        $items = [];

        if ($unmodeledCount > 0) {
            $mechanics = array_unique($runResult['unmodeled_mechanics'] ?? []);
            $items[] = [
                'area'        => 'Unmodeled mechanics',
                'description' => "$unmodeledCount mechanic(s) not modeled in this run: " . implode(', ', $mechanics) . '.',
                'impact'      => 'Results may diverge from production for affected action types.',
            ];
        }

        if ($adaptedCount > 0) {
            $paths = array_unique($runResult['adapted_paths'] ?? []);
            $items[] = [
                'area'        => 'Adapted execution paths',
                'description' => "$adaptedCount adapted path(s): " . implode(', ', $paths) . '.',
                'impact'      => 'Behavior is functionally equivalent but execution path differs from production.',
            ];
        }

        $items[] = [
            'area'        => 'Per-phase/per-archetype granularity',
            'description' => 'The fresh-run tick loop does not yet partition action counts by economy phase or archetype.',
            'impact'      => 'Economy phase summaries and archetype comparison tables have placeholder fields. '
                           . 'Extending the tick loop or adding post-run DB queries will resolve this.',
        ];

        $items[] = [
            'area'        => 'End-to-end parity validation',
            'description' => 'Disposable-DB end-to-end parity validation is still pending.',
            'impact'      => 'Parity status is assessed conservatively until formal validation passes.',
        ];

        return [
            'adapted_path_count'    => $adaptedCount,
            'unmodeled_mechanic_count' => $unmodeledCount,
            'items'                 => $items,
        ];
    }
}
