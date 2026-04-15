<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/optimization/TuningCandidateGenerator.php';
require_once __DIR__ . '/../scripts/simulation/EconomicCandidateValidator.php';

class TuningCandidateGeneratorTest extends TestCase
{
    public function testStageOneNeverProducesMultiKnobCandidates(): void
    {
        $document = TuningCandidateGenerator::generate($this->sampleDiagnosis(), [
            'baseline_season' => SimulationSeason::build(1, 'stage1-test'),
            'tuning_version' => 3,
        ]);

        $stage1 = (array)$document['stages']['stage_1_single_knob'];
        $this->assertNotEmpty($stage1);

        foreach ($stage1 as $candidate) {
            $this->assertSame('stage_1_single_knob', $candidate['stage']);
            $this->assertSame(1, $candidate['knob_count']);
            $this->assertCount(1, $candidate['changes']);
        }
    }

    public function testLowSignalKnobsDoNotAdvancePastStageOne(): void
    {
        $document = TuningCandidateGenerator::generate($this->sampleDiagnosis(), [
            'baseline_season' => SimulationSeason::build(1, 'blocked-test'),
            'tuning_version' => 3,
        ]);

        $stage1 = (array)$document['stages']['stage_1_single_knob'];
        $blocked = null;
        foreach ($stage1 as $candidate) {
            if (($candidate['changes'][0]['target'] ?? null) === 'hoarding_safe_hours') {
                $blocked = $candidate;
                break;
            }
        }

        $this->assertNotNull($blocked);
        $this->assertFalse((bool)$blocked['stage_controls']['eligible_for_next_stage']);
        $this->assertTrue((bool)$blocked['stage_controls']['blocked_due_to_low_signal']);
        $this->assertTrue((bool)$blocked['stage_controls']['blocked_due_to_instability']);

        foreach (['stage_2_pairwise', 'stage_3_constrained_bundle', 'stage_4_full_confirmation'] as $stageName) {
            foreach ((array)$document['stages'][$stageName] as $candidate) {
                $targets = array_column((array)$candidate['changes'], 'target');
                $this->assertNotContains('hoarding_safe_hours', $targets);
            }
        }
    }

    public function testLaterStagesCarryLineageFromEarlierValidatedCandidates(): void
    {
        $document = TuningCandidateGenerator::generate($this->sampleDiagnosis(), [
            'baseline_season' => SimulationSeason::build(1, 'lineage-test'),
            'tuning_version' => 3,
        ]);

        $stage2 = (array)$document['stages']['stage_2_pairwise'];
        $stage3 = (array)$document['stages']['stage_3_constrained_bundle'];
        $stage4 = (array)$document['stages']['stage_4_full_confirmation'];

        $this->assertNotEmpty($stage2);
        $this->assertNotEmpty($stage3);
        $this->assertNotEmpty($stage4);

        foreach ($stage2 as $candidate) {
            $this->assertCount(2, (array)$candidate['lineage']['parent_candidate_ids']);
        }

        foreach ($stage3 as $candidate) {
            $this->assertGreaterThanOrEqual(3, count((array)$candidate['lineage']['validated_candidate_ids']));
            $this->assertSame(3, $candidate['knob_count']);
        }

        foreach ($stage4 as $candidate) {
            $this->assertCount(1, (array)$candidate['lineage']['parent_candidate_ids']);
            $this->assertNotEmpty((array)$candidate['lineage']['ancestor_candidate_ids']);
        }
    }

    public function testGeneratedDocumentRemainsLintCompatible(): void
    {
        $document = TuningCandidateGenerator::generate($this->sampleDiagnosis(), [
            'baseline_season' => SimulationSeason::build(1, 'lint-test'),
            'tuning_version' => 3,
        ]);

        $failures = EconomicCandidateValidator::validateCandidateDocument($document, [
            'base_season' => SimulationSeason::build(1, 'lint-test-base'),
        ]);

        $this->assertSame([], $failures);
    }

    public function testHoardingCandidatesAreSuppressedWhenHoardingSinkIsDisabled(): void
    {
        $seasonConfig = $this->seasonConfigWithHoardingSink(0);
        $document = TuningCandidateGenerator::generate($this->sampleDiagnosis(), [
            'baseline_season' => SimulationSeason::build(1, 'baseline-defaults'),
            'season_config' => $seasonConfig,
            'tuning_version' => 3,
        ]);

        $targets = $this->documentTargets($document);
        $this->assertNotContains('hoarding_safe_hours', $targets);
        $this->assertNotContains('hoarding_tier2_rate_hourly_fp', $targets);
        $this->assertNotContains('hoarding_tier3_rate_hourly_fp', $targets);
        $this->assertNotContains('hoarding_idle_multiplier_fp', $targets);
        $this->assertNotContains('market_affordability_bias_fp', $targets);
        $this->assertNotContains('starprice_reactivation_window_ticks', $targets);
        $this->assertNotContains('target_spend_rate_per_tick', $targets);
        $this->assertNotContains('hoarding_window_ticks', $targets);

        $suppressed = (array)($document['suppression_report']['entries'] ?? []);
        $this->assertNotEmpty($suppressed);
        $this->assertTrue($this->hasSuppression($suppressed, 'hoarding_advantage', 'hoarding_tier2_rate_hourly_fp', 'knob_out_of_active_search_space'));
        $this->assertTrue($this->hasSuppression($suppressed, 'phase_dead_zones', 'hoarding_safe_hours', 'knob_out_of_active_search_space'));
    }

    public function testHoardingCandidateFamiliesReappearWhenHoardingSinkIsEnabled(): void
    {
        $seasonConfig = $this->seasonConfigWithHoardingSink(1);
        $document = TuningCandidateGenerator::generate($this->sampleDiagnosis(), [
            'baseline_season' => SimulationSeason::build(1, 'baseline-defaults'),
            'season_config' => $seasonConfig,
            'tuning_version' => 3,
        ]);

        $targets = $this->documentTargets($document);
        $this->assertContains('hoarding_safe_hours', $targets);
        $this->assertContains('hoarding_tier2_rate_hourly_fp', $targets);
        $this->assertContains('hoarding_tier3_rate_hourly_fp', $targets);
        $this->assertContains('hoarding_idle_multiplier_fp', $targets);
        $this->assertNotContains('market_affordability_bias_fp', $targets);
        $this->assertNotContains('starprice_reactivation_window_ticks', $targets);
        $this->assertNotContains('target_spend_rate_per_tick', $targets);
        $this->assertNotContains('hoarding_window_ticks', $targets);

        $suppressed = (array)($document['suppression_report']['entries'] ?? []);
        $this->assertFalse($this->hasSuppression($suppressed, 'hoarding_advantage', 'hoarding_tier2_rate_hourly_fp', 'knob_out_of_active_search_space'));
        $this->assertFalse($this->hasSuppression($suppressed, 'phase_dead_zones', 'hoarding_safe_hours', 'knob_out_of_active_search_space'));
    }

    public function testGenerationOutputIsStableAndBaselineAware(): void
    {
        $seasonConfig = $this->seasonConfigWithHoardingSink(0);
        $options = [
            'baseline_season' => SimulationSeason::build(1, 'baseline-defaults'),
            'season_config' => $seasonConfig,
            'tuning_version' => 3,
        ];

        $first = TuningCandidateGenerator::generate($this->sampleDiagnosis(), $options);
        $second = TuningCandidateGenerator::generate($this->sampleDiagnosis(), $options);

        $this->assertSame(
            $this->normalizeGeneratedDocument($first),
            $this->normalizeGeneratedDocument($second)
        );
    }

    private function sampleDiagnosis(): array
    {
        return [
            'schema_version' => 'tmc-diagnosis.v1',
            'findings' => [
                [
                    'id' => 'F1',
                    'category' => 'hoarding_advantage',
                    'severity' => 'HIGH',
                    'confidence' => 'HIGH',
                    'description' => 'Hoarders keep too much late-season advantage.',
                    'notes' => 'Stable across seeds.',
                ],
                [
                    'id' => 'F2',
                    'category' => 'concentrated_wealth',
                    'severity' => 'HIGH',
                    'confidence' => 'HIGH',
                    'description' => 'Top-end concentration remains elevated.',
                    'notes' => 'Stable across seeds.',
                ],
                [
                    'id' => 'F3',
                    'category' => 'lock_in_timing_pathologies',
                    'severity' => 'MEDIUM',
                    'confidence' => 'HIGH',
                    'description' => 'Lock-in timing collapses too late in the season.',
                    'notes' => 'Stable with consistent effect.',
                ],
                [
                    'id' => 'F4',
                    'category' => 'phase_dead_zones',
                    'severity' => 'MEDIUM',
                    'confidence' => 'LOW',
                    'description' => 'Quiet mid-season window may be under-tuned.',
                    'notes' => 'Paired samples: 2. Seed-specific variance observed.',
                ],
            ],
        ];
    }

    private function seasonConfigWithHoardingSink(int $enabled): array
    {
        return SimulationSeason::build(1, 'baseline-aware-test', [
            'hoarding_sink_enabled' => $enabled,
            'hoarding_min_factor_fp' => 90000,
            'hoarding_tier2_rate_hourly_fp' => 535,
            'hoarding_tier3_rate_hourly_fp' => 1070,
            'hoarding_idle_multiplier_fp' => 1287500,
            'hoarding_safe_hours' => 10,
            'starprice_idle_weight_fp' => 240000,
        ]);
    }

    private function documentTargets(array $document): array
    {
        $targets = [];
        foreach ((array)($document['packages'] ?? []) as $candidate) {
            foreach ((array)($candidate['changes'] ?? []) as $change) {
                $targets[] = (string)($change['target'] ?? '');
            }
        }

        return array_values(array_unique(array_filter($targets, static fn(string $target): bool => $target !== '')));
    }

    private function hasSuppression(array $entries, string $family, string $target, string $reasonCode): bool
    {
        foreach ($entries as $entry) {
            if (
                (string)($entry['family'] ?? '') === $family
                && (string)($entry['target'] ?? '') === $target
                && (string)($entry['reason_code'] ?? '') === $reasonCode
            ) {
                return true;
            }
        }

        return false;
    }

    private function normalizeGeneratedDocument(array $document): array
    {
        $packages = [];
        foreach ((array)($document['packages'] ?? []) as $candidate) {
            $packages[] = [
                'candidate_id' => (string)($candidate['candidate_id'] ?? ''),
                'stage' => (string)($candidate['stage'] ?? ''),
                'targets' => array_values(array_map(static fn(array $change): string => (string)($change['target'] ?? ''), (array)($candidate['changes'] ?? []))),
                'overrides' => (array)($candidate['changes'] ?? []),
            ];
        }

        return [
            'packages' => $packages,
            'stage_reports' => (array)($document['stage_reports'] ?? []),
            'suppression_report' => (array)($document['suppression_report'] ?? []),
            'baseline_context' => (array)($document['baseline_context'] ?? []),
            'metadata' => (array)($document['metadata'] ?? []),
        ];
    }
}
