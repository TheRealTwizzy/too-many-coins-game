<?php

require_once __DIR__ . '/MetricsCollector.php';

class ResultComparator
{
    public const COMPARATOR_SCHEMA_VERSION = 'tmc-sim-comparator.v1';

    public static function run(array $options): array
    {
        $sweepManifestPath = isset($options['sweep_manifest']) ? (string)$options['sweep_manifest'] : null;
        $baselineBPaths = (array)($options['baseline_b_paths'] ?? []);
        $baselineCPaths = (array)($options['baseline_c_paths'] ?? []);
        $outputDir = (string)($options['output_dir'] ?? (__DIR__ . '/../../simulation_output/comparator'));
        $seed = (string)($options['seed'] ?? 'phase1-comparator');

        $dataset = self::buildDataset($sweepManifestPath, $baselineBPaths, $baselineCPaths);
        $scenarioReports = [];

        foreach ($dataset['scenario_groups'] as $scenarioName => $simGroups) {
            $scenarioReport = self::compareScenario($scenarioName, $simGroups, $dataset['baseline_groups']);
            $scenarioReports[] = $scenarioReport;
        }

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
            ],
            'scenarios' => $scenarioReports,
        ];

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }
        $baseName = 'comparison_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $seed);
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

    private static function compareScenario(string $scenarioName, array $simGroups, array $baselineGroups): array
    {
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

        return [
            'scenario_name' => $scenarioName,
            'wins' => $wins,
            'losses' => $losses,
            'mixed_tradeoffs' => $mixed,
            'confidence_notes' => sprintf('Paired samples: %d group comparisons across %d simulator group(s).', $sampleCount, count($simulatorComparisons)),
            'recommended_disposition' => $disposition,
            'simulator_comparisons' => $simulatorComparisons,
            'cross_simulator_regression_flags' => $crossFlags,
            'regression_flags' => $flags,
        ];
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
