<?php

require_once __DIR__ . '/../simulation/SimulationSeason.php';
require_once __DIR__ . '/../simulation/CanonicalEconomyConfigContract.php';

class TuningCandidateGenerator
{
    private const STAGE_1 = 'stage_1_single_knob';
    private const STAGE_2 = 'stage_2_pairwise';
    private const STAGE_3 = 'stage_3_constrained_bundle';
    private const STAGE_4 = 'stage_4_full_confirmation';

    private const STAGE_ORDER = [
        self::STAGE_1 => 1,
        self::STAGE_2 => 2,
        self::STAGE_3 => 3,
        self::STAGE_4 => 4,
    ];

    private const STAGE_LIMITS = [
        self::STAGE_2 => 12,
        self::STAGE_3 => 6,
        self::STAGE_4 => 3,
    ];

    public static function generate(array $diagnosis, array $options = []): array
    {
        $tuningVersion = max(1, (int)($options['tuning_version'] ?? 1));
        $seasonConfig = $options['season_config'] ?? null;
        $baselineSeason = $options['baseline_season'] ?? SimulationSeason::build(1, 'baseline-defaults');
        $diagnosisPath = $options['diagnosis_path'] ?? null;
        $generationConstraints = self::resolveGenerationConstraints($seasonConfig, $baselineSeason);

        $findings = array_values((array)($diagnosis['findings'] ?? []));
        $findingsByCategory = self::indexFindingsByCategory($findings);

        $registry = self::tuningRegistry();
        ['multiplier_overrides' => $multiplierOverrides, 'counterweights' => $counterweights] =
            self::versionProfiles($tuningVersion);

        $escalations = [];
        $findingsProcessed = 0;
        $findingsTunable = 0;
        $findingsEscalated = 0;

        foreach ($findingsByCategory as $category => $categoryFindings) {
            $registryEntry = $registry[$category] ?? null;
            $findingsProcessed += count($categoryFindings);

            if ($registryEntry === null) {
                foreach ($categoryFindings as $finding) {
                    $escalations[] = [
                        'finding_id' => $finding['id'] ?? null,
                        'category' => $category,
                        'severity' => $finding['severity'] ?? 'UNKNOWN',
                        'requires_logic_change' => true,
                        'affected_subsystem' => 'unknown',
                        'runtime_path' => 'Unknown category - not in tuning registry',
                        'description' => $finding['description'] ?? '',
                    ];
                    $findingsEscalated++;
                }
                continue;
            }

            if (!(bool)($registryEntry['tunable'] ?? false)) {
                foreach ($categoryFindings as $finding) {
                    $escalations[] = [
                        'finding_id' => $finding['id'] ?? null,
                        'category' => $category,
                        'severity' => $finding['severity'] ?? 'UNKNOWN',
                        'requires_logic_change' => true,
                        'affected_subsystem' => $registryEntry['escalation_info']['subsystem'] ?? 'unknown',
                        'runtime_path' => $registryEntry['escalation_info']['runtime_path'] ?? '',
                        'description' => $finding['description'] ?? '',
                    ];
                    $findingsEscalated++;
                }
                continue;
            }

            $findingsTunable += count($categoryFindings);

            if (isset($registryEntry['escalation_partial'])) {
                foreach ($categoryFindings as $finding) {
                    $escalations[] = [
                        'finding_id' => $finding['id'] ?? null,
                        'category' => $category,
                        'severity' => $finding['severity'] ?? 'UNKNOWN',
                        'requires_logic_change' => true,
                        'affected_subsystem' => $registryEntry['escalation_partial']['subsystem'] ?? 'unknown',
                        'runtime_path' => $registryEntry['escalation_partial']['runtime_path'] ?? '',
                        'description' => 'PARTIAL: Season-column tuning applied, but full fix requires: '
                            . ($registryEntry['escalation_partial']['reason'] ?? ''),
                        'partial' => true,
                    ];
                }
            }
        }

        $stage1Result = self::buildStage1Candidates(
            $findingsByCategory,
            $registry,
            $multiplierOverrides,
            $counterweights,
            $seasonConfig,
            (array)$generationConstraints['effective_baseline'],
            $generationConstraints
        );
        $stage1Candidates = (array)($stage1Result['candidates'] ?? []);
        $suppressions = (array)($stage1Result['suppressions'] ?? []);
        $stage2Candidates = self::buildStage2Candidates($stage1Candidates);
        $stage3Candidates = self::buildStage3Candidates($stage1Candidates, $stage2Candidates);
        $stage4Candidates = self::buildStage4Candidates($stage3Candidates);

        $allCandidates = array_merge($stage1Candidates, $stage2Candidates, $stage3Candidates, $stage4Candidates);
        $scenarios = array_map(static function (array $candidate): array {
            return [
                'name' => $candidate['candidate_id'],
                'description' => $candidate['description'],
                'stage' => $candidate['stage'],
                'stage_order' => $candidate['stage_order'],
                'lineage' => $candidate['lineage'],
                'categories' => $candidate['categories'],
                'overrides' => self::candidateOverrides($candidate),
            ];
        }, $allCandidates);

        $stageReports = self::buildStageReports([
            self::STAGE_1 => $stage1Candidates,
            self::STAGE_2 => $stage2Candidates,
            self::STAGE_3 => $stage3Candidates,
            self::STAGE_4 => $stage4Candidates,
        ], $suppressions);

        $suppressionReport = self::buildSuppressionReport($suppressions);

        return [
            'schema_version' => 'tmc-tuning-candidates.staged.v1',
            'generated_at' => gmdate('c'),
            'diagnosis_report_path' => $diagnosisPath,
            'tuning_version' => $tuningVersion,
            'baseline_context' => [
                'feature_flags' => (array)($generationConstraints['feature_flags'] ?? []),
                'mechanics' => (array)($generationConstraints['mechanics'] ?? []),
                'active_search_space_keys' => array_values((array)($generationConstraints['active_search_space'] ?? [])),
            ],
            'packages' => $allCandidates,
            'stages' => [
                self::STAGE_1 => $stage1Candidates,
                self::STAGE_2 => $stage2Candidates,
                self::STAGE_3 => $stage3Candidates,
                self::STAGE_4 => $stage4Candidates,
            ],
            'stage_reports' => $stageReports,
            'suppression_report' => $suppressionReport,
            'escalations' => $escalations,
            'scenarios' => $scenarios,
            'metadata' => [
                'findings_processed' => $findingsProcessed,
                'findings_tunable' => $findingsTunable,
                'findings_escalated' => $findingsEscalated,
                'findings_skipped' => max(0, $findingsProcessed - $findingsTunable - $findingsEscalated),
                'packages_generated' => count($allCandidates),
                'scenarios_generated' => count($scenarios),
                'suppressed_candidate_families' => (int)($suppressionReport['count'] ?? 0),
                'stage_counts' => array_column($stageReports, 'candidate_count', 'stage'),
                'advancement_blocked_counts' => array_column($stageReports, 'blocked_from_next_stage', 'stage'),
            ],
        ];
    }

    public static function renderMarkdown(array $document): string
    {
        $metadata = (array)($document['metadata'] ?? []);
        $stageReports = (array)($document['stage_reports'] ?? []);
        $baselineContext = (array)($document['baseline_context'] ?? []);
        $suppressionReport = (array)($document['suppression_report'] ?? []);
        $packages = (array)($document['packages'] ?? []);
        $escalations = (array)($document['escalations'] ?? []);
        $scenarios = (array)($document['scenarios'] ?? []);

        $lines = [];
        $lines[] = '# Economy Tuning Candidates';
        $lines[] = '';
        $lines[] = 'Generated: ' . gmdate('Y-m-d H:i:s') . ' UTC';
        if (!empty($document['diagnosis_report_path'])) {
            $lines[] = 'Diagnosis source: `' . (string)$document['diagnosis_report_path'] . '`';
        }
        $lines[] = 'Tuning version: v' . (int)($document['tuning_version'] ?? 1);
        $lines[] = '';
        $lines[] = '## Summary';
        $lines[] = '';
        $lines[] = '| Metric | Value |';
        $lines[] = '|---|---|';
        $lines[] = '| Findings processed | ' . (int)($metadata['findings_processed'] ?? 0) . ' |';
        $lines[] = '| Findings tunable | ' . (int)($metadata['findings_tunable'] ?? 0) . ' |';
        $lines[] = '| Findings escalated | ' . (int)($metadata['findings_escalated'] ?? 0) . ' |';
        $lines[] = '| Candidates generated | ' . (int)($metadata['packages_generated'] ?? 0) . ' |';
        $lines[] = '| Scenarios generated | ' . (int)($metadata['scenarios_generated'] ?? 0) . ' |';
        $lines[] = '| Suppressed candidate families | ' . (int)($metadata['suppressed_candidate_families'] ?? 0) . ' |';
        $lines[] = '';
        if ($baselineContext !== []) {
            $lines[] = '## Baseline Constraints';
            $lines[] = '';
            $featureFlags = (array)($baselineContext['feature_flags'] ?? []);
            if ($featureFlags !== []) {
                $lines[] = '| Feature Flag | Baseline Value | Enabled |';
                $lines[] = '|---|---|---|';
                foreach ($featureFlags as $path => $flagState) {
                    $lines[] = '| `'
                        . (string)$path
                        . '` | '
                        . self::truncateMarkdownValue($flagState['value'] ?? null)
                        . ' | '
                        . (!empty($flagState['enabled']) ? 'yes' : 'no')
                        . ' |';
                }
                $lines[] = '';
            }
        }
        $lines[] = '## Stage Overview';
        $lines[] = '';
        $lines[] = '| Stage | Candidates | Blocked from next stage | Suppressed before generation |';
        $lines[] = '|---|---|---|---|';
        foreach ($stageReports as $report) {
            $lines[] = '| `' . $report['stage'] . '` | '
                . (int)$report['candidate_count']
                . ' | '
                . (int)$report['blocked_from_next_stage']
                . ' | '
                . (int)($report['suppressed_from_generation'] ?? 0)
                . ' |';
        }

        $suppressedEntries = (array)($suppressionReport['entries'] ?? []);
        if ($suppressedEntries !== []) {
            $lines[] = '';
            $lines[] = '## Suppressed Families';
            $lines[] = '';
            $lines[] = '| Stage | Family | Target | Reason |';
            $lines[] = '|---|---|---|---|';
            foreach ($suppressedEntries as $entry) {
                $lines[] = '| `'
                    . (string)($entry['stage'] ?? self::STAGE_1)
                    . '` | `'
                    . (string)($entry['family'] ?? 'unknown')
                    . '` | `'
                    . (string)($entry['target'] ?? 'unknown')
                    . '` | '
                    . self::truncateMarkdownText((string)($entry['reason_detail'] ?? ''), 90)
                    . ' |';
            }
        }

        $grouped = [];
        foreach ($packages as $candidate) {
            $grouped[$candidate['stage']][] = $candidate;
        }

        foreach ([self::STAGE_1, self::STAGE_2, self::STAGE_3, self::STAGE_4] as $stage) {
            $stageCandidates = $grouped[$stage] ?? [];
            if ($stageCandidates === []) {
                continue;
            }

            $lines[] = '';
            $lines[] = '## ' . $stage;
            $lines[] = '';
            foreach ($stageCandidates as $candidate) {
                $controls = (array)($candidate['stage_controls'] ?? []);
                $lineage = (array)($candidate['lineage'] ?? []);
                $lines[] = '### `' . $candidate['candidate_id'] . '`';
                $lines[] = '';
                $lines[] = $candidate['description'];
                $lines[] = '';
                $lines[] = '- Stage: `' . $candidate['stage'] . '`';
                $lines[] = '- Knobs: ' . (int)($candidate['knob_count'] ?? count((array)($candidate['changes'] ?? [])));
                $lines[] = '- Signal score: ' . round((float)($candidate['signal_score'] ?? 0.0), 2);
                $lines[] = '- Risk: ' . (string)($candidate['risk_level'] ?? 'UNKNOWN');
                $lines[] = '- Eligible for next stage: ' . (!empty($controls['eligible_for_next_stage']) ? 'yes' : 'no');
                $lines[] = '- Lineage parents: '
                    . (empty($lineage['parent_candidate_ids'])
                        ? 'none'
                        : implode(', ', (array)$lineage['parent_candidate_ids']));
                if (!empty($controls['reasons'])) {
                    $lines[] = '- Advancement notes: ' . implode('; ', (array)$controls['reasons']);
                }
                $lines[] = '';
                $lines[] = '| Target | Current | Proposed | Finding |';
                $lines[] = '|---|---|---|---|';
                foreach ((array)$candidate['changes'] as $change) {
                    $current = self::truncateMarkdownValue($change['current_value'] ?? null);
                    $proposed = self::truncateMarkdownValue($change['proposed_value'] ?? null);
                    $lines[] = '| `'
                        . (string)$change['target']
                        . '` | '
                        . $current
                        . ' | '
                        . $proposed
                        . ' | '
                        . (string)($change['finding_id'] ?? '')
                        . ' |';
                }
                $lines[] = '';
            }
        }

        if ($escalations !== []) {
            $lines[] = '## Escalations';
            $lines[] = '';
            $lines[] = '| Finding | Category | Severity | Subsystem | Reason |';
            $lines[] = '|---|---|---|---|---|';
            foreach ($escalations as $escalation) {
                $lines[] = '| '
                    . (string)($escalation['finding_id'] ?? '')
                    . ' | '
                    . (string)($escalation['category'] ?? '')
                    . ' | '
                    . (string)($escalation['severity'] ?? '')
                    . ' | '
                    . (string)($escalation['affected_subsystem'] ?? '')
                    . ' | '
                    . self::truncateMarkdownText((string)($escalation['description'] ?? ''), 90)
                    . ' |';
            }
            $lines[] = '';
        }

        if ($scenarios !== []) {
            $lines[] = '## Scenarios';
            $lines[] = '';
            foreach ($scenarios as $scenario) {
                $lines[] = '### `' . (string)$scenario['name'] . '`';
                $lines[] = '';
                $lines[] = '- Stage: `' . (string)$scenario['stage'] . '`';
                $lines[] = '- Categories: ' . implode(', ', (array)$scenario['categories']);
                $lines[] = '';
                $lines[] = '```json';
                $lines[] = json_encode($scenario['overrides'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                $lines[] = '```';
                $lines[] = '';
            }
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private static function buildStage1Candidates(
        array $findingsByCategory,
        array $registry,
        array $multiplierOverrides,
        array $counterweights,
        ?array $seasonConfig,
        array $baselineSeason,
        array $generationConstraints
    ): array {
        $profiles = [];
        $changeCounter = 0;
        $eligiblePrimaryCategories = [];
        $suppressions = [];

        foreach ($findingsByCategory as $category => $categoryFindings) {
            $registryEntry = $registry[$category] ?? null;
            if ($registryEntry === null || !(bool)($registryEntry['tunable'] ?? false)) {
                continue;
            }

            foreach ($categoryFindings as $finding) {
                foreach ((array)($registryEntry['targets'] ?? []) as $target) {
                    $key = (string)$target['key'];
                    $feasibility = self::evaluateTargetFeasibility($target, $generationConstraints);
                    if (!(bool)($feasibility['eligible'] ?? false)) {
                        self::recordSuppression($suppressions, [
                            'stage' => self::STAGE_1,
                            'family' => $category,
                            'target' => $key,
                            'mechanic' => (string)($target['mechanic'] ?? 'unknown'),
                            'source_kind' => 'primary',
                            'reason_code' => (string)($feasibility['reason_code'] ?? 'suppressed'),
                            'reason_detail' => (string)($feasibility['reason_detail'] ?? 'Candidate family is not baseline-feasible.'),
                            'subsystem' => (string)($feasibility['subsystem'] ?? 'unknown'),
                            'finding_ids' => [$finding['id'] ?? $category],
                        ]);
                        continue;
                    }

                    $currentValue = self::getBaselineValue($key, $seasonConfig, $baselineSeason);
                    $tier = self::recommendedTier($finding);
                    $proposedValue = self::computeProposedValue($target, $currentValue, $tier, $category, $multiplierOverrides);
                    $riskLevel = self::riskLevel($target, $currentValue, $proposedValue, $tier);
                    $signalHints = self::extractSignalHints($finding);
                    $eligiblePrimaryCategories[$category] = true;

                    if (!isset($profiles[$key])) {
                        $profiles[$key] = [
                            'target' => $key,
                            'current_value' => $currentValue,
                            'supports' => [],
                        ];
                    }

                    $profiles[$key]['supports'][] = [
                        'diagnostic_category' => $category,
                        'scenario_categories' => (array)($registryEntry['categories'] ?? []),
                        'finding_id' => $finding['id'] ?? $category,
                        'finding' => $finding,
                        'target' => $target,
                        'tier' => $tier,
                        'direction' => (string)($target['direction'] ?? 'increase'),
                        'proposed_value' => $proposedValue,
                        'risk_level' => $riskLevel,
                        'signal_hints' => $signalHints,
                        'support_kind' => 'primary',
                    ];
                }
            }
        }

        foreach ($counterweights as $counterweightName => $counterweight) {
            $triggerCategories = array_values(array_unique((array)($counterweight['trigger_categories'] ?? [])));
            $eligibleTriggerCategories = array_values(array_filter($triggerCategories, static function (string $triggerCategory) use ($eligiblePrimaryCategories): bool {
                return !empty($eligiblePrimaryCategories[$triggerCategory]);
            }));

            if ($eligibleTriggerCategories === []) {
                foreach ((array)($counterweight['targets'] ?? []) as $target) {
                    self::recordSuppression($suppressions, [
                        'stage' => self::STAGE_1,
                        'family' => (string)$counterweightName,
                        'target' => (string)($target['key'] ?? 'unknown'),
                        'mechanic' => (string)($target['mechanic'] ?? 'unknown'),
                        'source_kind' => 'counterweight',
                        'reason_code' => 'stage_ineligible_candidate_dimension',
                        'reason_detail' => 'Counterweight dimension has no active primary trigger lane after baseline search-space filtering.',
                        'subsystem' => (string)($target['mechanic'] ?? 'unknown'),
                        'finding_ids' => [],
                    ]);
                }
                continue;
            }

            $supportingFindings = [];
            foreach ($eligibleTriggerCategories as $triggerCategory) {
                foreach ((array)($findingsByCategory[$triggerCategory] ?? []) as $finding) {
                    $supportingFindings[] = [
                        'category' => $triggerCategory,
                        'finding' => $finding,
                    ];
                }
            }

            if ($supportingFindings === []) {
                continue;
            }

            usort($supportingFindings, static function (array $left, array $right): int {
                return self::supportPriority($right['finding']) <=> self::supportPriority($left['finding']);
            });
            $primarySupport = $supportingFindings[0];
            $primaryFinding = $primarySupport['finding'];

            foreach ((array)($counterweight['targets'] ?? []) as $target) {
                $key = (string)$target['key'];
                $feasibility = self::evaluateTargetFeasibility($target, $generationConstraints);
                if (!(bool)($feasibility['eligible'] ?? false)) {
                    self::recordSuppression($suppressions, [
                        'stage' => self::STAGE_1,
                        'family' => (string)$counterweightName,
                        'target' => $key,
                        'mechanic' => (string)($target['mechanic'] ?? 'unknown'),
                        'source_kind' => 'counterweight',
                        'reason_code' => (string)($feasibility['reason_code'] ?? 'suppressed'),
                        'reason_detail' => (string)($feasibility['reason_detail'] ?? 'Counterweight target is not baseline-feasible.'),
                        'subsystem' => (string)($feasibility['subsystem'] ?? 'unknown'),
                        'finding_ids' => [$primaryFinding['id'] ?? $counterweightName],
                    ]);
                    continue;
                }

                $currentValue = self::getBaselineValue($key, $seasonConfig, $baselineSeason);
                $tier = self::recommendedTier($primaryFinding);
                $proposedValue = self::computeCounterweightValue($target, $currentValue, $tier);
                $riskLevel = self::riskLevel($target, $currentValue, $proposedValue, $tier);
                $signalHints = self::extractSignalHints($primaryFinding);

                if (!isset($profiles[$key])) {
                    $profiles[$key] = [
                        'target' => $key,
                        'current_value' => $currentValue,
                        'supports' => [],
                    ];
                }

                $profiles[$key]['supports'][] = [
                    'diagnostic_category' => (string)$primarySupport['category'],
                    'scenario_categories' => (array)($target['categories'] ?? ['star_conversion_pricing', 'lock_in_expiry_incentives']),
                    'finding_id' => ($primaryFinding['id'] ?? $counterweightName) . ':counterweight',
                    'finding' => $primaryFinding,
                    'target' => $target,
                    'tier' => $tier,
                    'direction' => (string)($target['direction'] ?? 'increase'),
                    'proposed_value' => $proposedValue,
                    'risk_level' => $riskLevel,
                    'signal_hints' => $signalHints,
                    'support_kind' => 'counterweight',
                    'counterweight_name' => $counterweightName,
                ];
            }
        }

        $candidates = [];
        foreach ($profiles as $profile) {
            $candidate = self::finalizeStage1Candidate($profile, $changeCounter);
            $changeCounter++;
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        usort($candidates, static function (array $left, array $right): int {
            $signal = ($right['signal_score'] <=> $left['signal_score']);
            if ($signal !== 0) {
                return $signal;
            }
            return strcmp((string)$left['candidate_id'], (string)$right['candidate_id']);
        });

        return [
            'candidates' => $candidates,
            'suppressions' => array_values($suppressions),
        ];
    }

    private static function finalizeStage1Candidate(array $profile, int $ordinal): ?array
    {
        $supports = (array)($profile['supports'] ?? []);
        if ($supports === []) {
            return null;
        }

        usort($supports, static function (array $left, array $right): int {
            return self::supportPriority($right['finding']) <=> self::supportPriority($left['finding']);
        });

        $primary = $supports[0];
        $currentValue = $profile['current_value'];
        $proposedValue = $primary['proposed_value'];
        $directionSet = [];
        $signalScore = 0.0;
        $reasons = [];
        $categories = [];
        $diagnosticCategories = [];
        $mechanics = [];
        $findingIds = [];
        $hasCounterweightSupport = false;
        $maxRisk = $primary['risk_level'];

        foreach ($supports as $support) {
            $directionSet[(string)$support['direction']] = true;
            $signalScore += self::supportPriority($support['finding']);
            foreach ((array)($support['scenario_categories'] ?? []) as $scenarioCategory) {
                $categories[(string)$scenarioCategory] = true;
            }
            $diagnosticCategories[(string)($support['diagnostic_category'] ?? 'unknown')] = true;
            $mechanics[(string)($support['target']['mechanic'] ?? 'unknown')] = true;
            $findingIds[] = (string)$support['finding_id'];
            if (($support['support_kind'] ?? 'primary') === 'counterweight') {
                $hasCounterweightSupport = true;
            }
            if (self::riskRank($support['risk_level']) > self::riskRank($maxRisk)) {
                $maxRisk = $support['risk_level'];
            }
            $reasons = array_merge($reasons, (array)($support['signal_hints']['reasons'] ?? []));
        }

        $reasons = array_values(array_unique($reasons));
        $lowSignal = false;
        $unstable = false;

        if ($signalScore < 5.0) {
            $lowSignal = true;
            $reasons[] = 'signal score below automatic promotion threshold';
        }

        if (count($directionSet) > 1) {
            $unstable = true;
            $reasons[] = 'conflicting direction recommendations for the same knob';
        }

        if (in_array('low_confidence', $reasons, true) || in_array('paired_samples_hint', $reasons, true)) {
            $lowSignal = true;
        }

        if (in_array('variance_or_instability_hint', $reasons, true) || in_array('seed_specific_hint', $reasons, true)) {
            $unstable = true;
        }

        if (self::riskRank($maxRisk) >= self::riskRank('HIGH')) {
            $reasons[] = 'high-risk knob held for isolated stage-1 learning only';
        }

        $eligibleForNextStage = !$lowSignal && !$unstable && self::riskRank($maxRisk) < self::riskRank('HIGH');
        $candidateId = 'stage1-' . self::sanitize((string)$profile['target']) . '-' . str_pad((string)($ordinal + 1), 2, '0', STR_PAD_LEFT);

        $change = [
            'change_id' => 'S1-' . str_pad((string)($ordinal + 1), 3, '0', STR_PAD_LEFT),
            'finding_id' => implode(',', array_values(array_unique($findingIds))),
            'type' => (string)($primary['target']['type'] ?? 'config_value'),
            'target' => (string)$profile['target'],
            'current_value' => $currentValue,
            'proposed_value' => $proposedValue,
            'reason' => (string)($primary['finding']['description'] ?? ''),
            'impacted_mechanic' => (string)($primary['target']['mechanic'] ?? 'unknown'),
            'expected_player_effect' => (string)($primary['target']['player_effect'] ?? ''),
            'expected_economy_effect' => (string)($primary['target']['economy_effect'] ?? ''),
            'risk_level' => $maxRisk,
            'simulation_test' => $candidateId . ': isolated single-knob probe',
            'source_file' => 'schema.sql (seasons table column)',
            'source_surface' => 'season_column',
            'confidence' => (string)($primary['finding']['confidence'] ?? 'MEDIUM'),
            'notes' => (string)($primary['finding']['notes'] ?? ''),
            'support_kind' => $hasCounterweightSupport ? 'mixed' : (string)($primary['support_kind'] ?? 'primary'),
            'source_categories' => array_keys($categories),
        ];

        return [
            'candidate_id' => $candidateId,
            'package_name' => $candidateId,
            'description' => 'Single-knob learning pass for `' . $profile['target'] . '`.',
            'stage' => self::STAGE_1,
            'stage_order' => self::STAGE_ORDER[self::STAGE_1],
            'knob_count' => 1,
            'categories' => array_keys($categories),
            'diagnostic_categories' => array_keys($diagnosticCategories),
            'mechanics' => array_keys($mechanics),
            'changes' => [$change],
            'risk_level' => $maxRisk,
            'signal_score' => round($signalScore, 2),
            'supporting_findings' => array_values(array_unique($findingIds)),
            'stage_controls' => [
                'eligible_for_next_stage' => $eligibleForNextStage,
                'reasons' => array_values(array_unique($reasons)),
                'blocked_due_to_low_signal' => $lowSignal,
                'blocked_due_to_instability' => $unstable,
            ],
            'lineage' => [
                'root_knobs' => [(string)$profile['target']],
                'parent_candidate_ids' => [],
                'ancestor_candidate_ids' => [],
                'validated_candidate_ids' => [],
            ],
        ];
    }

    private static function buildStage2Candidates(array $stage1Candidates): array
    {
        $eligible = array_values(array_filter($stage1Candidates, static function (array $candidate): bool {
            return (bool)($candidate['stage_controls']['eligible_for_next_stage'] ?? false);
        }));

        $pairs = [];
        $count = count($eligible);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $pair = self::makeStage2Pair($eligible[$i], $eligible[$j]);
                if ($pair !== null) {
                    $pairs[] = $pair;
                }
            }
        }

        usort($pairs, static function (array $left, array $right): int {
            $signal = ($right['signal_score'] <=> $left['signal_score']);
            if ($signal !== 0) {
                return $signal;
            }
            return strcmp((string)$left['candidate_id'], (string)$right['candidate_id']);
        });

        return array_slice($pairs, 0, self::STAGE_LIMITS[self::STAGE_2]);
    }

    private static function makeStage2Pair(array $left, array $right): ?array
    {
        $leftChange = (array)$left['changes'][0];
        $rightChange = (array)$right['changes'][0];
        if (($leftChange['target'] ?? null) === ($rightChange['target'] ?? null)) {
            return null;
        }

        $sharedCategories = array_values(array_intersect((array)$left['categories'], (array)$right['categories']));
        $sharedMechanics = array_values(array_intersect((array)$left['mechanics'], (array)$right['mechanics']));
        $hasCounterweight = in_array('lock_in_expiry_incentives', (array)$left['categories'], true)
            || in_array('lock_in_expiry_incentives', (array)$right['categories'], true)
            || in_array('star_conversion_pricing', (array)$left['categories'], true)
            || in_array('star_conversion_pricing', (array)$right['categories'], true);

        if ($sharedCategories === [] && $sharedMechanics === [] && !$hasCounterweight) {
            return null;
        }

        $pairSignal = ((float)$left['signal_score'] + (float)$right['signal_score']) / 2.0;
        $riskLevel = self::maxRiskLevel([(string)$left['risk_level'], (string)$right['risk_level']]);
        $eligibleForNextStage = self::riskRank($riskLevel) < self::riskRank('HIGH')
            && ($sharedCategories !== [] || $hasCounterweight)
            && $pairSignal >= 5.0;

        $reasons = [];
        if ($sharedCategories === [] && !$hasCounterweight) {
            $reasons[] = 'pair lacks a shared diagnostic lane or stabilizing counterweight';
        }
        if (self::riskRank($riskLevel) >= self::riskRank('HIGH')) {
            $reasons[] = 'combined pair risk is too high for automatic bundle promotion';
        }

        $candidateId = 'stage2-'
            . self::sanitize((string)$leftChange['target'])
            . '-'
            . self::sanitize((string)$rightChange['target']);

        return [
            'candidate_id' => $candidateId,
            'package_name' => $candidateId,
            'description' => 'Pairwise validation for `'
                . (string)$leftChange['target']
                . '` + `'
                . (string)$rightChange['target']
                . '`.',
            'stage' => self::STAGE_2,
            'stage_order' => self::STAGE_ORDER[self::STAGE_2],
            'knob_count' => 2,
            'categories' => array_values(array_unique(array_merge((array)$left['categories'], (array)$right['categories']))),
            'diagnostic_categories' => array_values(array_unique(array_merge(
                (array)($left['diagnostic_categories'] ?? []),
                (array)($right['diagnostic_categories'] ?? [])
            ))),
            'mechanics' => array_values(array_unique(array_merge((array)$left['mechanics'], (array)$right['mechanics']))),
            'changes' => [$leftChange, $rightChange],
            'risk_level' => $riskLevel,
            'signal_score' => round($pairSignal, 2),
            'supporting_findings' => array_values(array_unique(array_merge(
                (array)$left['supporting_findings'],
                (array)$right['supporting_findings']
            ))),
            'stage_controls' => [
                'eligible_for_next_stage' => $eligibleForNextStage,
                'reasons' => $reasons,
                'blocked_due_to_low_signal' => $pairSignal < 5.0,
                'blocked_due_to_instability' => false,
            ],
            'lineage' => [
                'root_knobs' => [(string)$leftChange['target'], (string)$rightChange['target']],
                'parent_candidate_ids' => [(string)$left['candidate_id'], (string)$right['candidate_id']],
                'ancestor_candidate_ids' => [(string)$left['candidate_id'], (string)$right['candidate_id']],
                'validated_candidate_ids' => [(string)$left['candidate_id'], (string)$right['candidate_id']],
            ],
        ];
    }

    private static function buildStage3Candidates(array $stage1Candidates, array $stage2Candidates): array
    {
        $eligiblePairs = array_values(array_filter($stage2Candidates, static function (array $candidate): bool {
            return (bool)($candidate['stage_controls']['eligible_for_next_stage'] ?? false);
        }));

        $stage1ById = [];
        foreach ($stage1Candidates as $candidate) {
            $stage1ById[$candidate['candidate_id']] = $candidate;
        }

        $pairGraph = [];
        $pairLookup = [];
        foreach ($eligiblePairs as $pair) {
            $parents = array_values((array)($pair['lineage']['parent_candidate_ids'] ?? []));
            if (count($parents) !== 2) {
                continue;
            }
            sort($parents);
            [$a, $b] = $parents;
            $pairGraph[$a][$b] = true;
            $pairGraph[$b][$a] = true;
            $pairLookup[$a . '|' . $b] = $pair;
        }

        $nodes = array_keys($pairGraph);
        $bundles = [];
        $bundleSeen = [];
        $nodeCount = count($nodes);
        for ($i = 0; $i < $nodeCount; $i++) {
            for ($j = $i + 1; $j < $nodeCount; $j++) {
                for ($k = $j + 1; $k < $nodeCount; $k++) {
                    $triple = [$nodes[$i], $nodes[$j], $nodes[$k]];
                    sort($triple);
                    if (
                        empty($pairGraph[$triple[0]][$triple[1]])
                        || empty($pairGraph[$triple[0]][$triple[2]])
                        || empty($pairGraph[$triple[1]][$triple[2]])
                    ) {
                        continue;
                    }

                    $bundleKey = implode('|', $triple);
                    if (isset($bundleSeen[$bundleKey])) {
                        continue;
                    }
                    $bundleSeen[$bundleKey] = true;

                    $changes = [];
                    $categories = [];
                    $diagnosticCategories = [];
                    $mechanics = [];
                    $supportingFindings = [];
                    $risks = [];
                    $signalScores = [];
                    foreach ($triple as $stage1Id) {
                        if (!isset($stage1ById[$stage1Id])) {
                            continue 2;
                        }
                        $root = $stage1ById[$stage1Id];
                        $changes[] = (array)$root['changes'][0];
                        $categories = array_merge($categories, (array)$root['categories']);
                        $diagnosticCategories = array_merge($diagnosticCategories, (array)($root['diagnostic_categories'] ?? []));
                        $mechanics = array_merge($mechanics, (array)$root['mechanics']);
                        $supportingFindings = array_merge($supportingFindings, (array)$root['supporting_findings']);
                        $risks[] = (string)$root['risk_level'];
                        $signalScores[] = (float)$root['signal_score'];
                    }

                    $pairIds = [];
                    foreach ([[0, 1], [0, 2], [1, 2]] as $indexes) {
                        $pairIds[] = (string)$pairLookup[$triple[$indexes[0]] . '|' . $triple[$indexes[1]]]['candidate_id'];
                    }

                    $riskLevel = self::maxRiskLevel($risks);
                    $signalScore = self::mean($signalScores);
                    $eligibleForNextStage = self::riskRank($riskLevel) < self::riskRank('HIGH') && $signalScore >= 5.5;
                    $candidateId = 'stage3-' . self::sanitize(implode('-', array_map(static function (array $change): string {
                        return (string)$change['target'];
                    }, $changes)));

                    $bundles[] = [
                        'candidate_id' => $candidateId,
                        'package_name' => $candidateId,
                        'description' => 'Constrained 3-knob bundle built only from pairwise-validated knobs.',
                        'stage' => self::STAGE_3,
                        'stage_order' => self::STAGE_ORDER[self::STAGE_3],
                        'knob_count' => count($changes),
                        'categories' => array_values(array_unique($categories)),
                        'diagnostic_categories' => array_values(array_unique($diagnosticCategories)),
                        'mechanics' => array_values(array_unique($mechanics)),
                        'changes' => $changes,
                        'risk_level' => $riskLevel,
                        'signal_score' => round($signalScore, 2),
                        'supporting_findings' => array_values(array_unique($supportingFindings)),
                        'stage_controls' => [
                            'eligible_for_next_stage' => $eligibleForNextStage,
                            'reasons' => $eligibleForNextStage ? [] : ['bundle retained for analysis but not auto-promoted to confirmation'],
                            'blocked_due_to_low_signal' => $signalScore < 5.5,
                            'blocked_due_to_instability' => false,
                        ],
                        'lineage' => [
                            'root_knobs' => array_map(static fn(array $change): string => (string)$change['target'], $changes),
                            'parent_candidate_ids' => $pairIds,
                            'ancestor_candidate_ids' => array_values(array_unique(array_merge($triple, $pairIds))),
                            'validated_candidate_ids' => array_values(array_unique(array_merge($triple, $pairIds))),
                        ],
                    ];
                }
            }
        }

        usort($bundles, static function (array $left, array $right): int {
            $signal = ($right['signal_score'] <=> $left['signal_score']);
            if ($signal !== 0) {
                return $signal;
            }
            return strcmp((string)$left['candidate_id'], (string)$right['candidate_id']);
        });

        return array_slice($bundles, 0, self::STAGE_LIMITS[self::STAGE_3]);
    }

    private static function buildStage4Candidates(array $stage3Candidates): array
    {
        $eligibleBundles = array_values(array_filter($stage3Candidates, static function (array $candidate): bool {
            return (bool)($candidate['stage_controls']['eligible_for_next_stage'] ?? false);
        }));

        $confirmation = [];
        foreach (array_slice($eligibleBundles, 0, self::STAGE_LIMITS[self::STAGE_4]) as $bundle) {
            $candidateId = 'stage4-' . self::sanitize((string)$bundle['candidate_id']);
            $confirmation[] = [
                'candidate_id' => $candidateId,
                'package_name' => $candidateId,
                'description' => 'Full confirmation candidate promoted from constrained bundle `' . $bundle['candidate_id'] . '`.',
                'stage' => self::STAGE_4,
                'stage_order' => self::STAGE_ORDER[self::STAGE_4],
                'knob_count' => (int)$bundle['knob_count'],
                'categories' => (array)$bundle['categories'],
                'diagnostic_categories' => (array)($bundle['diagnostic_categories'] ?? []),
                'mechanics' => (array)$bundle['mechanics'],
                'changes' => (array)$bundle['changes'],
                'risk_level' => (string)$bundle['risk_level'],
                'signal_score' => (float)$bundle['signal_score'],
                'supporting_findings' => (array)$bundle['supporting_findings'],
                'stage_controls' => [
                    'eligible_for_next_stage' => false,
                    'reasons' => ['final confirmation stage'],
                    'blocked_due_to_low_signal' => false,
                    'blocked_due_to_instability' => false,
                ],
                'lineage' => [
                    'root_knobs' => (array)$bundle['lineage']['root_knobs'],
                    'parent_candidate_ids' => [(string)$bundle['candidate_id']],
                    'ancestor_candidate_ids' => array_values(array_unique(array_merge(
                        [(string)$bundle['candidate_id']],
                        (array)$bundle['lineage']['ancestor_candidate_ids']
                    ))),
                    'validated_candidate_ids' => array_values(array_unique(array_merge(
                        [(string)$bundle['candidate_id']],
                        (array)$bundle['lineage']['validated_candidate_ids']
                    ))),
                ],
            ];
        }

        return $confirmation;
    }

    private static function buildStageReports(array $stages, array $suppressions): array
    {
        $suppressedByStage = [];
        foreach ($suppressions as $suppression) {
            $stage = (string)($suppression['stage'] ?? self::STAGE_1);
            $suppressedByStage[$stage] = ($suppressedByStage[$stage] ?? 0) + 1;
        }

        $reports = [];
        foreach ($stages as $stage => $candidates) {
            $blocked = 0;
            foreach ($candidates as $candidate) {
                if (!(bool)($candidate['stage_controls']['eligible_for_next_stage'] ?? false)) {
                    $blocked++;
                }
            }

            $reports[] = [
                'stage' => $stage,
                'candidate_count' => count($candidates),
                'blocked_from_next_stage' => $blocked,
                'suppressed_from_generation' => (int)($suppressedByStage[$stage] ?? 0),
            ];
        }

        return $reports;
    }

    private static function buildSuppressionReport(array $suppressions): array
    {
        $byReason = [];
        $byStage = [];
        foreach ($suppressions as $suppression) {
            $reason = (string)($suppression['reason_code'] ?? 'suppressed');
            $stage = (string)($suppression['stage'] ?? self::STAGE_1);
            $byReason[$reason] = ($byReason[$reason] ?? 0) + 1;
            $byStage[$stage] = ($byStage[$stage] ?? 0) + 1;
        }

        ksort($byReason);
        ksort($byStage);

        return [
            'count' => count($suppressions),
            'by_reason' => $byReason,
            'by_stage' => $byStage,
            'entries' => array_values($suppressions),
        ];
    }

    private static function candidateOverrides(array $candidate): array
    {
        $overrides = [];
        foreach ((array)($candidate['changes'] ?? []) as $change) {
            if (!isset($change['target'])) {
                continue;
            }
            $overrides[(string)$change['target']] = $change['proposed_value'] ?? null;
        }
        return $overrides;
    }

    private static function recommendedTier(array $finding): string
    {
        $severity = strtoupper((string)($finding['severity'] ?? 'MEDIUM'));
        $confidence = strtoupper((string)($finding['confidence'] ?? 'MEDIUM'));

        if ($severity === 'HIGH' && $confidence === 'HIGH') {
            return 'balanced';
        }

        return 'conservative';
    }

    private static function computeProposedValue(array $target, $currentValue, string $tier, string $category, array $multiplierOverrides)
    {
        if ($currentValue === null) {
            return null;
        }

        $mode = (string)($target['mode'] ?? '');
        if ($mode === 'multiply' && is_numeric($currentValue)) {
            $factor = $target[$tier] ?? 1.0;
            if (isset($multiplierOverrides[$category][(string)$target['key']][$tier])) {
                $factor = $multiplierOverrides[$category][(string)$target['key']][$tier];
            }

            $raw = (float)$currentValue * (float)$factor;
            return is_int($currentValue) ? (int)round($raw) : $raw;
        }

        if ($mode === 'vault_tuning') {
            $vaultMode = $target[$tier] ?? null;
            return $vaultMode === null ? $currentValue : self::tuneVaultConfig($currentValue, (string)$vaultMode);
        }

        return $currentValue;
    }

    private static function computeCounterweightValue(array $target, $currentValue, string $tier)
    {
        if ($currentValue === null || !is_numeric($currentValue)) {
            return $currentValue;
        }

        $factor = (float)($target[$tier] ?? 1.0);
        $raw = (float)$currentValue * $factor;
        return is_int($currentValue) ? (int)round($raw) : $raw;
    }

    private static function riskLevel(array $target, $currentValue, $proposedValue, string $tier): string
    {
        if ((string)($target['mode'] ?? '') === 'vault_tuning') {
            return $tier === 'balanced' ? 'MEDIUM' : 'LOW';
        }

        if (!is_numeric($currentValue) || !is_numeric($proposedValue) || (float)$currentValue === 0.0) {
            return 'LOW';
        }

        $ratio = abs(((float)$proposedValue - (float)$currentValue) / (float)$currentValue);
        if ($ratio > 0.30) {
            return 'HIGH';
        }
        if ($ratio > 0.15) {
            return 'MEDIUM';
        }
        return 'LOW';
    }

    private static function maxRiskLevel(array $levels): string
    {
        $best = 'LOW';
        foreach ($levels as $level) {
            if (self::riskRank((string)$level) > self::riskRank($best)) {
                $best = (string)$level;
            }
        }
        return $best;
    }

    private static function riskRank(string $riskLevel): int
    {
        return match (strtoupper($riskLevel)) {
            'HIGH' => 3,
            'MEDIUM' => 2,
            default => 1,
        };
    }

    private static function supportPriority(array $finding): int
    {
        $severityScore = match (strtoupper((string)($finding['severity'] ?? 'LOW'))) {
            'HIGH' => 3,
            'MEDIUM' => 2,
            default => 1,
        };

        $confidenceScore = match (strtoupper((string)($finding['confidence'] ?? 'LOW'))) {
            'HIGH' => 2,
            'MEDIUM' => 1,
            default => 0,
        };

        return $severityScore * 10 + $confidenceScore;
    }

    private static function extractSignalHints(array $finding): array
    {
        $notes = strtolower((string)($finding['notes'] ?? ''));
        $description = strtolower((string)($finding['description'] ?? ''));
        $combined = $notes . ' ' . $description;

        $reasons = [];
        if (strtoupper((string)($finding['confidence'] ?? '')) === 'LOW') {
            $reasons[] = 'low_confidence';
        }
        if (str_contains($combined, 'paired samples')) {
            $reasons[] = 'paired_samples_hint';
        }
        if (str_contains($combined, 'cross-seed') || str_contains($combined, 'variance') || str_contains($combined, 'instability')) {
            $reasons[] = 'variance_or_instability_hint';
        }
        if (str_contains($combined, 'seed-specific') || str_contains($combined, 'seed specific')) {
            $reasons[] = 'seed_specific_hint';
        }

        return ['reasons' => $reasons];
    }

    private static function getBaselineValue(string $key, ?array $seasonConfig, array $baselineSeason)
    {
        if ($seasonConfig !== null && array_key_exists($key, $seasonConfig)) {
            return $seasonConfig[$key];
        }
        if (array_key_exists($key, $baselineSeason)) {
            return $baselineSeason[$key];
        }
        return null;
    }

    private static function resolveGenerationConstraints(?array $seasonConfig, array $baselineSeason): array
    {
        $effectiveBaseline = array_replace($baselineSeason, is_array($seasonConfig) ? $seasonConfig : []);
        $surfaceMeta = CanonicalEconomyConfigContract::validatorSurfaceMeta();
        $featureFlags = [];
        $activeSearchSpace = [];

        foreach ($surfaceMeta as $key => $meta) {
            $featureFlagPath = (string)($meta['feature_flag'] ?? '');
            if ($featureFlagPath !== '' && !isset($featureFlags[$featureFlagPath])) {
                $featureFlags[$featureFlagPath] = self::baselineFlagState($featureFlagPath, $effectiveBaseline);
            }

            if ($featureFlagPath === '' || !empty($featureFlags[$featureFlagPath]['enabled'])) {
                $activeSearchSpace[$key] = $key;
            }
        }

        return [
            'effective_baseline' => $effectiveBaseline,
            'surface_meta' => $surfaceMeta,
            'feature_flags' => $featureFlags,
            'mechanics' => self::baselineMechanicStates($effectiveBaseline),
            'active_search_space' => $activeSearchSpace,
        ];
    }

    private static function baselineFlagState(string $path, array $baselineSeason): array
    {
        $key = str_starts_with($path, 'season.') ? substr($path, 7) : $path;
        $value = $baselineSeason[$key] ?? null;
        $enabled = false;

        if (is_bool($value)) {
            $enabled = $value;
        } elseif (is_numeric($value)) {
            $enabled = ((int)$value) !== 0;
        }

        return [
            'path' => $path,
            'key' => $key,
            'value' => $value,
            'enabled' => $enabled,
        ];
    }

    private static function baselineMechanicStates(array $baselineSeason): array
    {
        return [
            'hoarding_sink' => [
                'enabled' => ((int)($baselineSeason['hoarding_sink_enabled'] ?? 0)) === 1,
                'path' => 'season.hoarding_sink_enabled',
                'value' => $baselineSeason['hoarding_sink_enabled'] ?? null,
            ],
            'ubi' => ['enabled' => true, 'path' => null, 'value' => null],
            'boost_economy' => ['enabled' => true, 'path' => null, 'value' => null],
            'star_pricing' => ['enabled' => true, 'path' => null, 'value' => null],
            'sigil_vault' => ['enabled' => true, 'path' => null, 'value' => null],
        ];
    }

    private static function evaluateTargetFeasibility(array $target, array $generationConstraints): array
    {
        $key = (string)($target['key'] ?? '');
        $surfaceMeta = (array)($generationConstraints['surface_meta'] ?? []);
        if ($key === '' || !isset($surfaceMeta[$key])) {
            return [
                'eligible' => false,
                'reason_code' => 'knob_out_of_active_search_space',
                'reason_detail' => 'Knob is not part of the canonical tuning surface.',
                'subsystem' => 'unknown',
            ];
        }

        $meta = (array)$surfaceMeta[$key];
        $featureFlagPath = (string)($meta['feature_flag'] ?? '');
        if ($featureFlagPath !== '') {
            $flagState = (array)(($generationConstraints['feature_flags'][$featureFlagPath] ?? null)
                ?: self::baselineFlagState($featureFlagPath, (array)($generationConstraints['effective_baseline'] ?? [])));
            if (empty($flagState['enabled'])) {
                return [
                    'eligible' => false,
                    'reason_code' => 'knob_out_of_active_search_space',
                    'reason_detail' => sprintf(
                        'Knob is outside the active search space because `%s` resolves to %s in the baseline.',
                        $featureFlagPath,
                        json_encode($flagState['value'] ?? null, JSON_UNESCAPED_SLASHES)
                    ),
                    'subsystem' => (string)($meta['subsystem'] ?? 'unknown'),
                ];
            }
        }

        $mechanic = (string)($target['mechanic'] ?? '');
        $mechanicState = (array)(($generationConstraints['mechanics'][$mechanic] ?? null)
            ?: ['enabled' => true, 'path' => null, 'value' => null]);
        if (empty($mechanicState['enabled'])) {
            return [
                'eligible' => false,
                'reason_code' => 'subsystem_disabled_in_baseline',
                'reason_detail' => sprintf(
                    'Subsystem is disabled in the baseline because `%s` resolves to %s.',
                    (string)($mechanicState['path'] ?? 'unknown'),
                    json_encode($mechanicState['value'] ?? null, JSON_UNESCAPED_SLASHES)
                ),
                'subsystem' => (string)($meta['subsystem'] ?? $mechanic ?: 'unknown'),
            ];
        }

        return [
            'eligible' => true,
            'reason_code' => null,
            'reason_detail' => null,
            'subsystem' => (string)($meta['subsystem'] ?? 'unknown'),
        ];
    }

    private static function recordSuppression(array &$suppressions, array $entry): void
    {
        $findingIds = array_values(array_unique(array_filter(array_map('strval', (array)($entry['finding_ids'] ?? [])))));
        sort($findingIds);
        $key = implode('|', [
            (string)($entry['stage'] ?? self::STAGE_1),
            (string)($entry['family'] ?? 'unknown'),
            (string)($entry['target'] ?? 'unknown'),
            (string)($entry['reason_code'] ?? 'suppressed'),
        ]);

        if (!isset($suppressions[$key])) {
            $suppressions[$key] = [
                'stage' => (string)($entry['stage'] ?? self::STAGE_1),
                'family' => (string)($entry['family'] ?? 'unknown'),
                'target' => (string)($entry['target'] ?? 'unknown'),
                'mechanic' => (string)($entry['mechanic'] ?? 'unknown'),
                'source_kind' => (string)($entry['source_kind'] ?? 'primary'),
                'reason_code' => (string)($entry['reason_code'] ?? 'suppressed'),
                'reason_detail' => (string)($entry['reason_detail'] ?? 'Candidate family was suppressed.'),
                'subsystem' => (string)($entry['subsystem'] ?? 'unknown'),
                'finding_ids' => $findingIds,
            ];
            return;
        }

        $suppressions[$key]['finding_ids'] = array_values(array_unique(array_merge(
            (array)$suppressions[$key]['finding_ids'],
            $findingIds
        )));
        sort($suppressions[$key]['finding_ids']);
    }

    private static function indexFindingsByCategory(array $findings): array
    {
        $indexed = [];
        foreach ($findings as $finding) {
            $category = (string)($finding['category'] ?? 'unknown');
            $indexed[$category][] = $finding;
        }
        return $indexed;
    }

    private static function mean(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        return array_sum($values) / count($values);
    }

    private static function sanitize(string $value): string
    {
        return trim((string)preg_replace('/[^A-Za-z0-9_-]+/', '-', $value), '-');
    }

    private static function truncateMarkdownValue($value): string
    {
        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_SLASHES);
        }
        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }
        return self::truncateMarkdownText((string)$value, 30);
    }

    private static function truncateMarkdownText(string $value, int $maxLength): string
    {
        return strlen($value) > $maxLength ? substr($value, 0, $maxLength - 3) . '...' : $value;
    }

    private static function tuneVaultConfig($currentVaultJson, string $mode): string
    {
        $vault = is_string($currentVaultJson) ? json_decode($currentVaultJson, true) : $currentVaultJson;
        if (!is_array($vault)) {
            return is_string($currentVaultJson) ? $currentVaultJson : json_encode($currentVaultJson);
        }

        $multipliers = [
            'vault_supply_small' => ['supply' => 1.15, 'cost' => 0.95],
            'vault_supply_medium' => ['supply' => 1.30, 'cost' => 0.90],
            'vault_supply_large' => ['supply' => 1.50, 'cost' => 0.85],
            'vault_supply_reduce_small' => ['supply' => 0.90, 'cost' => 1.10],
            'vault_supply_reduce_medium' => ['supply' => 0.80, 'cost' => 1.20],
            'vault_supply_reduce_large' => ['supply' => 0.70, 'cost' => 1.35],
        ];

        if (!isset($multipliers[$mode])) {
            return is_string($currentVaultJson) ? $currentVaultJson : json_encode($currentVaultJson);
        }

        $multiplier = $multipliers[$mode];
        foreach ($vault as &$tier) {
            if (isset($tier['supply'])) {
                $tier['supply'] = max(1, (int)round((float)$tier['supply'] * (float)$multiplier['supply']));
            }
            if (isset($tier['cost_table']) && is_array($tier['cost_table'])) {
                foreach ($tier['cost_table'] as &$entry) {
                    if (isset($entry['cost'])) {
                        $entry['cost'] = max(1, (int)round((float)$entry['cost'] * (float)$multiplier['cost']));
                    }
                }
                unset($entry);
            }
        }
        unset($tier);

        return json_encode($vault, JSON_UNESCAPED_SLASHES);
    }

    private static function versionProfiles(int $tuningVersion): array
    {
        $multiplierOverrides = [
            'hoarding_advantage' => [
                'hoarding_tier2_rate_hourly_fp' => ['conservative' => 1.07, 'balanced' => 1.15],
                'hoarding_tier3_rate_hourly_fp' => ['conservative' => 1.07, 'balanced' => 1.15],
                'hoarding_idle_multiplier_fp' => ['conservative' => 1.03, 'balanced' => 1.08],
            ],
            'concentrated_wealth' => [
                'hoarding_tier1_rate_hourly_fp' => ['conservative' => 1.05, 'balanced' => 1.12],
                'hoarding_tier2_rate_hourly_fp' => ['conservative' => 1.05, 'balanced' => 1.12],
                'hoarding_sink_cap_ratio_fp' => ['conservative' => 0.97, 'balanced' => 0.92],
            ],
            'boost_roi_imbalance' => [
                'base_ubi_active_per_tick' => ['conservative' => 1.04, 'balanced' => 1.10],
                'hoarding_min_factor_fp' => ['conservative' => 1.05, 'balanced' => 1.15],
            ],
            'phase_dead_zones' => [
                'hoarding_safe_hours' => ['conservative' => 0.90, 'balanced' => 0.75],
            ],
        ];

        $counterweights = [
            'lock_in_support' => [
                'trigger_categories' => ['hoarding_advantage', 'concentrated_wealth', 'phase_dead_zones'],
                'targets' => [
                    [
                        'key' => 'starprice_max_downstep_fp',
                        'direction' => 'increase',
                        'conservative' => 1.08,
                        'balanced' => 1.15,
                        'mode' => 'multiply',
                        'type' => 'pacing',
                        'mechanic' => 'star_pricing',
                        'player_effect' => 'Star prices can ease faster after demand cools.',
                        'economy_effect' => 'Preserves lock-in viability when anti-hoarding pressure tightens supply.',
                    ],
                    [
                        'key' => 'starprice_idle_weight_fp',
                        'direction' => 'decrease',
                        'conservative' => 0.97,
                        'balanced' => 0.93,
                        'mode' => 'multiply',
                        'type' => 'pricing',
                        'mechanic' => 'star_pricing',
                        'player_effect' => 'Idle supply contributes less upward star-price pressure.',
                        'economy_effect' => 'Maintains lock-in accessibility when coin supply tightens.',
                    ],
                ],
            ],
            'expiry_softening' => [
                'trigger_categories' => ['hoarding_advantage', 'concentrated_wealth'],
                'targets' => [
                    [
                        'key' => 'starprice_max_downstep_fp',
                        'direction' => 'increase',
                        'conservative' => 1.08,
                        'balanced' => 1.15,
                        'mode' => 'multiply',
                        'type' => 'pacing',
                        'mechanic' => 'star_pricing',
                        'player_effect' => 'Price can recover downward faster after demand drops.',
                        'economy_effect' => 'Helps avoid price-stuck expiry dominance.',
                    ],
                ],
            ],
        ];

        if ($tuningVersion >= 3) {
            $multiplierOverrides = [
                'hoarding_advantage' => [
                    'hoarding_tier2_rate_hourly_fp' => ['conservative' => 1.04, 'balanced' => 1.08],
                    'hoarding_tier3_rate_hourly_fp' => ['conservative' => 1.05, 'balanced' => 1.10],
                    'hoarding_idle_multiplier_fp' => ['conservative' => 1.01, 'balanced' => 1.04],
                ],
                'concentrated_wealth' => [
                    'hoarding_tier1_rate_hourly_fp' => ['conservative' => 1.03, 'balanced' => 1.06],
                    'hoarding_tier2_rate_hourly_fp' => ['conservative' => 1.04, 'balanced' => 1.08],
                    'hoarding_sink_cap_ratio_fp' => ['conservative' => 0.985, 'balanced' => 0.965],
                ],
                'boost_roi_imbalance' => [
                    'base_ubi_active_per_tick' => ['conservative' => 1.03, 'balanced' => 1.06],
                    'hoarding_min_factor_fp' => ['conservative' => 1.04, 'balanced' => 1.08],
                ],
                'phase_dead_zones' => [
                    'hoarding_safe_hours' => ['conservative' => 0.96, 'balanced' => 0.88],
                ],
            ];

            $counterweights = [
                'lock_in_support' => [
                    'trigger_categories' => ['hoarding_advantage', 'concentrated_wealth', 'phase_dead_zones'],
                    'targets' => [
                        [
                            'key' => 'starprice_max_downstep_fp',
                            'direction' => 'increase',
                            'conservative' => 1.15,
                            'balanced' => 1.24,
                            'mode' => 'multiply',
                            'type' => 'pacing',
                            'mechanic' => 'star_pricing',
                            'player_effect' => 'Price falls back faster after demand softens.',
                            'economy_effect' => 'Stabilizes lock-in rate under anti-hoarding pressure.',
                        ],
                        [
                            'key' => 'starprice_idle_weight_fp',
                            'direction' => 'decrease',
                            'conservative' => 0.94,
                            'balanced' => 0.90,
                            'mode' => 'multiply',
                            'type' => 'pricing',
                            'mechanic' => 'star_pricing',
                            'player_effect' => 'Lower idle price pressure keeps star conversion more reachable.',
                            'economy_effect' => 'Offsets lock-in suppression from tighter hoarding drains.',
                        ],
                    ],
                ],
                'expiry_softening' => [
                    'trigger_categories' => ['hoarding_advantage', 'concentrated_wealth'],
                    'targets' => [
                        [
                            'key' => 'starprice_max_downstep_fp',
                            'direction' => 'increase',
                            'conservative' => 1.12,
                            'balanced' => 1.22,
                            'mode' => 'multiply',
                            'type' => 'pacing',
                            'mechanic' => 'star_pricing',
                            'player_effect' => 'Price recovers downward faster after demand drops.',
                            'economy_effect' => 'Reduces expiry dominance and improves conversion consistency.',
                        ],
                    ],
                ],
            ];
        }

        return [
            'multiplier_overrides' => $multiplierOverrides,
            'counterweights' => $counterweights,
        ];
    }

    private static function tuningRegistry(): array
    {
        return [
            'concentrated_wealth' => [
                'tunable' => true,
                'categories' => ['hoarding_preservation_pressure'],
                'targets' => [
                    [
                        'key' => 'hoarding_tier1_rate_hourly_fp',
                        'direction' => 'increase',
                        'conservative' => 1.10,
                        'balanced' => 1.25,
                        'mode' => 'multiply',
                        'type' => 'sink_source',
                        'mechanic' => 'hoarding_sink',
                        'player_effect' => 'Excess coin reserves drain slightly faster.',
                        'economy_effect' => 'Reduces top-10% share of total score.',
                    ],
                    [
                        'key' => 'hoarding_tier2_rate_hourly_fp',
                        'direction' => 'increase',
                        'conservative' => 1.10,
                        'balanced' => 1.25,
                        'mode' => 'multiply',
                        'type' => 'sink_source',
                        'mechanic' => 'hoarding_sink',
                        'player_effect' => 'Large coin hoards are taxed more aggressively.',
                        'economy_effect' => 'Reduces wealth concentration at top quartile.',
                    ],
                    [
                        'key' => 'hoarding_sink_cap_ratio_fp',
                        'direction' => 'decrease',
                        'conservative' => 0.95,
                        'balanced' => 0.85,
                        'mode' => 'multiply',
                        'type' => 'cap',
                        'mechanic' => 'hoarding_sink',
                        'player_effect' => 'Max hoarding drain per cycle increases.',
                        'economy_effect' => 'Stronger wealth redistribution at top end.',
                    ],
                ],
            ],
            'lock_in_timing_pathologies' => [
                'tunable' => true,
                'categories' => ['lock_in_expiry_incentives', 'star_conversion_pricing'],
                'targets' => [
                    [
                        'key' => 'starprice_max_downstep_fp',
                        'direction' => 'increase',
                        'conservative' => 1.10,
                        'balanced' => 1.25,
                        'mode' => 'multiply',
                        'type' => 'pacing',
                        'mechanic' => 'star_pricing',
                        'player_effect' => 'Star prices can relax faster after demand spikes.',
                        'economy_effect' => 'Spreads lock-in timing across phases instead of collapsing late.',
                    ],
                    [
                        'key' => 'starprice_idle_weight_fp',
                        'direction' => 'decrease',
                        'conservative' => 0.97,
                        'balanced' => 0.92,
                        'mode' => 'multiply',
                        'type' => 'pricing',
                        'mechanic' => 'star_pricing',
                        'player_effect' => 'Less idle supply is counted in star-price pressure.',
                        'economy_effect' => 'Encourages earlier lock-in instead of end-rush.',
                    ],
                ],
            ],
            'excessive_expiry' => [
                'tunable' => true,
                'categories' => ['star_conversion_pricing', 'lock_in_expiry_incentives'],
                'targets' => [
                    [
                        'key' => 'starprice_idle_weight_fp',
                        'direction' => 'decrease',
                        'conservative' => 0.95,
                        'balanced' => 0.88,
                        'mode' => 'multiply',
                        'type' => 'pricing',
                        'mechanic' => 'star_pricing',
                        'player_effect' => 'Idle supply adds less pressure to star prices.',
                        'economy_effect' => 'Reduces natural expiry rate toward healthy range.',
                    ],
                    [
                        'key' => 'starprice_max_downstep_fp',
                        'direction' => 'increase',
                        'conservative' => 1.10,
                        'balanced' => 1.25,
                        'mode' => 'multiply',
                        'type' => 'pacing',
                        'mechanic' => 'star_pricing',
                        'player_effect' => 'Star price can drop faster when demand is low.',
                        'economy_effect' => 'More responsive pricing reduces price-stuck expiry.',
                    ],
                ],
            ],
            'insufficient_expiry' => [
                'tunable' => true,
                'categories' => ['star_conversion_pricing', 'lock_in_expiry_incentives'],
                'targets' => [
                    [
                        'key' => 'starprice_idle_weight_fp',
                        'direction' => 'increase',
                        'conservative' => 1.05,
                        'balanced' => 1.12,
                        'mode' => 'multiply',
                        'type' => 'pricing',
                        'mechanic' => 'star_pricing',
                        'player_effect' => 'Idle supply contributes more pressure to star prices.',
                        'economy_effect' => 'Increases expiry rate to create meaningful lock-in decisions.',
                    ],
                    [
                        'key' => 'starprice_max_upstep_fp',
                        'direction' => 'increase',
                        'conservative' => 1.10,
                        'balanced' => 1.25,
                        'mode' => 'multiply',
                        'type' => 'pacing',
                        'mechanic' => 'star_pricing',
                        'player_effect' => 'Star prices rise faster during high-demand phases.',
                        'economy_effect' => 'Creates urgency to lock in before price spikes.',
                    ],
                ],
            ],
            'hoarding_advantage' => [
                'tunable' => true,
                'categories' => ['hoarding_preservation_pressure'],
                'targets' => [
                    [
                        'key' => 'hoarding_tier2_rate_hourly_fp',
                        'direction' => 'increase',
                        'conservative' => 1.15,
                        'balanced' => 1.35,
                        'mode' => 'multiply',
                        'type' => 'sink_source',
                        'mechanic' => 'hoarding_sink',
                        'player_effect' => 'Hoarding large reserves incurs higher drain.',
                        'economy_effect' => 'Reduces hoarder advantage over active spenders.',
                    ],
                    [
                        'key' => 'hoarding_tier3_rate_hourly_fp',
                        'direction' => 'increase',
                        'conservative' => 1.15,
                        'balanced' => 1.35,
                        'mode' => 'multiply',
                        'type' => 'sink_source',
                        'mechanic' => 'hoarding_sink',
                        'player_effect' => 'Extreme hoards face significantly higher drain.',
                        'economy_effect' => 'Caps hoarder long-run conversion advantage.',
                    ],
                    [
                        'key' => 'hoarding_idle_multiplier_fp',
                        'direction' => 'increase',
                        'conservative' => 1.05,
                        'balanced' => 1.15,
                        'mode' => 'multiply',
                        'type' => 'sink_source',
                        'mechanic' => 'hoarding_sink',
                        'player_effect' => 'Idle players with hoards lose coins faster.',
                        'economy_effect' => 'Discourages idle-hoard strategies.',
                    ],
                ],
            ],
            'boost_roi_imbalance' => [
                'tunable' => true,
                'categories' => ['boost_related', 'hoarding_preservation_pressure'],
                'targets' => [
                    [
                        'key' => 'base_ubi_active_per_tick',
                        'direction' => 'increase',
                        'conservative' => 1.08,
                        'balanced' => 1.18,
                        'mode' => 'multiply',
                        'type' => 'reward',
                        'mechanic' => 'ubi',
                        'player_effect' => 'Active players gain more baseline coins to convert boost value into action.',
                        'economy_effect' => 'Improves boost ROI relative to non-boost paths.',
                    ],
                    [
                        'key' => 'hoarding_min_factor_fp',
                        'direction' => 'increase',
                        'conservative' => 1.10,
                        'balanced' => 1.30,
                        'mode' => 'multiply',
                        'type' => 'config_value',
                        'mechanic' => 'hoarding_sink',
                        'player_effect' => 'Higher floor on hoarding sink preserves more boost-earned coins.',
                        'economy_effect' => 'Reduces penalty on boost-focused play patterns.',
                    ],
                ],
            ],
            'star_pricing_issues' => [
                'tunable' => true,
                'categories' => ['star_conversion_pricing'],
                'targets' => [
                    [
                        'key' => 'starprice_max_upstep_fp',
                        'direction' => 'decrease',
                        'conservative' => 0.90,
                        'balanced' => 0.75,
                        'mode' => 'multiply',
                        'type' => 'pacing',
                        'mechanic' => 'star_pricing',
                        'player_effect' => 'Star price volatility is reduced.',
                        'economy_effect' => 'Price stays off cap/floor more of the season.',
                    ],
                    [
                        'key' => 'starprice_max_downstep_fp',
                        'direction' => 'decrease',
                        'conservative' => 0.90,
                        'balanced' => 0.75,
                        'mode' => 'multiply',
                        'type' => 'pacing',
                        'mechanic' => 'star_pricing',
                        'player_effect' => 'Star price adjusts more smoothly.',
                        'economy_effect' => 'Reduces CV of star price across seeds.',
                    ],
                ],
            ],
            'bad_player_experience' => [
                'tunable' => true,
                'categories' => ['boost_related', 'hoarding_preservation_pressure'],
                'targets' => [
                    [
                        'key' => 'base_ubi_idle_factor_fp',
                        'direction' => 'increase',
                        'conservative' => 1.10,
                        'balanced' => 1.25,
                        'mode' => 'multiply',
                        'type' => 'reward',
                        'mechanic' => 'ubi',
                        'player_effect' => 'Idle players earn marginally more coins passively.',
                        'economy_effect' => 'Improves casual/idle lock-in viability.',
                    ],
                    [
                        'key' => 'hoarding_safe_min_coins',
                        'direction' => 'increase',
                        'conservative' => 1.15,
                        'balanced' => 1.40,
                        'mode' => 'multiply',
                        'type' => 'cap',
                        'mechanic' => 'hoarding_sink',
                        'player_effect' => 'Casual players keep more coins before drain kicks in.',
                        'economy_effect' => 'Reduces punitive drain on low-engagement players.',
                    ],
                ],
            ],
            'sigil_scarcity' => [
                'tunable' => false,
                'categories' => [],
                'targets' => [],
                'escalation_info' => [
                    'reason' => 'Phase 1 excludes vault-market spending from the canonical search surface, and drop-rate controls remain outside season columns.',
                    'subsystem' => 'sigil_drop_engine',
                    'runtime_path' => 'includes/config.php + scripts/simulation/PolicyBehavior.php',
                ],
            ],
            'sigil_overabundance' => [
                'tunable' => false,
                'categories' => [],
                'targets' => [],
                'escalation_info' => [
                    'reason' => 'Phase 1 excludes vault-market spending from the canonical search surface, and drop-rate controls remain outside season columns.',
                    'subsystem' => 'sigil_drop_engine',
                    'runtime_path' => 'includes/config.php + scripts/simulation/PolicyBehavior.php',
                ],
            ],
            'weak_progression_pacing' => [
                'tunable' => true,
                'categories' => ['boost_related'],
                'targets' => [
                    [
                        'key' => 'base_ubi_active_per_tick',
                        'direction' => 'increase',
                        'conservative' => 1.08,
                        'balanced' => 1.17,
                        'mode' => 'multiply',
                        'type' => 'reward',
                        'mechanic' => 'ubi',
                        'player_effect' => 'Faster early-season coin accumulation.',
                        'economy_effect' => 'Raises median score at early phase boundary.',
                    ],
                ],
            ],
            'phase_dead_zones' => [
                'tunable' => true,
                'categories' => ['phase_timing', 'hoarding_preservation_pressure'],
                'targets' => [
                    [
                        'key' => 'hoarding_safe_hours',
                        'direction' => 'decrease',
                        'conservative' => 0.92,
                        'balanced' => 0.83,
                        'mode' => 'multiply',
                        'type' => 'timer',
                        'mechanic' => 'hoarding_sink',
                        'player_effect' => 'The hoarding grace period ends sooner, creating earlier mid-season pressure.',
                        'economy_effect' => 'Drives action into quieter phases.',
                    ],
                ],
            ],
            'underused_mechanics' => [
                'tunable' => false,
                'categories' => [],
                'targets' => [],
                'escalation_info' => [
                    'reason' => 'Freeze/theft/combine action rates depend on config.php constants and BoostCatalog definitions.',
                    'subsystem' => 'sigil_abilities',
                    'runtime_path' => 'includes/config.php + includes/actions.php',
                ],
            ],
            'overpowered_mechanics' => [
                'tunable' => false,
                'categories' => [],
                'targets' => [],
                'escalation_info' => [
                    'reason' => 'Boost power/stack limits are hardcoded in BoostCatalog::DEFINITIONS and config.php.',
                    'subsystem' => 'boost_catalog',
                    'runtime_path' => 'includes/boost_catalog.php + includes/config.php',
                ],
            ],
            'non_viable_archetype' => [
                'tunable' => false,
                'categories' => [],
                'targets' => [],
                'escalation_info' => [
                    'reason' => 'Archetype viability issues can span UBI, boost, star pricing, or hoarding surfaces.',
                    'subsystem' => 'economy_multi_surface',
                    'runtime_path' => 'Depends on finding details.',
                ],
            ],
            'dominant_archetype' => [
                'tunable' => false,
                'categories' => [],
                'targets' => [],
                'escalation_info' => [
                    'reason' => 'Dominant archetype root cause may require multi-surface tuning or logic changes.',
                    'subsystem' => 'economy_multi_surface',
                    'runtime_path' => 'Depends on finding details.',
                ],
            ],
            'cross_seed_instability' => [
                'tunable' => false,
                'categories' => [],
                'targets' => [],
                'escalation_info' => [
                    'reason' => 'Cross-seed instability is informational and does not map cleanly to a single tunable knob.',
                    'subsystem' => 'simulation_variance',
                    'runtime_path' => 'N/A',
                ],
            ],
        ];
    }
}
