<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/simulation/StakeholderSummaryBuilder.php';

/**
 * Tests for StakeholderSummaryBuilder (Milestone 4B).
 */
class StakeholderSummaryBuilderTest extends TestCase
{
    private function sampleRunResult(array $overrides = []): array
    {
        return array_merge([
            'status'  => 'completed',
            'message' => 'Lifecycle run completed.',
            'cohort'  => [
                'status'          => 'created',
                'total_players'   => 110,
                'archetype_count' => 11,
                'archetypes'      => [
                    'casual'  => ['label' => 'Casual',  'count' => 10],
                    'regular' => ['label' => 'Regular', 'count' => 10],
                ],
                'player_map'      => [],
            ],
            'season_id'           => 1,
            'adapted_paths'       => ['season_setup_direct_insert', 'database_singleton_redirect'],
            'unmodeled_mechanics' => ['boost_purchase_not_dispatched'],
            'metrics' => [
                'join' => [
                    'joined'      => 110,
                    'failed'      => 0,
                    'errors'      => [],
                    'duration_ms' => 42.5,
                ],
                'tick_loop' => [
                    'ticks_processed'  => 5040,
                    'actions_executed'  => [
                        'star_purchase' => ['attempted' => 500, 'succeeded' => 480],
                        'lock_in'       => ['attempted' => 80, 'succeeded' => 75],
                        'combine'       => ['attempted' => 200, 'succeeded' => 190],
                        'freeze'        => ['attempted' => 50, 'succeeded' => 30],
                        'theft'         => ['attempted' => 40, 'succeeded' => 20],
                    ],
                    'season_finalized' => true,
                    'duration_ms'      => 12345.6,
                ],
                'total_duration_ms' => 13000.0,
            ],
        ], $overrides);
    }

    private function sampleConfig(): array
    {
        return [
            'seed'        => 42,
            'cohort_size' => 10,
        ];
    }

    private function samplePhaseTimings(): array
    {
        return ['total_run_duration_ms' => 13000.0];
    }

    private function sampleSeasonConfig(): array
    {
        return [
            'season_id'     => 1,
            'start_time'    => 100,
            'end_time'      => 5140,
            'blackout_time' => 4900,
        ];
    }

    private function buildSummary(array $overrides = []): array
    {
        return StakeholderSummaryBuilder::build(
            $this->sampleRunResult($overrides),
            $this->sampleConfig(),
            $this->samplePhaseTimings(),
            $this->sampleSeasonConfig()
        );
    }

    // -----------------------------------------------------------------------
    // Top-level structure
    // -----------------------------------------------------------------------

    public function testSummaryHasAllRequiredSections(): void
    {
        $summary = $this->buildSummary();
        $required = [
            'run_summary',
            'economy_phase_summary',
            'lock_in_vs_expiry',
            'archetype_comparison',
            'outcome_distribution',
            'limitations_and_parity',
        ];
        foreach ($required as $key) {
            $this->assertArrayHasKey($key, $summary, "Missing section: $key");
        }
    }

    // -----------------------------------------------------------------------
    // Run summary
    // -----------------------------------------------------------------------

    public function testRunSummaryContainsRequiredFields(): void
    {
        $summary = $this->buildSummary();
        $rs = $summary['run_summary'];

        $this->assertArrayHasKey('status', $rs);
        $this->assertArrayHasKey('seed', $rs);
        $this->assertArrayHasKey('total_players', $rs);
        $this->assertArrayHasKey('ticks_processed', $rs);
        $this->assertArrayHasKey('season_finalized', $rs);
        $this->assertArrayHasKey('narrative', $rs);
        $this->assertArrayHasKey('total_actions', $rs);
        $this->assertArrayHasKey('successful_actions', $rs);
    }

    public function testRunSummaryNarrativeIsNonEmpty(): void
    {
        $summary = $this->buildSummary();
        $this->assertNotEmpty($summary['run_summary']['narrative']);
    }

    public function testRunSummaryReflectsInputData(): void
    {
        $summary = $this->buildSummary();
        $rs = $summary['run_summary'];

        $this->assertSame('completed', $rs['status']);
        $this->assertSame(42, $rs['seed']);
        $this->assertSame(110, $rs['total_players']);
        $this->assertSame(5040, $rs['ticks_processed']);
        $this->assertTrue($rs['season_finalized']);
        $this->assertSame(870, $rs['total_actions']); // 500+80+200+50+40
        $this->assertSame(795, $rs['successful_actions']); // 480+75+190+30+20
    }

    // -----------------------------------------------------------------------
    // Economy phase summary
    // -----------------------------------------------------------------------

    public function testEconomyPhaseSummaryStructure(): void
    {
        $summary = $this->buildSummary();
        $eps = $summary['economy_phase_summary'];

        $this->assertArrayHasKey('phases', $eps);
        $this->assertArrayHasKey('season_duration', $eps);
        $this->assertArrayHasKey('global_action_totals', $eps);
        $this->assertArrayHasKey('limitation', $eps);
        $this->assertSame(['EARLY', 'MID', 'LATE_ACTIVE', 'BLACKOUT'], $eps['phases']);
    }

    public function testEconomyPhaseSummaryHasLimitation(): void
    {
        $summary = $this->buildSummary();
        $this->assertIsString($summary['economy_phase_summary']['limitation']);
        $this->assertNotEmpty($summary['economy_phase_summary']['limitation']);
    }

    // -----------------------------------------------------------------------
    // Lock-in vs expiry
    // -----------------------------------------------------------------------

    public function testLockInVsExpiryFields(): void
    {
        $summary = $this->buildSummary();
        $lie = $summary['lock_in_vs_expiry'];

        $this->assertArrayHasKey('total_players', $lie);
        $this->assertArrayHasKey('locked_in', $lie);
        $this->assertArrayHasKey('expired', $lie);
        $this->assertArrayHasKey('lock_in_rate', $lie);
        $this->assertArrayHasKey('expiry_rate', $lie);
        $this->assertArrayHasKey('timing_distribution_by_phase', $lie);
        $this->assertArrayHasKey('limitation', $lie);
    }

    public function testLockInVsExpiryMathConsistency(): void
    {
        $summary = $this->buildSummary();
        $lie = $summary['lock_in_vs_expiry'];

        $this->assertSame(110, $lie['total_players']);
        $this->assertSame(75, $lie['locked_in']);
        $this->assertSame(35, $lie['expired']);
        $this->assertEqualsWithDelta(1.0, $lie['lock_in_rate'] + $lie['expiry_rate'], 0.01);
    }

    public function testLockInTimingDistributionIsNullWithLimitation(): void
    {
        $summary = $this->buildSummary();
        $this->assertNull($summary['lock_in_vs_expiry']['timing_distribution_by_phase']);
        $this->assertNotEmpty($summary['lock_in_vs_expiry']['limitation']);
    }

    // -----------------------------------------------------------------------
    // Archetype comparison
    // -----------------------------------------------------------------------

    public function testArchetypeComparisonStructure(): void
    {
        $summary = $this->buildSummary();
        $ac = $summary['archetype_comparison'];

        $this->assertArrayHasKey('archetypes', $ac);
        $this->assertArrayHasKey('limitation', $ac);
        $this->assertIsArray($ac['archetypes']);
    }

    public function testArchetypeComparisonRowFields(): void
    {
        $summary = $this->buildSummary();
        $rows = $summary['archetype_comparison']['archetypes'];

        $this->assertGreaterThan(0, count($rows));
        foreach ($rows as $row) {
            $this->assertArrayHasKey('archetype_key', $row);
            $this->assertArrayHasKey('label', $row);
            $this->assertArrayHasKey('player_count', $row);
            $this->assertArrayHasKey('action_counts', $row);
            $this->assertArrayHasKey('avg_final_score', $row);
            $this->assertArrayHasKey('lock_in_count', $row);
            $this->assertArrayHasKey('expiry_count', $row);
        }
    }

    // -----------------------------------------------------------------------
    // Outcome distribution
    // -----------------------------------------------------------------------

    public function testOutcomeDistributionStructure(): void
    {
        $summary = $this->buildSummary();
        $od = $summary['outcome_distribution'];

        $this->assertArrayHasKey('total_players', $od);
        $this->assertArrayHasKey('locked_in', $od);
        $this->assertArrayHasKey('expired', $od);
        $this->assertArrayHasKey('score_percentiles', $od);
        $this->assertArrayHasKey('ranking_summary', $od);
        $this->assertArrayHasKey('concentration', $od);
        $this->assertArrayHasKey('limitation', $od);
    }

    public function testOutcomeDistributionPlaceholdsForUnavailableFields(): void
    {
        $summary = $this->buildSummary();
        $od = $summary['outcome_distribution'];

        $this->assertNull($od['score_percentiles']);
        $this->assertNull($od['ranking_summary']);
        $this->assertNull($od['concentration']);
        $this->assertNotEmpty($od['limitation']);
    }

    // -----------------------------------------------------------------------
    // Limitations and parity
    // -----------------------------------------------------------------------

    public function testLimitationsAndParityStructure(): void
    {
        $summary = $this->buildSummary();
        $lp = $summary['limitations_and_parity'];

        $this->assertArrayHasKey('adapted_path_count', $lp);
        $this->assertArrayHasKey('unmodeled_mechanic_count', $lp);
        $this->assertArrayHasKey('items', $lp);
        $this->assertIsArray($lp['items']);
    }

    public function testLimitationsItemsHaveRequiredFields(): void
    {
        $summary = $this->buildSummary();
        foreach ($summary['limitations_and_parity']['items'] as $item) {
            $this->assertArrayHasKey('area', $item);
            $this->assertArrayHasKey('description', $item);
            $this->assertArrayHasKey('impact', $item);
        }
    }

    public function testLimitationsCountsMatchInput(): void
    {
        $summary = $this->buildSummary();
        $lp = $summary['limitations_and_parity'];

        $this->assertSame(2, $lp['adapted_path_count']);
        $this->assertSame(1, $lp['unmodeled_mechanic_count']);
    }

    // -----------------------------------------------------------------------
    // Edge: empty/zero data
    // -----------------------------------------------------------------------

    public function testSummaryHandlesZeroPlayerRun(): void
    {
        $summary = $this->buildSummary([
            'metrics' => [
                'join' => ['joined' => 0, 'failed' => 0, 'errors' => [], 'duration_ms' => 0],
                'tick_loop' => [
                    'ticks_processed' => 0,
                    'actions_executed' => [],
                    'season_finalized' => false,
                    'duration_ms' => 0,
                ],
                'total_duration_ms' => 0,
            ],
            'cohort' => ['status' => 'created', 'total_players' => 0, 'archetype_count' => 0, 'archetypes' => [], 'player_map' => []],
        ]);

        $this->assertSame(0, $summary['run_summary']['total_players']);
        $this->assertSame(0, $summary['lock_in_vs_expiry']['locked_in']);
        $this->assertSame(0, $summary['lock_in_vs_expiry']['lock_in_rate']);
    }
}
