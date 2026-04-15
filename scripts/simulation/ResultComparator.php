<?php

require_once __DIR__ . '/MetricsCollector.php';
require_once __DIR__ . '/EconomicCandidateValidator.php';

class ResultComparator
{
    public const COMPARATOR_SCHEMA_VERSION = 'tmc-sim-comparator.v1';
    public const REJECTION_ATTRIBUTION_SCHEMA_VERSION = 'tmc-sim-rejection-attribution.v1';

    public static function run(array $options): array
    {
        $startedAt = microtime(true);
        $sweepManifestPath = isset($options['sweep_manifest']) ? (string)$options['sweep_manifest'] : null;
        $baselineBPaths = (array)($options['baseline_b_paths'] ?? []);
        $baselineCPaths = (array)($options['baseline_c_paths'] ?? []);
        $outputDir = (string)($options['output_dir'] ?? (__DIR__ . '/../../simulation_output/comparator'));
        $seed = (string)($options['seed'] ?? 'phase1-comparator');

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        $datasetStartedAt = microtime(true);
        $dataset = self::buildDataset($sweepManifestPath, $baselineBPaths, $baselineCPaths);
        $datasetDurationMs = self::msSince($datasetStartedAt);
        $scenarioReports = [];

        $scenarioCompareStartedAt = microtime(true);
        foreach ($dataset['scenario_groups'] as $scenarioName => $simGroups) {
            $scenarioReport = self::compareScenario($scenarioName, $simGroups, $dataset['baseline_groups'], $outputDir, $seed);
            $scenarioReports[] = $scenarioReport;
        }
        $scenarioCompareDurationMs = self::msSince($scenarioCompareStartedAt);

        usort($scenarioReports, static function ($left, $right) {
            return strcmp((string)$left['scenario_name'], (string)$right['scenario_name']);
        });

        $result = [
            'schema_version' => MetricsCollector::SCHEMA_VERSION,
            'comparator_schema_version' => self::COMPARATOR_SCHEMA_VERSION,
            'generated_at' => gmdate('c'),
            'seed' => $seed,
            'inputs' => [
                'sweep_manifest' => $sweepManifestPath,
                'baseline_b_paths' => array_values($baselineBPaths),
                'baseline_c_paths' => array_values($baselineCPaths),
            ],
            'summary' => [
                'scenario_count' => count($scenarioReports),
                'baseline_group_count' => count($dataset['baseline_groups']),
                'scenario_group_count' => count($dataset['scenario_groups']),
                'rejected_scenario_count' => count(array_filter($scenarioReports, static function (array $scenario): bool {
                    return (string)($scenario['recommended_disposition'] ?? '') === 'reject';
                })),
            ],
            'scenarios' => $scenarioReports,
            'timing_summary' => [
                'dataset_build_duration_ms' => $datasetDurationMs,
                'scenario_compare_duration_ms' => $scenarioCompareDurationMs,
                'artifact_write_duration_ms' => 0,
                'total_duration_ms' => 0,
            ],
        ];

        $baseName = 'comparison_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $seed);
        $artifactWriteStartedAt = microtime(true);
        $jsonPath = MetricsCollector::writeJson($result, $outputDir, $baseName);
        $result['timing_summary']['artifact_write_duration_ms'] = self::msSince($artifactWriteStartedAt);
        $result['timing_summary']['total_duration_ms'] = self::msSince($startedAt);
        $jsonPath = MetricsCollector::writeJson($result, $outputDir, $baseName);

        return [
            'payload' => $result,
            'json_path' => $jsonPath,
        ];
    }

    private static function buildDataset(?string $sweepManifestPath, array $baselineBPaths, array $baselineCPaths): array
    {
        $baselineGroups = [];
        $scenarioGroups = [];

        if ($sweepManifestPath !== null && $sweepManifestPath !== '') {
            $manifest = self::readJsonFile($sweepManifestPath);
            $manifestDir = dirname($sweepManifestPath);
            foreach ((array)($manifest['runs'] ?? []) as $run) {
                $simulator = strtoupper((string)($run['simulator_type'] ?? ''));
                if (!in_array($simulator, ['B', 'C'], true)) {
                    continue;
                }
                $jsonPath = self::resolvePath($manifestDir, (string)($run['json'] ?? ''));
                $payload = self::readJsonFile($jsonPath);
                $signature = self::buildSignature($simulator, (array)($run['cohort'] ?? []), (array)($run['horizon'] ?? []), $payload);
                $entry = [
                    'simulator_type' => $simulator,
                    'signature' => $signature,
                    'scenario_name' => (string)($run['scenario_name'] ?? 'unknown'),
                    'is_baseline' => !empty($run['is_baseline']),
                    'seed' => (string)($run['seed'] ?? ''),
                    'override_categories' => (array)($run['override_categories'] ?? []),
                    'override_keys' => (array)($run['override_keys'] ?? []),
                    'source' => 'sweep_manifest',
                    'payload_path' => $jsonPath,
                    'payload' => $payload,
                    'config_audit_report' => self::loadConfigAuditReport($manifestDir, (array)($run['config_audit'] ?? [])),
                ];

                if ($entry['is_baseline']) {
                    $baselineGroups[$simulator][$signature][] = $entry;
                } else {
                    $scenarioName = $entry['scenario_name'];
                    $scenarioGroups[$scenarioName][$simulator][$signature][] = $entry;
                }
            }
        }

        foreach ($baselineBPaths as $path) {
            self::addStandaloneBaseline($baselineGroups, 'B', (string)$path);
        }
        foreach ($baselineCPaths as $path) {
            self::addStandaloneBaseline($baselineGroups, 'C', (string)$path);
        }

        return [
            'baseline_groups' => $baselineGroups,
            'scenario_groups' => $scenarioGroups,
        ];
    }

    private static function addStandaloneBaseline(array &$baselineGroups, string $simulator, string $path): void
    {
        if ($path === '') {
            return;
        }
        $payload = self::readJsonFile($path);
        $signature = self::buildSignature($simulator, [], [], $payload);
        $baselineGroups[$simulator][$signature][] = [
            'simulator_type' => $simulator,
            'signature' => $signature,
            'scenario_name' => 'baseline-standalone',
            'is_baseline' => true,
            'seed' => (string)($payload['seed'] ?? ''),
            'override_categories' => [],
            'override_keys' => [],
            'source' => 'standalone_baseline',
            'payload_path' => $path,
            'payload' => $payload,
        ];
    }

    private static function compareScenario(string $scenarioName, array $simGroups, array $baselineGroups, string $outputDir, string $seed): array
    {
        $startedAt = microtime(true);
        $simulatorComparisons = [];
        $regressionFlags = [];
        $wins = 0;
        $losses = 0;
        $mixed = 0;

        foreach ($simGroups as $simulator => $signatureGroups) {
            foreach ($signatureGroups as $signature => $scenarioRuns) {
                $baselineRuns = $baselineGroups[$simulator][$signature] ?? [];
                if ($baselineRuns === []) {
                    $fallback = $baselineGroups[$simulator] ?? [];
                    if (!empty($fallback)) {
                        $baselineRuns = reset($fallback);
                    }
                }
                if ($baselineRuns === [] || $scenarioRuns === []) {
                    continue;
                }

                $comparison = self::compareRunGroup($simulator, $signature, $baselineRuns, $scenarioRuns);
                $simulatorComparisons[] = $comparison;
                $wins += (int)$comparison['wins'];
                $losses += (int)$comparison['losses'];
                $mixed += (int)$comparison['mixed_tradeoffs'];
                foreach ((array)$comparison['regression_flags'] as $flag) {
                    $regressionFlags[$flag] = true;
                }
            }
        }

        $sampleCount = 0;
        foreach ($simulatorComparisons as $comparison) {
            $sampleCount += (int)$comparison['sample_size']['paired_runs'];
        }

        $crossFlags = self::detectCrossSimulatorFlags($simulatorComparisons);
        foreach ($crossFlags as $flag) {
            $regressionFlags[$flag] = true;
        }
        $flags = array_keys($regressionFlags);
        sort($flags);

        $disposition = 'keep testing';
        if (!empty($flags)) {
            $disposition = 'reject';
        } elseif ($wins >= ($losses + 2)) {
            $disposition = 'candidate for production tuning';
        }

        $report = [
            'scenario_name' => $scenarioName,
            'wins' => $wins,
            'losses' => $losses,
            'mixed_tradeoffs' => $mixed,
            'confidence_notes' => sprintf('Paired samples: %d group comparisons across %d simulator group(s).', $sampleCount, count($simulatorComparisons)),
            'recommended_disposition' => $disposition,
            'simulator_comparisons' => $simulatorComparisons,
            'cross_simulator_regression_flags' => $crossFlags,
            'regression_flags' => $flags,
            'timing_ms' => 0,
        ];

        if ($disposition === 'reject') {
            $attribution = self::buildRejectionAttribution($scenarioName, $report, $simGroups, $baselineGroups, $outputDir, $seed);
            $report['rejection_attribution'] = [
                'artifact_paths' => $attribution['artifact_paths'],
                'primary_failed_gate' => $attribution['report']['primary_failed_gate'],
                'secondary_regressions' => $attribution['report']['secondary_regressions'],
                'interaction_ambiguity' => $attribution['report']['interaction_ambiguity'],
                'confidence_notes' => $attribution['report']['confidence_notes'],
                'timing_ms' => (int)($attribution['timing_ms'] ?? 0),
            ];
        }
        $report['timing_ms'] = self::msSince($startedAt);

        return $report;
    }

    private static function compareRunGroup(string $simulator, string $signature, array $baselineRuns, array $scenarioRuns): array
    {
        $paired = min(count($baselineRuns), count($scenarioRuns));
        $pairs = [];
        for ($i = 0; $i < $paired; $i++) {
            $pairs[] = [
                'baseline' => $baselineRuns[$i],
                'candidate' => $scenarioRuns[$i],
            ];
        }

        $deltaAccumulator = [];
        $archetypeRankShifts = [];
        $sourceMetadata = [
            'override_categories' => [],
            'override_keys' => [],
        ];
        $dominantBaseline = 'none';
        $dominantCandidate = 'none';

        foreach ($pairs as $index => $pair) {
            $baselineMetrics = self::extractMetrics($simulator, (array)$pair['baseline']['payload']);
            $candidateMetrics = self::extractMetrics($simulator, (array)$pair['candidate']['payload']);
            if ($index === 0) {
                $dominantBaseline = (string)$baselineMetrics['dominant_archetype'];
                $dominantCandidate = (string)$candidateMetrics['dominant_archetype'];
            }
            $delta = self::computeDelta($baselineMetrics, $candidateMetrics);
            self::mergeDeltaAccumulator($deltaAccumulator, $delta);

            $rankShift = self::rankShift($baselineMetrics['archetype_rankings'], $candidateMetrics['archetype_rankings']);
            foreach ($rankShift as $label => $shift) {
                if (!isset($archetypeRankShifts[$label])) {
                    $archetypeRankShifts[$label] = 0.0;
                }
                $archetypeRankShifts[$label] += $shift;
            }

            foreach ((array)$pair['candidate']['override_categories'] as $category) {
                $sourceMetadata['override_categories'][(string)$category] = true;
            }
            foreach ((array)$pair['candidate']['override_keys'] as $key) {
                $sourceMetadata['override_keys'][(string)$key] = true;
            }
        }

        $deltaFlags = self::finalizeDeltaAccumulator($deltaAccumulator, max(1, $paired));
        foreach ($archetypeRankShifts as $label => $value) {
            $archetypeRankShifts[$label] = $value / max(1, $paired);
        }

        $winsLosses = self::countWinsLosses($deltaFlags, $simulator);
        $regressionFlags = self::detectRegressionFlags($deltaFlags, $simulator);

        return [
            'simulator_type' => $simulator,
            'signature' => $signature,
            'sample_size' => [
                'baseline_runs' => count($baselineRuns),
                'scenario_runs' => count($scenarioRuns),
                'paired_runs' => $paired,
            ],
            'delta_flags' => $deltaFlags,
            'archetype_ranking_shift' => $archetypeRankShifts,
            'wins' => $winsLosses['wins'],
            'losses' => $winsLosses['losses'],
            'mixed_tradeoffs' => $winsLosses['mixed_tradeoffs'],
            'regression_flags' => $regressionFlags,
            'metadata' => [
                'override_categories' => array_keys($sourceMetadata['override_categories']),
                'override_keys' => array_keys($sourceMetadata['override_keys']),
                'scenario_name' => (string)$scenarioRuns[0]['scenario_name'],
                'dominant_archetype_transition' => [
                    'baseline' => $dominantBaseline,
                    'candidate' => $dominantCandidate,
                ],
            ],
        ];
    }

    private static function extractMetrics(string $simulator, array $payload): array
    {
        if ($simulator === 'B') {
            return self::extractSeasonMetrics($payload);
        }
        return self::extractLifetimeMetrics($payload);
    }

    private static function extractSeasonMetrics(array $payload): array
    {
        $diagnostics = (array)($payload['diagnostics'] ?? []);
        $archetypes = (array)($payload['archetypes'] ?? []);

        $scores = [];
        $t6Total = 0;
        $t6BySource = ['drop' => 0, 'combine' => 0, 'theft' => 0];
        $naturalExpiryTotal = 0;
        foreach ($archetypes as $key => $row) {
            $label = (string)($row['label'] ?? $key);
            $score = (float)($row['global_stars_gained'] ?? 0.0);
            $scores[$label] = $score;
            $t6Total += (int)($row['t6_total_acquired'] ?? 0);
            $naturalExpiryTotal += (int)($row['natural_expiry_count'] ?? 0);
            $sources = (array)($row['t6_by_source'] ?? []);
            foreach ($t6BySource as $source => $value) {
                $t6BySource[$source] += (int)($sources[$source] ?? 0);
            }
        }

        $lockTiming = (array)($diagnostics['lock_in_timing'] ?? []);
        $lockIns = (int)($lockTiming['EARLY'] ?? 0)
            + (int)($lockTiming['MID'] ?? 0)
            + (int)($lockTiming['LATE_ACTIVE'] ?? 0)
            + (int)($lockTiming['BLACKOUT'] ?? 0);

        return [
            'archetype_rankings' => self::sortScores($scores),
            'dominant_archetype' => self::topLabel($scores),
            'metrics' => [
                'lock_in_total' => $lockIns,
                'natural_expiry_total' => (int)($diagnostics['natural_expiry_count'] ?? $naturalExpiryTotal),
                'late_active_participation_rate' => (float)($diagnostics['late_active_engaged_rate'] ?? 0.0),
                't6_total' => (float)$t6Total,
                't6_source_drop' => (float)$t6BySource['drop'],
                't6_source_combine' => (float)$t6BySource['combine'],
                't6_source_theft' => (float)$t6BySource['theft'],
                'mostly_idle_performance' => self::scoreByArchetype($archetypes, 'mostly_idle', 'global_stars_gained'),
                'star_focused_dominance' => self::scoreByArchetype($archetypes, 'star_focused', 'global_stars_gained'),
                'hoarder_performance' => self::scoreByArchetype($archetypes, 'hoarder', 'global_stars_gained'),
                'boost_focused_payoff' => self::scoreByArchetype($archetypes, 'boost_focused', 'global_stars_gained'),
                'concentration_proxy_top1_share' => self::topShare($scores),
                'skip_rejoin_exploit' => 0.0,
                'cumulative_global_star_concentration' => 0.0,
            ],
        ];
    }

    private static function extractLifetimeMetrics(array $payload): array
    {
        $archetypes = (array)($payload['archetypes'] ?? []);
        $timeline = (array)($payload['season_timeline'] ?? []);
        $diagnostics = (array)($payload['population_diagnostics'] ?? []);
        $drift = (array)($payload['concentration_drift'] ?? []);
        $finalConcentration = !empty($drift) ? (array)$drift[count($drift) - 1] : [];

        $scores = [];
        $naturalExpiry = 0.0;
        foreach ($archetypes as $key => $row) {
            $label = (string)($row['label'] ?? $key);
            $scores[$label] = (float)($row['cumulative_global_stars_avg'] ?? 0.0);
            $naturalExpiry += (float)($row['natural_expiry_count_sum'] ?? 0.0);
        }

        $lateActiveRateSum = 0.0;
        $t6Total = 0.0;
        foreach ($timeline as $season) {
            $lateActiveRateSum += (float)($season['late_active_engaged_rate'] ?? 0.0);
            $t6Total += (float)($season['t6_total'] ?? 0.0);
        }
        $lateActiveRate = !empty($timeline) ? ($lateActiveRateSum / count($timeline)) : 0.0;

        return [
            'archetype_rankings' => self::sortScores($scores),
            'dominant_archetype' => self::topLabel($scores),
            'metrics' => [
                'lock_in_total' => (float)($diagnostics['throughput_lock_in_rate'] ?? 0.0),
                'natural_expiry_total' => $naturalExpiry,
                'late_active_participation_rate' => $lateActiveRate,
                't6_total' => $t6Total,
                't6_source_drop' => 0.0,
                't6_source_combine' => 0.0,
                't6_source_theft' => 0.0,
                'mostly_idle_performance' => self::scoreByArchetype($archetypes, 'mostly_idle', 'cumulative_global_stars_avg'),
                'star_focused_dominance' => self::scoreByArchetype($archetypes, 'star_focused', 'cumulative_global_stars_avg'),
                'hoarder_performance' => self::scoreByArchetype($archetypes, 'hoarder', 'cumulative_global_stars_avg'),
                'boost_focused_payoff' => self::scoreByArchetype($archetypes, 'boost_focused', 'cumulative_global_stars_avg'),
                'concentration_proxy_top1_share' => (float)($finalConcentration['top_1_percent_share'] ?? 0.0),
                'concentration_drift_top10_share' => (float)($finalConcentration['top_10_percent_share'] ?? 0.0),
                'skip_rejoin_exploit' => (float)($diagnostics['skip_strategy_edge'] ?? 0.0),
                'cumulative_global_star_concentration' => (float)($finalConcentration['top_10_percent_share'] ?? 0.0),
            ],
        ];
    }

    private static function computeDelta(array $baselineMetrics, array $candidateMetrics): array
    {
        $delta = [];
        $base = (array)$baselineMetrics['metrics'];
        $cand = (array)$candidateMetrics['metrics'];
        foreach ($cand as $key => $value) {
            $delta[$key] = (float)$value - (float)($base[$key] ?? 0.0);
        }

        $delta['dominant_archetype_changed'] = ((string)$baselineMetrics['dominant_archetype'] !== (string)$candidateMetrics['dominant_archetype']) ? 1.0 : 0.0;
        return $delta;
    }

    private static function mergeDeltaAccumulator(array &$acc, array $delta): void
    {
        foreach ($delta as $key => $value) {
            if (!isset($acc[$key])) {
                $acc[$key] = 0.0;
            }
            $acc[$key] += (float)$value;
        }
    }

    private static function finalizeDeltaAccumulator(array $acc, int $paired): array
    {
        $avg = [];
        foreach ($acc as $key => $value) {
            $avg[$key] = (float)$value / max(1, $paired);
        }

        return [
            'lock_in_vs_natural_expiry' => [
                'lock_in_delta' => (float)($avg['lock_in_total'] ?? 0.0),
                'natural_expiry_delta' => (float)($avg['natural_expiry_total'] ?? 0.0),
            ],
            'late_active_participation' => [
                'late_active_engaged_rate_delta' => (float)($avg['late_active_participation_rate'] ?? 0.0),
            ],
            't6_supply_and_sourcing' => [
                't6_total_delta' => (float)($avg['t6_total'] ?? 0.0),
                't6_drop_delta' => (float)($avg['t6_source_drop'] ?? 0.0),
                't6_combine_delta' => (float)($avg['t6_source_combine'] ?? 0.0),
                't6_theft_delta' => (float)($avg['t6_source_theft'] ?? 0.0),
            ],
            'mostly_idle_performance' => [
                'delta' => (float)($avg['mostly_idle_performance'] ?? 0.0),
            ],
            'star_focused_dominance' => [
                'delta' => (float)($avg['star_focused_dominance'] ?? 0.0),
            ],
            'hoarder_performance' => [
                'delta' => (float)($avg['hoarder_performance'] ?? 0.0),
            ],
            'boost_focused_payoff' => [
                'delta' => (float)($avg['boost_focused_payoff'] ?? 0.0),
            ],
            'concentration_drift' => [
                'top10_share_delta' => (float)($avg['concentration_drift_top10_share'] ?? 0.0),
            ],
            'skip_rejoin_exploit' => [
                'skip_strategy_edge_delta' => (float)($avg['skip_rejoin_exploit'] ?? 0.0),
            ],
            'cumulative_global_star_concentration' => [
                'top1_share_delta' => (float)($avg['concentration_proxy_top1_share'] ?? 0.0),
                'top10_share_delta' => (float)($avg['cumulative_global_star_concentration'] ?? 0.0),
            ],
            'archetype_ranking_shift' => [
                'dominant_archetype_changed' => ((float)($avg['dominant_archetype_changed'] ?? 0.0) > 0.0),
            ],
        ];
    }

    private static function countWinsLosses(array $deltaFlags, string $simulator): array
    {
        $wins = 0;
        $losses = 0;

        $checks = [
            ['path' => ['mostly_idle_performance', 'delta'], 'direction' => 'down'],
            ['path' => ['star_focused_dominance', 'delta'], 'direction' => 'down'],
            ['path' => ['hoarder_performance', 'delta'], 'direction' => 'down'],
            ['path' => ['boost_focused_payoff', 'delta'], 'direction' => 'up'],
            ['path' => ['late_active_participation', 'late_active_engaged_rate_delta'], 'direction' => 'up'],
            ['path' => ['t6_supply_and_sourcing', 't6_total_delta'], 'direction' => 'down_soft'],
            ['path' => ['lock_in_vs_natural_expiry', 'natural_expiry_delta'], 'direction' => 'down'],
        ];

        if ($simulator === 'C') {
            $checks[] = ['path' => ['concentration_drift', 'top10_share_delta'], 'direction' => 'down'];
            $checks[] = ['path' => ['skip_rejoin_exploit', 'skip_strategy_edge_delta'], 'direction' => 'toward_zero'];
        }

        foreach ($checks as $check) {
            $value = self::getNestedFloat($deltaFlags, $check['path']);
            if ($value === null) {
                continue;
            }
            $eps = 0.00001;
            if ($check['direction'] === 'up') {
                if ($value > $eps) {
                    $wins++;
                } elseif ($value < -$eps) {
                    $losses++;
                }
            } elseif ($check['direction'] === 'down') {
                if ($value < -$eps) {
                    $wins++;
                } elseif ($value > $eps) {
                    $losses++;
                }
            } elseif ($check['direction'] === 'toward_zero') {
                if (abs($value) < 0.00001) {
                    continue;
                }
                if ($value < 0) {
                    $wins++;
                } else {
                    $losses++;
                }
            } else {
                // down_soft
                if ($value < -$eps) {
                    $wins++;
                } elseif ($value > 0.25) {
                    $losses++;
                }
            }
        }

        $mixed = ($wins > 0 && $losses > 0) ? 1 : 0;
        return ['wins' => $wins, 'losses' => $losses, 'mixed_tradeoffs' => $mixed];
    }

    private static function detectRegressionFlags(array $deltaFlags, string $simulator): array
    {
        $flags = [];

        $lockDelta = self::getNestedFloat($deltaFlags, ['lock_in_vs_natural_expiry', 'lock_in_delta']) ?? 0.0;
        $expiryDelta = self::getNestedFloat($deltaFlags, ['lock_in_vs_natural_expiry', 'natural_expiry_delta']) ?? 0.0;
        $engagementDelta = self::getNestedFloat($deltaFlags, ['late_active_participation', 'late_active_engaged_rate_delta']) ?? 0.0;
        $t6Delta = self::getNestedFloat($deltaFlags, ['t6_supply_and_sourcing', 't6_total_delta']) ?? 0.0;

        if ($engagementDelta > 0.02 && $t6Delta > 2.0) {
            $flags[] = 'engagement_up_but_t6_supply_spike';
        }
        if ($lockDelta < -0.01 && $expiryDelta > 0.5) {
            $flags[] = 'lock_in_down_but_expiry_dominance_up';
        }
        if (!empty($deltaFlags['archetype_ranking_shift']['dominant_archetype_changed'])) {
            $flags[] = 'dominant_archetype_shifted';
        }
        if ($simulator === 'C') {
            $concentrationDelta = self::getNestedFloat($deltaFlags, ['concentration_drift', 'top10_share_delta']) ?? 0.0;
            if ($concentrationDelta > 0.01) {
                $flags[] = 'long_run_concentration_worsened';
            }
            $skipDelta = self::getNestedFloat($deltaFlags, ['skip_rejoin_exploit', 'skip_strategy_edge_delta']) ?? 0.0;
            if ($skipDelta > 0.0) {
                $flags[] = 'skip_rejoin_exploit_worsened';
            }
        }

        sort($flags);
        return $flags;
    }

    private static function detectCrossSimulatorFlags(array $simulatorComparisons): array
    {
        $flags = [];
        $bySimulator = [];
        foreach ($simulatorComparisons as $comparison) {
            $sim = (string)$comparison['simulator_type'];
            $bySimulator[$sim][] = $comparison;
        }

        $b = $bySimulator['B'][0] ?? null;
        $c = $bySimulator['C'][0] ?? null;
        if ($b !== null && $c !== null) {
            if ((int)$b['wins'] > (int)$b['losses'] && (int)$c['losses'] > (int)$c['wins']) {
                $flags[] = 'candidate_improves_B_but_worsens_C';
            }

            $bTop1 = self::getNestedFloat((array)$b['delta_flags'], ['cumulative_global_star_concentration', 'top1_share_delta']) ?? 0.0;
            $cTop10 = self::getNestedFloat((array)$c['delta_flags'], ['concentration_drift', 'top10_share_delta']) ?? 0.0;
            if ($bTop1 < -0.001 && $cTop10 > 0.001) {
                $flags[] = 'seasonal_fairness_improves_but_long_run_concentration_worsens';
            }

            $bStar = self::getNestedFloat((array)$b['delta_flags'], ['star_focused_dominance', 'delta']) ?? 0.0;
            $bHoard = self::getNestedFloat((array)$b['delta_flags'], ['hoarder_performance', 'delta']) ?? 0.0;
            $transition = (array)($c['metadata']['dominant_archetype_transition'] ?? []);
            $reducedDominant = ($bStar < 0.0 || $bHoard < 0.0);
            $newDominant = ((string)($transition['baseline'] ?? '') !== '')
                && ((string)($transition['candidate'] ?? '') !== '')
                && ((string)$transition['baseline'] !== (string)$transition['candidate']);
            if ($reducedDominant && $newDominant) {
                $flags[] = 'reduced_one_dominant_but_created_new_dominant';
            }
        }

        foreach ($simulatorComparisons as $comparison) {
            $engagementDelta = self::getNestedFloat((array)$comparison['delta_flags'], ['late_active_participation', 'late_active_engaged_rate_delta']) ?? 0.0;
            $t6Delta = self::getNestedFloat((array)$comparison['delta_flags'], ['t6_supply_and_sourcing', 't6_total_delta']) ?? 0.0;
            if ($engagementDelta > 0.02 && $t6Delta > 2.0) {
                $flags[] = 'improves_engagement_but_t6_supply_spikes';
            }

            $lockDelta = self::getNestedFloat((array)$comparison['delta_flags'], ['lock_in_vs_natural_expiry', 'lock_in_delta']) ?? 0.0;
            $expiryDelta = self::getNestedFloat((array)$comparison['delta_flags'], ['lock_in_vs_natural_expiry', 'natural_expiry_delta']) ?? 0.0;
            if ($lockDelta < -0.01 && $expiryDelta > 0.5) {
                $flags[] = 'reduces_lock_in_but_expiry_dominance_rises';
            }
        }

        $flags = array_values(array_unique($flags));
        sort($flags);
        return $flags;
    }

    private static function rankShift(array $baselineRankings, array $candidateRankings): array
    {
        $basePos = [];
        foreach ($baselineRankings as $index => $entry) {
            $basePos[(string)$entry['label']] = $index + 1;
        }
        $candPos = [];
        foreach ($candidateRankings as $index => $entry) {
            $candPos[(string)$entry['label']] = $index + 1;
        }

        $allLabels = array_unique(array_merge(array_keys($basePos), array_keys($candPos)));
        $shifts = [];
        foreach ($allLabels as $label) {
            $base = (float)($basePos[$label] ?? 999.0);
            $cand = (float)($candPos[$label] ?? 999.0);
            $shifts[$label] = $base - $cand;
        }

        return $shifts;
    }

    private static function sortScores(array $scores): array
    {
        arsort($scores);
        $rows = [];
        foreach ($scores as $label => $score) {
            $rows[] = ['label' => $label, 'score' => (float)$score];
        }
        return $rows;
    }

    private static function topLabel(array $scores): string
    {
        if (empty($scores)) {
            return 'none';
        }
        arsort($scores);
        return (string)array_key_first($scores);
    }

    private static function topShare(array $scores): float
    {
        $total = array_sum($scores);
        if ($total <= 0) {
            return 0.0;
        }
        arsort($scores);
        $top = (float)reset($scores);
        return $top / $total;
    }

    private static function scoreByArchetype(array $archetypes, string $key, string $metric): float
    {
        if (!isset($archetypes[$key])) {
            return 0.0;
        }
        return (float)($archetypes[$key][$metric] ?? 0.0);
    }

    private static function getNestedFloat(array $data, array $path): ?float
    {
        $current = $data;
        foreach ($path as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }
        return is_numeric($current) ? (float)$current : null;
    }

    private static function loadConfigAuditReport(string $baseDir, array $artifactPaths): ?array
    {
        $jsonPath = (string)($artifactPaths['effective_config_json'] ?? '');
        if ($jsonPath === '') {
            return null;
        }

        $resolved = self::resolvePath($baseDir, $jsonPath);
        if (!is_file($resolved)) {
            return null;
        }

        $decoded = json_decode((string)file_get_contents($resolved), true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function buildRejectionAttribution(
        string $scenarioName,
        array $scenarioReport,
        array $simGroups,
        array $baselineGroups,
        string $outputDir,
        string $seed
    ): array {
        $startedAt = microtime(true);
        $changedKnobs = self::collectChangedKnobs($simGroups, $baselineGroups, $scenarioReport);
        $gateCandidates = self::collectGateCandidates($scenarioReport);
        $primaryGate = $gateCandidates[0] ?? [
            'flag' => 'unknown_regression',
            'label' => 'Unclassified regression flag',
            'source' => 'scenario',
            'score' => 0.0,
            'evidence' => [],
            'rationale' => 'No regression evidence was available beyond the reject disposition.',
        ];
        $secondaryGates = array_slice($gateCandidates, 1);

        $activeKnobs = array_values(array_filter($changedKnobs, static function (array $knob): bool {
            return (string)($knob['classification'] ?? '') === 'active';
        }));
        $inactiveKnobs = array_values(array_filter($changedKnobs, static function (array $knob): bool {
            return (string)($knob['classification'] ?? '') === 'inactive';
        }));

        $interactionAmbiguity = self::buildInteractionAmbiguity($activeKnobs, $changedKnobs);
        $rankedKnobs = self::rankLikelyCausalKnobs($changedKnobs, $primaryGate, $secondaryGates, $interactionAmbiguity);
        $confidenceNotes = self::buildConfidenceNotes(
            $scenarioReport,
            $changedKnobs,
            $activeKnobs,
            $inactiveKnobs,
            $interactionAmbiguity,
            $primaryGate
        );

        $report = [
            'schema_version' => self::REJECTION_ATTRIBUTION_SCHEMA_VERSION,
            'generated_at' => gmdate('c'),
            'comparator_seed' => $seed,
            'scenario_name' => $scenarioName,
            'recommended_disposition' => (string)($scenarioReport['recommended_disposition'] ?? 'reject'),
            'control_baseline_comparison' => self::buildControlComparison($scenarioReport),
            'changed_knobs' => $changedKnobs,
            'knob_activity_summary' => [
                'active_count' => count($activeKnobs),
                'inactive_count' => count($inactiveKnobs),
                'unknown_count' => count($changedKnobs) - count($activeKnobs) - count($inactiveKnobs),
                'active_paths' => array_values(array_map(static fn(array $knob): string => (string)$knob['path'], $activeKnobs)),
                'inactive_paths' => array_values(array_map(static fn(array $knob): string => (string)$knob['path'], $inactiveKnobs)),
            ],
            'primary_failed_gate' => $primaryGate,
            'secondary_regressions' => $secondaryGates,
            'likely_causal_knob_ranking' => $rankedKnobs,
            'interaction_ambiguity' => $interactionAmbiguity,
            'confidence_notes' => $confidenceNotes,
        ];

        $artifactPaths = self::writeRejectionAttributionArtifacts($report, $outputDir, $seed, $scenarioName);

        return [
            'report' => $report,
            'artifact_paths' => $artifactPaths,
            'timing_ms' => self::msSince($startedAt),
        ];
    }

    private static function buildControlComparison(array $scenarioReport): array
    {
        $simulators = [];
        foreach ((array)($scenarioReport['simulator_comparisons'] ?? []) as $comparison) {
            $simulators[] = [
                'simulator_type' => (string)($comparison['simulator_type'] ?? 'unknown'),
                'signature' => (string)($comparison['signature'] ?? 'unknown'),
                'paired_runs' => (int)($comparison['sample_size']['paired_runs'] ?? 0),
                'wins' => (int)($comparison['wins'] ?? 0),
                'losses' => (int)($comparison['losses'] ?? 0),
                'mixed_tradeoffs' => (int)($comparison['mixed_tradeoffs'] ?? 0),
                'regression_flags' => array_values((array)($comparison['regression_flags'] ?? [])),
                'key_deltas' => [
                    'lock_in_delta' => self::getNestedFloat((array)($comparison['delta_flags'] ?? []), ['lock_in_vs_natural_expiry', 'lock_in_delta']),
                    'natural_expiry_delta' => self::getNestedFloat((array)($comparison['delta_flags'] ?? []), ['lock_in_vs_natural_expiry', 'natural_expiry_delta']),
                    'late_active_engaged_rate_delta' => self::getNestedFloat((array)($comparison['delta_flags'] ?? []), ['late_active_participation', 'late_active_engaged_rate_delta']),
                    'concentration_top10_share_delta' => self::getNestedFloat((array)($comparison['delta_flags'] ?? []), ['concentration_drift', 'top10_share_delta']),
                    'skip_strategy_edge_delta' => self::getNestedFloat((array)($comparison['delta_flags'] ?? []), ['skip_rejoin_exploit', 'skip_strategy_edge_delta']),
                ],
            ];
        }

        return [
            'wins' => (int)($scenarioReport['wins'] ?? 0),
            'losses' => (int)($scenarioReport['losses'] ?? 0),
            'mixed_tradeoffs' => (int)($scenarioReport['mixed_tradeoffs'] ?? 0),
            'confidence_summary' => (string)($scenarioReport['confidence_notes'] ?? ''),
            'regression_flags' => array_values((array)($scenarioReport['regression_flags'] ?? [])),
            'cross_simulator_regression_flags' => array_values((array)($scenarioReport['cross_simulator_regression_flags'] ?? [])),
            'simulator_groups' => $simulators,
        ];
    }

    private static function collectChangedKnobs(array $simGroups, array $baselineGroups, array $scenarioReport): array
    {
        $surfaceMeta = EconomicCandidateValidator::allowedSurface();
        $knobs = [];

        foreach ($simGroups as $simulator => $signatureGroups) {
            foreach ($signatureGroups as $signature => $scenarioRuns) {
                $candidateRun = $scenarioRuns[0] ?? null;
                if (!is_array($candidateRun)) {
                    continue;
                }

                $baselineRuns = $baselineGroups[$simulator][$signature] ?? [];
                if ($baselineRuns === []) {
                    $fallback = $baselineGroups[$simulator] ?? [];
                    if (!empty($fallback)) {
                        $baselineRuns = reset($fallback);
                    }
                }
                $baselineRun = $baselineRuns[0] ?? null;
                $candidateAudit = (array)($candidateRun['config_audit_report'] ?? []);
                $baselineAudit = is_array($baselineRun) ? (array)($baselineRun['config_audit_report'] ?? []) : [];

                $candidateChanges = (array)($candidateAudit['requested_candidate_changes'] ?? []);
                foreach ($candidateChanges as $change) {
                    $path = (string)($change['path'] ?? '');
                    if ($path === '') {
                        continue;
                    }

                    $key = self::extractKnobKey($path);
                    $classification = !empty($change['is_active']) ? 'active' : 'inactive';
                    $baselineValue = self::lookupEffectiveConfigValue($baselineAudit, $path);
                    $candidateEffectiveValue = $change['effective_value'] ?? self::lookupEffectiveConfigValue($candidateAudit, $path);
                    $simulatorEvidence = [
                        'simulator_type' => $simulator,
                        'signature' => $signature,
                        'baseline_value' => $baselineValue,
                        'candidate_effective_value' => $candidateEffectiveValue,
                        'effective_source' => $change['effective_source'] ?? null,
                    ];

                    if (!isset($knobs[$path])) {
                        $knobs[$path] = [
                            'path' => $path,
                            'key' => $key,
                            'requested_value' => $change['requested_value'] ?? null,
                            'baseline_value' => $baselineValue,
                            'candidate_effective_value' => $candidateEffectiveValue,
                            'classification' => $classification,
                            'reason_code' => $change['reason_code'] ?? null,
                            'reason_detail' => $change['reason_detail'] ?? null,
                            'effective_source' => $change['effective_source'] ?? null,
                            'subsystem' => (string)($surfaceMeta[$key]['subsystem'] ?? 'unknown'),
                            'simulator_evidence' => [$simulatorEvidence],
                        ];
                        continue;
                    }

                    $knobs[$path]['simulator_evidence'][] = $simulatorEvidence;
                    if ($knobs[$path]['baseline_value'] === null && $baselineValue !== null) {
                        $knobs[$path]['baseline_value'] = $baselineValue;
                    }
                    if ($knobs[$path]['candidate_effective_value'] === null && $candidateEffectiveValue !== null) {
                        $knobs[$path]['candidate_effective_value'] = $candidateEffectiveValue;
                    }
                    if ((string)$knobs[$path]['classification'] !== 'active' && $classification === 'active') {
                        $knobs[$path]['classification'] = 'active';
                        $knobs[$path]['reason_code'] = null;
                        $knobs[$path]['reason_detail'] = null;
                    }
                }
            }
        }

        if ($knobs === []) {
            foreach ((array)($scenarioReport['simulator_comparisons'] ?? []) as $comparison) {
                foreach ((array)($comparison['metadata']['override_keys'] ?? []) as $key) {
                    $path = 'season.' . (string)$key;
                    if (isset($knobs[$path])) {
                        continue;
                    }
                    $knobs[$path] = [
                        'path' => $path,
                        'key' => (string)$key,
                        'requested_value' => null,
                        'baseline_value' => null,
                        'candidate_effective_value' => null,
                        'classification' => 'unknown',
                        'reason_code' => 'audit_unavailable',
                        'reason_detail' => 'No effective-config audit was available for this run group.',
                        'effective_source' => null,
                        'subsystem' => (string)($surfaceMeta[(string)$key]['subsystem'] ?? 'unknown'),
                        'simulator_evidence' => [],
                    ];
                }
            }
        }

        ksort($knobs);
        return array_values($knobs);
    }

    private static function lookupEffectiveConfigValue(array $auditReport, string $path): mixed
    {
        if ($auditReport === []) {
            return null;
        }

        if (str_starts_with($path, 'season.')) {
            $key = substr($path, 7);
            return $auditReport['effective_config']['season'][$key] ?? null;
        }
        if (str_starts_with($path, 'runtime.')) {
            $key = substr($path, 8);
            return $auditReport['effective_config']['runtime'][$key] ?? null;
        }
        return null;
    }

    private static function extractKnobKey(string $path): string
    {
        if (str_starts_with($path, 'season.')) {
            return substr($path, 7);
        }
        if (str_starts_with($path, 'runtime.')) {
            return substr($path, 8);
        }
        return $path;
    }

    private static function collectGateCandidates(array $scenarioReport): array
    {
        $candidates = [];
        foreach ((array)($scenarioReport['simulator_comparisons'] ?? []) as $comparison) {
            foreach ((array)($comparison['regression_flags'] ?? []) as $flag) {
                $candidates[] = self::buildGateCandidate((string)$flag, 'simulator', $comparison, $scenarioReport);
            }
        }
        foreach ((array)($scenarioReport['cross_simulator_regression_flags'] ?? []) as $flag) {
            $candidates[] = self::buildGateCandidate((string)$flag, 'cross_simulator', null, $scenarioReport);
        }

        usort($candidates, static function (array $left, array $right): int {
            return ($right['score'] <=> $left['score']) ?: strcmp((string)$left['flag'], (string)$right['flag']);
        });

        $unique = [];
        $result = [];
        foreach ($candidates as $candidate) {
            $flag = (string)$candidate['flag'];
            if (isset($unique[$flag])) {
                continue;
            }
            $unique[$flag] = true;
            $result[] = $candidate;
        }

        return $result;
    }

    private static function buildGateCandidate(string $flag, string $source, ?array $comparison, array $scenarioReport): array
    {
        $deltaFlags = (array)($comparison['delta_flags'] ?? []);
        $label = self::flagLabel($flag);
        $severity = self::flagSeverity($flag);
        $evidence = [];
        $magnitude = 1.0;

        switch ($flag) {
            case 'lock_in_down_but_expiry_dominance_up':
            case 'reduces_lock_in_but_expiry_dominance_rises':
                $lockDelta = self::getNestedFloat($deltaFlags, ['lock_in_vs_natural_expiry', 'lock_in_delta']) ?? 0.0;
                $expiryDelta = self::getNestedFloat($deltaFlags, ['lock_in_vs_natural_expiry', 'natural_expiry_delta']) ?? 0.0;
                $magnitude = max(0.0, (-$lockDelta * 100.0)) + max(0.0, $expiryDelta);
                $evidence = [
                    'simulator_type' => $comparison['simulator_type'] ?? null,
                    'lock_in_delta' => $lockDelta,
                    'natural_expiry_delta' => $expiryDelta,
                ];
                break;
            case 'long_run_concentration_worsened':
            case 'seasonal_fairness_improves_but_long_run_concentration_worsens':
                $top10Delta = self::getNestedFloat($deltaFlags, ['concentration_drift', 'top10_share_delta']) ?? 0.0;
                if ($source === 'cross_simulator') {
                    foreach ((array)($scenarioReport['simulator_comparisons'] ?? []) as $simComparison) {
                        if ((string)($simComparison['simulator_type'] ?? '') !== 'C') {
                            continue;
                        }
                        $top10Delta = self::getNestedFloat((array)($simComparison['delta_flags'] ?? []), ['concentration_drift', 'top10_share_delta']) ?? $top10Delta;
                        break;
                    }
                }
                $magnitude = max(0.0, $top10Delta * 1000.0);
                $evidence = [
                    'simulator_type' => $comparison['simulator_type'] ?? 'C',
                    'top10_share_delta' => $top10Delta,
                ];
                break;
            case 'skip_rejoin_exploit_worsened':
                $skipDelta = self::getNestedFloat($deltaFlags, ['skip_rejoin_exploit', 'skip_strategy_edge_delta']) ?? 0.0;
                $magnitude = max(0.0, $skipDelta / 100.0);
                $evidence = [
                    'simulator_type' => $comparison['simulator_type'] ?? 'C',
                    'skip_strategy_edge_delta' => $skipDelta,
                ];
                break;
            case 'engagement_up_but_t6_supply_spike':
            case 'improves_engagement_but_t6_supply_spikes':
                $engagementDelta = self::getNestedFloat($deltaFlags, ['late_active_participation', 'late_active_engaged_rate_delta']) ?? 0.0;
                $t6Delta = self::getNestedFloat($deltaFlags, ['t6_supply_and_sourcing', 't6_total_delta']) ?? 0.0;
                $magnitude = max(0.0, $engagementDelta * 100.0) + max(0.0, $t6Delta);
                $evidence = [
                    'simulator_type' => $comparison['simulator_type'] ?? null,
                    'late_active_engaged_rate_delta' => $engagementDelta,
                    't6_total_delta' => $t6Delta,
                ];
                break;
            case 'candidate_improves_B_but_worsens_C':
                $bComparison = null;
                $cComparison = null;
                foreach ((array)($scenarioReport['simulator_comparisons'] ?? []) as $simComparison) {
                    if ((string)($simComparison['simulator_type'] ?? '') === 'B') {
                        $bComparison = $simComparison;
                    } elseif ((string)($simComparison['simulator_type'] ?? '') === 'C') {
                        $cComparison = $simComparison;
                    }
                }
                $magnitude = max(
                    1.0,
                    ((float)(($bComparison['wins'] ?? 0) - ($bComparison['losses'] ?? 0)))
                    + ((float)(($cComparison['losses'] ?? 0) - ($cComparison['wins'] ?? 0)))
                );
                $evidence = [
                    'B' => $bComparison !== null ? ['wins' => (int)$bComparison['wins'], 'losses' => (int)$bComparison['losses']] : null,
                    'C' => $cComparison !== null ? ['wins' => (int)$cComparison['wins'], 'losses' => (int)$cComparison['losses']] : null,
                ];
                break;
            case 'dominant_archetype_shifted':
            case 'reduced_one_dominant_but_created_new_dominant':
                $evidence = [
                    'simulator_type' => $comparison['simulator_type'] ?? null,
                    'dominant_archetype_transition' => (array)($comparison['metadata']['dominant_archetype_transition'] ?? []),
                ];
                break;
            default:
                $evidence = [
                    'simulator_type' => $comparison['simulator_type'] ?? null,
                ];
                break;
        }

        return [
            'flag' => $flag,
            'label' => $label,
            'source' => $source,
            'score' => $severity + $magnitude,
            'evidence' => $evidence,
            'rationale' => self::flagRationale($flag, $source),
        ];
    }

    private static function buildInteractionAmbiguity(array $activeKnobs, array $changedKnobs): array
    {
        if (count($activeKnobs) > 1) {
            return [
                'present' => true,
                'type' => 'multi_knob_bundle',
                'note' => sprintf(
                    '%d active knobs changed together, so the causal ranking is heuristic and interaction effects are not isolated.',
                    count($activeKnobs)
                ),
            ];
        }

        if (count($activeKnobs) === 1) {
            return [
                'present' => false,
                'type' => 'single_active_knob',
                'note' => 'One active knob changed, which narrows attribution, but the comparator still measures outcome rather than a direct causal counterfactual.',
            ];
        }

        return [
            'present' => true,
            'type' => 'unresolved_activity',
            'note' => sprintf(
                'No active knob could be confirmed from the config audit across %d changed knob(s), so attribution remains highly uncertain.',
                count($changedKnobs)
            ),
        ];
    }

    private static function rankLikelyCausalKnobs(array $changedKnobs, array $primaryGate, array $secondaryGates, array $interactionAmbiguity): array
    {
        $ranked = [];
        $activeCount = count(array_filter($changedKnobs, static function (array $knob): bool {
            return (string)($knob['classification'] ?? '') === 'active';
        }));

        foreach ($changedKnobs as $knob) {
            $score = 0.0;
            $classification = (string)($knob['classification'] ?? 'unknown');
            if ($classification === 'active') {
                $score += 3.0;
            } elseif ($classification === 'inactive') {
                $score += 0.5;
            } else {
                $score += 1.0;
            }

            $primaryMatch = self::flagMatchScore((string)$knob['key'], (string)($knob['subsystem'] ?? 'unknown'), (string)($primaryGate['flag'] ?? ''));
            $score += $primaryMatch;

            foreach ($secondaryGates as $gate) {
                $score += self::flagMatchScore((string)$knob['key'], (string)($knob['subsystem'] ?? 'unknown'), (string)($gate['flag'] ?? '')) * 0.45;
            }

            if ($activeCount === 1 && $classification === 'active') {
                $score += 2.0;
            }
            if (!empty($interactionAmbiguity['present'])) {
                $score *= 0.85;
            }
            if ($classification === 'inactive') {
                $score *= 0.35;
            }

            $ranked[] = [
                'path' => (string)$knob['path'],
                'key' => (string)$knob['key'],
                'classification' => $classification,
                'subsystem' => (string)($knob['subsystem'] ?? 'unknown'),
                'score' => round($score, 3),
                'confidence' => self::knobConfidence($classification, $activeCount, !empty($interactionAmbiguity['present']), $primaryMatch),
                'rationale' => self::knobRationale($knob, $interactionAmbiguity, $primaryMatch),
            ];
        }

        usort($ranked, static function (array $left, array $right): int {
            return ($right['score'] <=> $left['score']) ?: strcmp((string)$left['path'], (string)$right['path']);
        });

        foreach ($ranked as $index => &$row) {
            $row['rank'] = $index + 1;
        }
        unset($row);

        return $ranked;
    }

    private static function buildConfidenceNotes(
        array $scenarioReport,
        array $changedKnobs,
        array $activeKnobs,
        array $inactiveKnobs,
        array $interactionAmbiguity,
        array $primaryGate
    ): array {
        $notes = [];
        $notes[] = (string)($scenarioReport['confidence_notes'] ?? 'Comparator confidence note unavailable.');

        $pairedGroups = count((array)($scenarioReport['simulator_comparisons'] ?? []));
        if ($pairedGroups <= 2) {
            $notes[] = sprintf('Evidence is sparse: only %d paired simulator group(s) contributed to this rejection.', $pairedGroups);
        }
        if ($changedKnobs === []) {
            $notes[] = 'No config-audit knob evidence was available, so causal ranking falls back to regression metadata only.';
        }
        if ($inactiveKnobs !== []) {
            $notes[] = 'Inactive or shadowed requested knobs are included for transparency but down-ranked as causal candidates.';
        }
        if (!empty($interactionAmbiguity['present'])) {
            $notes[] = (string)$interactionAmbiguity['note'];
        }
        if (count($activeKnobs) === 1) {
            $notes[] = 'A single active knob reduces bundle ambiguity, but this is still an attribution estimate rather than a counterfactual proof.';
        }
        if ((string)($primaryGate['source'] ?? '') === 'cross_simulator') {
            $notes[] = 'The primary failure is cross-simulator, so the report reflects an interaction-level regression instead of a single local metric threshold.';
        }

        return array_values(array_unique(array_filter($notes, static function ($note): bool {
            return trim((string)$note) !== '';
        })));
    }

    private static function writeRejectionAttributionArtifacts(array $report, string $outputDir, string $seed, string $scenarioName): array
    {
        $dir = rtrim($outputDir, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'rejections'
            . DIRECTORY_SEPARATOR . preg_replace('/[^A-Za-z0-9_-]/', '_', $seed)
            . DIRECTORY_SEPARATOR . preg_replace('/[^A-Za-z0-9_-]/', '_', $scenarioName);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $jsonPath = $dir . DIRECTORY_SEPARATOR . 'rejection_attribution.json';
        $mdPath = $dir . DIRECTORY_SEPARATOR . 'rejection_attribution.md';

        file_put_contents($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        file_put_contents($mdPath, self::buildRejectionAttributionMarkdown($report));

        return [
            'rejection_attribution_json' => $jsonPath,
            'rejection_attribution_md' => $mdPath,
        ];
    }

    private static function buildRejectionAttributionMarkdown(array $report): string
    {
        $lines = [];
        $lines[] = '# Rejection Attribution';
        $lines[] = '';
        $lines[] = '- Scenario: `' . (string)$report['scenario_name'] . '`';
        $lines[] = '- Disposition: `' . (string)$report['recommended_disposition'] . '`';
        $lines[] = '- Generated: `' . (string)$report['generated_at'] . '`';
        $lines[] = '';

        $comparison = (array)($report['control_baseline_comparison'] ?? []);
        $lines[] = '## Control vs Baseline';
        $lines[] = '';
        $lines[] = '- Wins: ' . (int)($comparison['wins'] ?? 0) . ' | Losses: ' . (int)($comparison['losses'] ?? 0) . ' | Mixed: ' . (int)($comparison['mixed_tradeoffs'] ?? 0);
        $lines[] = '- Comparator note: ' . (string)($comparison['confidence_summary'] ?? 'n/a');
        foreach ((array)($comparison['simulator_groups'] ?? []) as $group) {
            $lines[] = '- `' . (string)$group['simulator_type'] . '` '
                . (string)$group['signature']
                . ' | wins=' . (int)$group['wins']
                . ' losses=' . (int)$group['losses']
                . ' flags=' . (empty($group['regression_flags']) ? 'none' : implode(', ', (array)$group['regression_flags']));
        }
        $lines[] = '';
        $lines[] = '## Changed Knobs';
        $lines[] = '';
        if ((array)($report['changed_knobs'] ?? []) === []) {
            $lines[] = '- No knob audit data available.';
        } else {
            foreach ((array)$report['changed_knobs'] as $knob) {
                $lines[] = '- `' . (string)$knob['path'] . '` => ' . (string)$knob['classification']
                    . ' | baseline=' . self::formatValueInline($knob['baseline_value'] ?? null)
                    . ' | candidate=' . self::formatValueInline($knob['candidate_effective_value'] ?? null);
                if (!empty($knob['reason_detail'])) {
                    $lines[] = '  note=' . (string)$knob['reason_detail'];
                }
            }
        }
        $lines[] = '';
        $lines[] = '## Failed Gate';
        $lines[] = '';
        $lines[] = '- Primary: `' . (string)($report['primary_failed_gate']['flag'] ?? 'unknown') . '`'
            . ' | ' . (string)($report['primary_failed_gate']['label'] ?? 'Unclassified');
        foreach ((array)($report['secondary_regressions'] ?? []) as $gate) {
            $lines[] = '- Secondary: `' . (string)$gate['flag'] . '` | ' . (string)$gate['label'];
        }
        $lines[] = '';
        $lines[] = '## Causal Ranking';
        $lines[] = '';
        foreach ((array)($report['likely_causal_knob_ranking'] ?? []) as $row) {
            $lines[] = '- #' . (int)$row['rank'] . ' `' . (string)$row['path'] . '`'
                . ' | confidence=' . (string)$row['confidence']
                . ' | score=' . (string)$row['score'];
            $lines[] = '  rationale=' . (string)$row['rationale'];
        }
        $lines[] = '';
        $lines[] = '## Uncertainty';
        $lines[] = '';
        $lines[] = '- Interaction ambiguity: ' . (((bool)($report['interaction_ambiguity']['present'] ?? false)) ? 'present' : 'not explicit');
        $lines[] = '- Note: ' . (string)($report['interaction_ambiguity']['note'] ?? 'n/a');
        foreach ((array)($report['confidence_notes'] ?? []) as $note) {
            $lines[] = '- ' . (string)$note;
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private static function msSince(float $startedAt): int
    {
        return (int)round((microtime(true) - $startedAt) * 1000);
    }

    private static function flagSeverity(string $flag): float
    {
        return match ($flag) {
            'lock_in_down_but_expiry_dominance_up' => 100.0,
            'reduces_lock_in_but_expiry_dominance_rises' => 96.0,
            'long_run_concentration_worsened' => 92.0,
            'skip_rejoin_exploit_worsened' => 90.0,
            'seasonal_fairness_improves_but_long_run_concentration_worsens' => 88.0,
            'candidate_improves_B_but_worsens_C' => 84.0,
            'engagement_up_but_t6_supply_spike', 'improves_engagement_but_t6_supply_spikes' => 80.0,
            'reduced_one_dominant_but_created_new_dominant' => 74.0,
            'dominant_archetype_shifted' => 68.0,
            default => 60.0,
        };
    }

    private static function flagLabel(string $flag): string
    {
        return match ($flag) {
            'lock_in_down_but_expiry_dominance_up', 'reduces_lock_in_but_expiry_dominance_rises' => 'Lock-in weakened while expiry pressure rose',
            'long_run_concentration_worsened' => 'Long-run concentration worsened',
            'skip_rejoin_exploit_worsened' => 'Skip/rejoin edge worsened',
            'seasonal_fairness_improves_but_long_run_concentration_worsens' => 'Seasonal fairness improved but long-run concentration regressed',
            'candidate_improves_B_but_worsens_C' => 'Short-run gains flipped into long-run losses',
            'engagement_up_but_t6_supply_spike', 'improves_engagement_but_t6_supply_spikes' => 'Engagement gain came with excess T6 supply',
            'reduced_one_dominant_but_created_new_dominant' => 'One dominant strategy fell but another took over',
            'dominant_archetype_shifted' => 'Dominant archetype changed',
            default => str_replace('_', ' ', $flag),
        };
    }

    private static function flagRationale(string $flag, string $source): string
    {
        $prefix = ($source === 'cross_simulator')
            ? 'This gate is driven by a cross-simulator regression pattern.'
            : 'This gate is driven by direct simulator deltas.';

        return match ($flag) {
            'lock_in_down_but_expiry_dominance_up', 'reduces_lock_in_but_expiry_dominance_rises' => $prefix . ' Candidate output reduced lock-in while raising natural expiry.',
            'long_run_concentration_worsened' => $prefix . ' Candidate increased long-run top-share concentration.',
            'skip_rejoin_exploit_worsened' => $prefix . ' Candidate increased the skip-heavy edge instead of pushing it toward zero.',
            'seasonal_fairness_improves_but_long_run_concentration_worsens' => $prefix . ' Candidate improved short-run fairness but paid for it with worse long-run concentration.',
            'candidate_improves_B_but_worsens_C' => $prefix . ' Candidate looked better in B but clearly regressed in C.',
            'engagement_up_but_t6_supply_spike', 'improves_engagement_but_t6_supply_spikes' => $prefix . ' Engagement gain appears coupled to excess T6 supply growth.',
            'reduced_one_dominant_but_created_new_dominant', 'dominant_archetype_shifted' => $prefix . ' Archetype leadership changed enough to trigger a stability concern.',
            default => $prefix . ' The comparator marked this as a reject-level regression flag.',
        };
    }

    private static function flagMatchScore(string $key, string $subsystem, string $flag): float
    {
        $mapping = [
            'lock_in_down_but_expiry_dominance_up' => [
                'subsystems' => ['lock_in_expiry_incentives', 'hoarding_preservation_pressure', 'phase_timing'],
                'keys' => ['hoarding_min_factor_fp', 'hoarding_sink_enabled', 'hoarding_safe_hours', 'hoarding_safe_min_coins', 'hoarding_tier1_rate_hourly_fp', 'hoarding_tier2_rate_hourly_fp', 'hoarding_tier3_rate_hourly_fp', 'hoarding_sink_cap_ratio_fp', 'starprice_idle_weight_fp', 'starprice_max_upstep_fp', 'starprice_max_downstep_fp'],
            ],
            'reduces_lock_in_but_expiry_dominance_rises' => [
                'subsystems' => ['lock_in_expiry_incentives', 'hoarding_preservation_pressure', 'phase_timing'],
                'keys' => ['hoarding_min_factor_fp', 'hoarding_sink_enabled', 'hoarding_safe_hours', 'hoarding_safe_min_coins', 'hoarding_tier1_rate_hourly_fp', 'hoarding_tier2_rate_hourly_fp', 'hoarding_tier3_rate_hourly_fp', 'hoarding_sink_cap_ratio_fp', 'starprice_idle_weight_fp', 'starprice_max_upstep_fp', 'starprice_max_downstep_fp'],
            ],
            'long_run_concentration_worsened' => [
                'subsystems' => ['hoarding_preservation_pressure', 'boost_related', 'star_conversion_pricing'],
                'keys' => ['inflation_table', 'base_ubi_active_per_tick', 'base_ubi_idle_factor_fp', 'hoarding_tier1_rate_hourly_fp', 'hoarding_tier2_rate_hourly_fp', 'hoarding_tier3_rate_hourly_fp', 'hoarding_sink_cap_ratio_fp', 'starprice_table', 'star_price_cap', 'starprice_idle_weight_fp'],
            ],
            'seasonal_fairness_improves_but_long_run_concentration_worsens' => [
                'subsystems' => ['hoarding_preservation_pressure', 'boost_related', 'star_conversion_pricing'],
                'keys' => ['inflation_table', 'base_ubi_active_per_tick', 'base_ubi_idle_factor_fp', 'hoarding_tier1_rate_hourly_fp', 'hoarding_tier2_rate_hourly_fp', 'hoarding_tier3_rate_hourly_fp', 'hoarding_sink_cap_ratio_fp', 'starprice_table', 'star_price_cap', 'starprice_idle_weight_fp'],
            ],
            'skip_rejoin_exploit_worsened' => [
                'subsystems' => ['lock_in_expiry_incentives', 'phase_timing', 'hoarding_preservation_pressure'],
                'keys' => ['hoarding_min_factor_fp', 'hoarding_sink_enabled', 'hoarding_safe_hours', 'starprice_idle_weight_fp', 'base_ubi_active_per_tick'],
            ],
            'engagement_up_but_t6_supply_spike' => [
                'subsystems' => ['sigil_drop_tier_combine'],
                'keys' => [],
            ],
            'improves_engagement_but_t6_supply_spikes' => [
                'subsystems' => ['sigil_drop_tier_combine'],
                'keys' => [],
            ],
            'candidate_improves_B_but_worsens_C' => [
                'subsystems' => ['hoarding_preservation_pressure', 'boost_related', 'star_conversion_pricing', 'lock_in_expiry_incentives', 'phase_timing', 'sigil_drop_tier_combine'],
                'keys' => [],
            ],
            'dominant_archetype_shifted' => [
                'subsystems' => ['hoarding_preservation_pressure', 'boost_related', 'star_conversion_pricing', 'lock_in_expiry_incentives'],
                'keys' => [],
            ],
            'reduced_one_dominant_but_created_new_dominant' => [
                'subsystems' => ['hoarding_preservation_pressure', 'boost_related', 'star_conversion_pricing', 'lock_in_expiry_incentives'],
                'keys' => [],
            ],
        ];

        $meta = $mapping[$flag] ?? ['subsystems' => [], 'keys' => []];
        if (in_array($key, (array)$meta['keys'], true)) {
            return 4.0;
        }
        if (in_array($subsystem, (array)$meta['subsystems'], true)) {
            return 2.0;
        }
        return 0.35;
    }

    private static function knobConfidence(string $classification, int $activeCount, bool $ambiguous, float $primaryMatch): string
    {
        if ($classification !== 'active') {
            return 'low';
        }
        if ($activeCount === 1 && !$ambiguous && $primaryMatch >= 2.0) {
            return 'moderate';
        }
        return 'low';
    }

    private static function knobRationale(array $knob, array $interactionAmbiguity, float $primaryMatch): string
    {
        $parts = [];
        $parts[] = ((string)($knob['classification'] ?? '') === 'active')
            ? 'Knob was active in the effective config.'
            : 'Knob was not active in the effective config.';
        if ($primaryMatch >= 4.0) {
            $parts[] = 'Its key maps directly to the primary failed gate.';
        } elseif ($primaryMatch >= 2.0) {
            $parts[] = 'Its subsystem aligns with the primary failed gate.';
        } else {
            $parts[] = 'Its link to the primary gate is indirect.';
        }
        if (!empty($interactionAmbiguity['present'])) {
            $parts[] = 'Bundle interaction ambiguity lowers confidence.';
        }
        return implode(' ', $parts);
    }

    private static function formatValueInline(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES);
        }
        if (is_string($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES);
        }
        return (string)$value;
    }

    private static function buildSignature(string $simulator, array $cohort, array $horizon, array $payload): string
    {
        $ppa = (int)($cohort['players_per_archetype'] ?? ($payload['config']['players_per_archetype'] ?? 0));
        $seasons = (int)($horizon['season_count'] ?? ($payload['config']['season_count'] ?? (($simulator === 'B') ? 1 : 0)));

        return strtoupper($simulator) . '|ppa=' . $ppa . '|seasons=' . $seasons;
    }

    private static function readJsonFile(string $path): array
    {
        if ($path === '' || !is_file($path)) {
            throw new InvalidArgumentException('Comparator input file not found: ' . $path);
        }
        $decoded = json_decode((string)file_get_contents($path), true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Comparator input file is not valid JSON object: ' . $path);
        }
        return $decoded;
    }

    private static function resolvePath(string $baseDir, string $path): string
    {
        if ($path === '') {
            return $path;
        }

        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        if (preg_match('#^[A-Za-z]:[\\/]#', $normalized) === 1) {
            return $normalized;
        }
        if (str_starts_with($normalized, DIRECTORY_SEPARATOR)) {
            return $normalized;
        }
        if (is_file($normalized)) {
            return $normalized;
        }

        return rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $normalized;
    }
}
