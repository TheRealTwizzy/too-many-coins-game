<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/simulation/RunArtifactBuilder.php';

/**
 * Tests for RunArtifactBuilder (Milestone 4A + 4B).
 *
 * Validates that the structured run artifact contains all required fields,
 * maintains correct section boundaries, and that the determinism fingerprint
 * is stable and reproducible.
 */
class RunArtifactBuilderTest extends TestCase
{
    /**
     * Build a minimal valid run result for testing.
     */
    private function sampleRunResult(array $overrides = []): array
    {
        return array_merge([
            'status'  => 'completed',
            'message' => 'Lifecycle run completed: one-season fresh-run with production action paths.',
            'cohort'  => [
                'status'          => 'created',
                'total_players'   => 110,
                'archetype_count' => 11,
                'archetypes'      => [],
                'player_map'      => [],
            ],
            'season_id'           => 1,
            'adapted_paths'       => [
                'season_setup_direct_insert',
                'database_singleton_redirect',
                'simulated_player_keepalive',
            ],
            'unmodeled_mechanics' => [
                'boost_purchase_not_dispatched',
                'self_melt_freeze_no_policy_decision',
            ],
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

    private function sampleConfig(array $overrides = []): array
    {
        return array_merge([
            'db_host'     => '127.0.0.1',
            'db_port'     => '3306',
            'db_name'     => 'tmc_sim_test',
            'db_user'     => 'root',
            'db_pass'     => '',
            'seed'        => 42,
            'cohort_size' => 10,
            'drop_first'  => false,
        ], $overrides);
    }

    private function samplePhaseTimings(): array
    {
        return [
            'bootstrap_duration_ms'        => 150.0,
            'cohort_creation_duration_ms'   => 200.0,
            'season_setup_duration_ms'      => 50.0,
            'join_duration_ms'              => 42.5,
            'tick_loop_duration_ms'         => 12345.6,
            'total_run_duration_ms'         => 13000.0,
        ];
    }

    private function sampleSeasonConfig(): array
    {
        return [
            'season_id'     => 1,
            'start_time'    => 100,
            'end_time'      => 5140,
            'blackout_time' => 4900,
            'season_seed'   => 'abc123',
        ];
    }

    private function buildArtifact(array $overrides = [], array $extraSeasons = []): array
    {
        return RunArtifactBuilder::build(
            $this->sampleRunResult($overrides),
            $this->sampleConfig(),
            $this->samplePhaseTimings(),
            $this->sampleSeasonConfig(),
            [],
            $extraSeasons
        );
    }

    // -----------------------------------------------------------------------
    // Schema and top-level structure
    // -----------------------------------------------------------------------

    public function testArtifactHasRequiredTopLevelKeys(): void
    {
        $artifact = $this->buildArtifact();

        $requiredKeys = [
            'schema_version',
            'simulator',
            'generated_at',
            'metadata',
            'execution_metrics',
            'adapted_paths',
            'unmodeled_mechanics',
            'assumptions',
            'parity_status',
            'termination',
            'stakeholder_summary',
            'mechanic_classifications',
            'parity_ledger',
            'determinism_fingerprint',
        ];

        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $artifact, "Missing top-level key: $key");
        }
    }

    public function testSchemaVersionIsStable(): void
    {
        $artifact = $this->buildArtifact();
        $this->assertSame('tmc-fresh-run-artifact.v1', $artifact['schema_version']);
    }

    public function testSimulatorIdentifier(): void
    {
        $artifact = $this->buildArtifact();
        $this->assertSame('fresh-lifecycle', $artifact['simulator']);
    }

    public function testGeneratedAtIsIso8601(): void
    {
        $artifact = $this->buildArtifact();
        $parsed = \DateTime::createFromFormat(\DateTime::ATOM, $artifact['generated_at']);
        $this->assertNotFalse($parsed, 'generated_at must be valid ISO 8601');
    }

    // -----------------------------------------------------------------------
    // Metadata section
    // -----------------------------------------------------------------------

    public function testMetadataContainsRequiredFields(): void
    {
        $artifact = $this->buildArtifact();
        $meta = $artifact['metadata'];

        $this->assertArrayHasKey('simulator_version', $meta);
        $this->assertArrayHasKey('production_commit', $meta);
        $this->assertArrayHasKey('deterministic_seed', $meta);
        $this->assertArrayHasKey('cohort_definition', $meta);
        $this->assertArrayHasKey('season', $meta);
    }

    public function testSimulatorVersionIsString(): void
    {
        $artifact = $this->buildArtifact();
        $this->assertIsString($artifact['metadata']['simulator_version']);
        $this->assertNotEmpty($artifact['metadata']['simulator_version']);
    }

    public function testDeterministicSeedMatchesConfig(): void
    {
        $artifact = $this->buildArtifact();
        $this->assertSame(42, $artifact['metadata']['deterministic_seed']);
    }

    public function testCohortDefinitionFields(): void
    {
        $artifact = $this->buildArtifact();
        $cohort = $artifact['metadata']['cohort_definition'];

        $this->assertArrayHasKey('players_per_archetype', $cohort);
        $this->assertArrayHasKey('archetype_count', $cohort);
        $this->assertArrayHasKey('total_players', $cohort);
        $this->assertSame(10, $cohort['players_per_archetype']);
    }

    public function testSeasonSummaryIsolatesTargetSeason(): void
    {
        $artifact = $this->buildArtifact();
        $season = $artifact['metadata']['season'];

        $this->assertArrayHasKey('target_season_id', $season);
        $this->assertArrayHasKey('start_time', $season);
        $this->assertArrayHasKey('end_time', $season);
        $this->assertArrayHasKey('blackout_time', $season);
        $this->assertArrayHasKey('extra_seasons_present', $season);
        $this->assertArrayHasKey('extra_season_ids', $season);
        $this->assertSame(1, $season['target_season_id']);
        $this->assertFalse($season['extra_seasons_present']);
    }

    public function testExtraSeasonsReportedWhenPresent(): void
    {
        $artifact = $this->buildArtifact([], [2, 3]);
        $season = $artifact['metadata']['season'];

        $this->assertTrue($season['extra_seasons_present']);
        $this->assertSame([2, 3], $season['extra_season_ids']);
        $this->assertNotNull($season['extra_seasons_note']);
    }

    // -----------------------------------------------------------------------
    // Execution metrics section
    // -----------------------------------------------------------------------

    public function testExecutionMetricsContainsRequiredFields(): void
    {
        $artifact = $this->buildArtifact();
        $metrics = $artifact['execution_metrics'];

        $this->assertArrayHasKey('phase_durations_ms', $metrics);
        $this->assertArrayHasKey('action_counts', $metrics);
        $this->assertArrayHasKey('ticks_processed', $metrics);
        $this->assertArrayHasKey('players_joined', $metrics);
        $this->assertArrayHasKey('players_join_failed', $metrics);
    }

    public function testPhaseDurationsContainsAllPhases(): void
    {
        $artifact = $this->buildArtifact();
        $durations = $artifact['execution_metrics']['phase_durations_ms'];

        $requiredPhases = ['bootstrap', 'cohort_creation', 'season_setup', 'join', 'tick_loop', 'total_run'];
        foreach ($requiredPhases as $phase) {
            $this->assertArrayHasKey($phase, $durations, "Missing phase duration: $phase");
        }
    }

    public function testActionCountsMatchRunResult(): void
    {
        $artifact = $this->buildArtifact();
        $actions = $artifact['execution_metrics']['action_counts'];

        $this->assertArrayHasKey('star_purchase', $actions);
        $this->assertArrayHasKey('lock_in', $actions);
        $this->assertArrayHasKey('combine', $actions);
        $this->assertArrayHasKey('freeze', $actions);
        $this->assertArrayHasKey('theft', $actions);

        $this->assertSame(500, $actions['star_purchase']['attempted']);
        $this->assertSame(480, $actions['star_purchase']['succeeded']);
    }

    public function testTicksProcessedIsInteger(): void
    {
        $artifact = $this->buildArtifact();
        $this->assertSame(5040, $artifact['execution_metrics']['ticks_processed']);
    }

    // -----------------------------------------------------------------------
    // Adapted paths section
    // -----------------------------------------------------------------------

    public function testAdaptedPathsAreStructured(): void
    {
        $artifact = $this->buildArtifact();
        $paths = $artifact['adapted_paths'];

        $this->assertIsArray($paths);
        $this->assertGreaterThan(0, count($paths));

        foreach ($paths as $path) {
            $this->assertArrayHasKey('key', $path);
            $this->assertArrayHasKey('label', $path);
            $this->assertArrayHasKey('description', $path);
            $this->assertArrayHasKey('classification', $path);
            $this->assertSame('Adapted', $path['classification']);
        }
    }

    public function testAdaptedPathsContainExpectedKeys(): void
    {
        $artifact = $this->buildArtifact();
        $keys = array_column($artifact['adapted_paths'], 'key');

        $this->assertContains('season_setup_direct_insert', $keys);
        $this->assertContains('database_singleton_redirect', $keys);
        $this->assertContains('simulated_player_keepalive', $keys);
    }

    // -----------------------------------------------------------------------
    // Unmodeled mechanics section
    // -----------------------------------------------------------------------

    public function testUnmodeledMechanicsAreStructured(): void
    {
        $artifact = $this->buildArtifact();
        $mechanics = $artifact['unmodeled_mechanics'];

        $this->assertIsArray($mechanics);
        $this->assertGreaterThan(0, count($mechanics));

        foreach ($mechanics as $m) {
            $this->assertArrayHasKey('key', $m);
            $this->assertArrayHasKey('label', $m);
            $this->assertArrayHasKey('description', $m);
            $this->assertArrayHasKey('classification', $m);
            $this->assertArrayHasKey('severity', $m);
        }
    }

    public function testUnmodeledMechanicsContainExpectedKeys(): void
    {
        $artifact = $this->buildArtifact();
        $keys = array_column($artifact['unmodeled_mechanics'], 'key');

        $this->assertContains('boost_purchase_not_dispatched', $keys);
        $this->assertContains('self_melt_freeze_no_policy_decision', $keys);
    }

    // -----------------------------------------------------------------------
    // Assumptions section
    // -----------------------------------------------------------------------

    public function testAssumptionsAreStructured(): void
    {
        $artifact = $this->buildArtifact();
        $assumptions = $artifact['assumptions'];

        $this->assertIsArray($assumptions);
        $this->assertGreaterThan(0, count($assumptions));

        foreach ($assumptions as $a) {
            $this->assertArrayHasKey('key', $a);
            $this->assertArrayHasKey('description', $a);
        }
    }

    public function testPhantomSeasonsAssumptionAddedWhenExtraSeasonsPresent(): void
    {
        $artifact = $this->buildArtifact([], [2]);
        $keys = array_column($artifact['assumptions'], 'key');
        $this->assertContains('phantom_seasons_present', $keys);
    }

    public function testNoPhantomSeasonsAssumptionWithoutExtraSeasons(): void
    {
        $artifact = $this->buildArtifact();
        $keys = array_column($artifact['assumptions'], 'key');
        $this->assertNotContains('phantom_seasons_present', $keys);
    }

    // -----------------------------------------------------------------------
    // Parity status section
    // -----------------------------------------------------------------------

    public function testParityStatusFields(): void
    {
        $artifact = $this->buildArtifact();
        $parity = $artifact['parity_status'];

        $this->assertArrayHasKey('overall', $parity);
        $this->assertArrayHasKey('adapted_count', $parity);
        $this->assertArrayHasKey('unmodeled_count', $parity);
        $this->assertArrayHasKey('e2e_validated', $parity);
        $this->assertArrayHasKey('notes', $parity);
    }

    public function testParityStatusIsPartialWithUnmodeledMechanics(): void
    {
        $artifact = $this->buildArtifact();
        $this->assertSame('partial', $artifact['parity_status']['overall']);
    }

    public function testParityStatusE2eNotValidated(): void
    {
        $artifact = $this->buildArtifact();
        $this->assertFalse($artifact['parity_status']['e2e_validated']);
    }

    public function testParityStatusConservativeWhenNoUnmodeled(): void
    {
        $artifact = $this->buildArtifact(['unmodeled_mechanics' => []]);
        $this->assertSame('conservative', $artifact['parity_status']['overall']);
    }

    // -----------------------------------------------------------------------
    // Termination section
    // -----------------------------------------------------------------------

    public function testTerminationFieldsOnSuccess(): void
    {
        $artifact = $this->buildArtifact();
        $term = $artifact['termination'];

        $this->assertSame('season_finalized', $term['reason']);
        $this->assertSame('completed', $term['status']);
    }

    public function testTerminationReasonMaxTickWhenNotFinalized(): void
    {
        $artifact = $this->buildArtifact([
            'metrics' => [
                'tick_loop' => [
                    'ticks_processed' => 5040,
                    'actions_executed' => [],
                    'season_finalized' => false,
                    'duration_ms' => 1000.0,
                ],
                'join' => ['joined' => 10, 'failed' => 0, 'errors' => [], 'duration_ms' => 5.0],
                'total_duration_ms' => 1100.0,
            ],
        ]);
        $this->assertSame('max_tick_reached', $artifact['termination']['reason']);
    }

    public function testTerminationReasonOnFailure(): void
    {
        $artifact = $this->buildArtifact(['status' => 'failed']);
        $this->assertSame('error', $artifact['termination']['reason']);
    }

    // -----------------------------------------------------------------------
    // Determinism fingerprint
    // -----------------------------------------------------------------------

    public function testDeterminismFingerprintIssha256(): void
    {
        $artifact = $this->buildArtifact();
        $fp = $artifact['determinism_fingerprint'];

        $this->assertIsString($fp);
        $this->assertSame(64, strlen($fp), 'Fingerprint must be 64 hex characters (SHA-256)');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $fp);
    }

    public function testFingerprintIsReproducible(): void
    {
        $a1 = $this->buildArtifact();
        $a2 = $this->buildArtifact();

        $this->assertSame(
            $a1['determinism_fingerprint'],
            $a2['determinism_fingerprint'],
            'Same inputs must produce the same fingerprint'
        );
    }

    public function testFingerprintChangesWithDifferentSeed(): void
    {
        $a1 = RunArtifactBuilder::build(
            $this->sampleRunResult(),
            $this->sampleConfig(['seed' => 42]),
            $this->samplePhaseTimings(),
            $this->sampleSeasonConfig(),
            [],
            []
        );

        $a2 = RunArtifactBuilder::build(
            $this->sampleRunResult(),
            $this->sampleConfig(['seed' => 99]),
            $this->samplePhaseTimings(),
            $this->sampleSeasonConfig(),
            [],
            []
        );

        $this->assertNotSame(
            $a1['determinism_fingerprint'],
            $a2['determinism_fingerprint'],
            'Different seeds must produce different fingerprints'
        );
    }

    public function testFingerprintExcludesTimingVariation(): void
    {
        $timings1 = $this->samplePhaseTimings();
        $timings2 = $this->samplePhaseTimings();
        $timings2['bootstrap_duration_ms'] = 999.9;
        $timings2['total_run_duration_ms'] = 50000.0;

        $a1 = RunArtifactBuilder::build(
            $this->sampleRunResult(),
            $this->sampleConfig(),
            $timings1,
            $this->sampleSeasonConfig(),
            [],
            []
        );

        $a2 = RunArtifactBuilder::build(
            $this->sampleRunResult(),
            $this->sampleConfig(),
            $timings2,
            $this->sampleSeasonConfig(),
            [],
            []
        );

        $this->assertSame(
            $a1['determinism_fingerprint'],
            $a2['determinism_fingerprint'],
            'Phase timing differences must not affect the fingerprint'
        );
    }

    // -----------------------------------------------------------------------
    // Artifact serialization
    // -----------------------------------------------------------------------

    public function testWriteArtifactCreatesValidJson(): void
    {
        $artifact = $this->buildArtifact();
        $tmpDir = sys_get_temp_dir() . '/tmc_artifact_test_' . getmypid();

        try {
            $path = RunArtifactBuilder::writeArtifact($artifact, $tmpDir, 'test_artifact');
            $this->assertFileExists($path);

            $content = file_get_contents($path);
            $decoded = json_decode($content, true);
            $this->assertIsArray($decoded);
            $this->assertSame('tmc-fresh-run-artifact.v1', $decoded['schema_version']);
        } finally {
            // Cleanup
            if (isset($path) && file_exists($path)) {
                unlink($path);
            }
            if (is_dir($tmpDir)) {
                rmdir($tmpDir);
            }
        }
    }

    // -----------------------------------------------------------------------
    // Section boundary: adapted vs unmodeled vs assumptions are distinct
    // -----------------------------------------------------------------------

    public function testSectionsAreDistinct(): void
    {
        $artifact = $this->buildArtifact();

        // Adapted paths, unmodeled mechanics, and assumptions must be
        // distinct sections with no key overlap.
        $adaptedKeys = array_column($artifact['adapted_paths'], 'key');
        $unmodeledKeys = array_column($artifact['unmodeled_mechanics'], 'key');
        $assumptionKeys = array_column($artifact['assumptions'], 'key');

        $this->assertEmpty(
            array_intersect($adaptedKeys, $unmodeledKeys),
            'Adapted paths and unmodeled mechanics must not overlap'
        );
        $this->assertEmpty(
            array_intersect($adaptedKeys, $assumptionKeys),
            'Adapted paths and assumptions must not overlap'
        );
        $this->assertEmpty(
            array_intersect($unmodeledKeys, $assumptionKeys),
            'Unmodeled mechanics and assumptions must not overlap'
        );
    }

    // -----------------------------------------------------------------------
    // Deduplication of adapted/unmodeled entries
    // -----------------------------------------------------------------------

    public function testDuplicateAdaptedPathsAreDeduped(): void
    {
        $artifact = $this->buildArtifact([
            'adapted_paths' => [
                'season_setup_direct_insert',
                'season_setup_direct_insert',
                'database_singleton_redirect',
            ],
        ]);

        $keys = array_column($artifact['adapted_paths'], 'key');
        $this->assertSame(array_values(array_unique($keys)), $keys);
    }

    public function testDuplicateUnmodeledMechanicsAreDeduped(): void
    {
        $artifact = $this->buildArtifact([
            'unmodeled_mechanics' => [
                'boost_purchase_not_dispatched',
                'boost_purchase_not_dispatched',
            ],
        ]);

        $keys = array_column($artifact['unmodeled_mechanics'], 'key');
        $this->assertSame(array_values(array_unique($keys)), $keys);
    }

    // -----------------------------------------------------------------------
    // Milestone 4B: Stakeholder summary in artifact
    // -----------------------------------------------------------------------

    public function testArtifactContains4BTopLevelKeys(): void
    {
        $artifact = $this->buildArtifact();
        $this->assertArrayHasKey('stakeholder_summary', $artifact);
        $this->assertArrayHasKey('mechanic_classifications', $artifact);
        $this->assertArrayHasKey('parity_ledger', $artifact);
    }

    public function testStakeholderSummarySectionsPresent(): void
    {
        $artifact = $this->buildArtifact();
        $ss = $artifact['stakeholder_summary'];

        $this->assertArrayHasKey('run_summary', $ss);
        $this->assertArrayHasKey('economy_phase_summary', $ss);
        $this->assertArrayHasKey('lock_in_vs_expiry', $ss);
        $this->assertArrayHasKey('archetype_comparison', $ss);
        $this->assertArrayHasKey('outcome_distribution', $ss);
        $this->assertArrayHasKey('limitations_and_parity', $ss);
    }

    // -----------------------------------------------------------------------
    // Milestone 4B: Mechanic classifications
    // -----------------------------------------------------------------------

    public function testMechanicClassificationsStructure(): void
    {
        $artifact = $this->buildArtifact();
        $mc = $artifact['mechanic_classifications'];

        $this->assertArrayHasKey('classification_labels', $mc);
        $this->assertArrayHasKey('counts', $mc);
        $this->assertArrayHasKey('mechanics', $mc);

        $this->assertSame(
            ['Modeled faithfully', 'Adapted', 'Approximated', 'Not modeled'],
            $mc['classification_labels']
        );
    }

    public function testMechanicClassificationsEntriesHaveRequiredFields(): void
    {
        $artifact = $this->buildArtifact();
        foreach ($artifact['mechanic_classifications']['mechanics'] as $m) {
            $this->assertArrayHasKey('mechanic', $m);
            $this->assertArrayHasKey('classification', $m);
            $this->assertArrayHasKey('note', $m);
            $this->assertContains($m['classification'], [
                'Modeled faithfully', 'Adapted', 'Approximated', 'Not modeled',
            ]);
        }
    }

    public function testMechanicClassificationsCountsMatchEntries(): void
    {
        $artifact = $this->buildArtifact();
        $mc = $artifact['mechanic_classifications'];

        $expected = ['Modeled faithfully' => 0, 'Adapted' => 0, 'Approximated' => 0, 'Not modeled' => 0];
        foreach ($mc['mechanics'] as $m) {
            $expected[$m['classification']]++;
        }

        $this->assertSame($expected, $mc['counts']);
    }

    public function testMechanicClassificationsReflectAdaptedPaths(): void
    {
        $artifact = $this->buildArtifact();
        $mc = $artifact['mechanic_classifications'];

        // With default sample data, adapted paths include season_setup_direct_insert
        $seasonSetup = null;
        foreach ($mc['mechanics'] as $m) {
            if ($m['mechanic'] === 'Season setup') {
                $seasonSetup = $m;
            }
        }
        $this->assertNotNull($seasonSetup);
        $this->assertSame('Adapted', $seasonSetup['classification']);
    }

    public function testMechanicClassificationsReflectUnmodeledMechanics(): void
    {
        $artifact = $this->buildArtifact();
        $mc = $artifact['mechanic_classifications'];

        $boostPurchase = null;
        foreach ($mc['mechanics'] as $m) {
            if ($m['mechanic'] === 'Boost purchase/activation') {
                $boostPurchase = $m;
            }
        }
        $this->assertNotNull($boostPurchase);
        $this->assertSame('Not modeled', $boostPurchase['classification']);
    }

    // -----------------------------------------------------------------------
    // Milestone 4B: Parity ledger in artifact
    // -----------------------------------------------------------------------

    public function testParityLedgerStructureInArtifact(): void
    {
        $artifact = $this->buildArtifact();
        $pl = $artifact['parity_ledger'];

        $this->assertArrayHasKey('schema', $pl);
        $this->assertArrayHasKey('generated_at', $pl);
        $this->assertArrayHasKey('run_context', $pl);
        $this->assertArrayHasKey('total_issues', $pl);
        $this->assertArrayHasKey('by_severity', $pl);
        $this->assertArrayHasKey('by_classification', $pl);
        $this->assertArrayHasKey('entries', $pl);
    }

    public function testParityLedgerDefaultsToEmpty(): void
    {
        $artifact = $this->buildArtifact();
        $pl = $artifact['parity_ledger'];

        $this->assertSame(0, $pl['total_issues']);
        $this->assertEmpty($pl['entries']);
        $this->assertSame('tmc-parity-ledger.v1', $pl['schema']);
    }

    public function testParityLedgerRunContextReflectsConfig(): void
    {
        $artifact = $this->buildArtifact();
        $ctx = $artifact['parity_ledger']['run_context'];

        $this->assertSame(42, $ctx['seed']);
        $this->assertSame(10, $ctx['cohort_size']);
        $this->assertNotEmpty($ctx['simulator_version']);
    }

    // -----------------------------------------------------------------------
    // Milestone 4B: Fingerprint stability with new sections
    // -----------------------------------------------------------------------

    public function testFingerprintIncludesMechanicClassifications(): void
    {
        // Build two artifacts with different adapted paths to verify
        // mechanic_classifications (which depend on adapted_paths) affect
        // the fingerprint.
        $a1 = $this->buildArtifact([
            'adapted_paths' => ['season_setup_direct_insert'],
        ]);
        $a2 = $this->buildArtifact([
            'adapted_paths' => [],
        ]);

        $this->assertNotSame(
            $a1['determinism_fingerprint'],
            $a2['determinism_fingerprint'],
            'Different adapted paths (affecting mechanic_classifications) should change fingerprint'
        );
    }

    public function testFingerprintExcludesExtraSeasonIds(): void
    {
        $a1 = $this->buildArtifact([], []);
        $a2 = $this->buildArtifact([], [2, 3]);

        $this->assertSame(
            $a1['determinism_fingerprint'],
            $a2['determinism_fingerprint'],
            'Extra season IDs (non-deterministic auto-increment) must not affect the fingerprint'
        );
    }
}
