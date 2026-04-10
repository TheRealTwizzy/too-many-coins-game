<?php
/**
 * Phase A — Baseline Analysis Aggregator
 *
 * Reads all baseline batch outputs and produces a unified analysis report
 * and a human-readable summary.
 *
 * Usage:
 *   php scripts/analyze_baseline.php --manifest=<batch_manifest.json> [OPTIONS]
 *
 * Options:
 *   --manifest=FILE   Path to batch_manifest.json (required)
 *   --output=DIR      Output directory (default: same as manifest dir)
 *   --help            Show this help
 *
 * Outputs:
 *   baseline_analysis_report.json   Machine-readable aggregated metrics
 *   baseline_summary.md             Human-readable summary
 */

$options = [
    'manifest' => null,
    'output'   => null,
];

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--manifest=')) {
        $options['manifest'] = substr($arg, 11);
    } elseif (str_starts_with($arg, '--output=')) {
        $options['output'] = substr($arg, 9);
    } elseif ($arg === '--help') {
        echo <<<'HELP'
Phase A — Baseline Analysis Aggregator

Reads all baseline batch outputs and produces:
  - baseline_analysis_report.json  (machine-readable)
  - baseline_summary.md            (human-readable)

Usage:
  php scripts/analyze_baseline.php --manifest=<batch_manifest.json> [OPTIONS]

Options:
  --manifest=FILE   Path to batch_manifest.json from run_baseline_batch.php (required)
  --output=DIR      Output directory (default: same directory as manifest)
  --help            Show this help

HELP;
        exit(0);
    }
}

if ($options['manifest'] === null) {
    fwrite(STDERR, "ERROR: --manifest is required.\n");
    exit(1);
}

$manifestPath = realpath($options['manifest']);
if ($manifestPath === false || !is_file($manifestPath)) {
    fwrite(STDERR, "ERROR: Manifest not found: {$options['manifest']}\n");
    exit(1);
}

$manifest = json_decode(file_get_contents($manifestPath), true);
if (!is_array($manifest) || !isset($manifest['runs'])) {
    fwrite(STDERR, "ERROR: Invalid manifest format.\n");
    exit(1);
}

if (empty($manifest['summary']['batch_valid'])) {
    fwrite(STDERR, "WARNING: Batch manifest reports failures. Analysis may be incomplete.\n");
}

$outputDir = $options['output'] ?? dirname($manifestPath);
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0777, true);
}

// --- Load all run outputs ---

$simBPayloads = [];
$simCPayloads = [];

foreach ($manifest['runs'] as $run) {
    if (($run['status'] ?? '') !== 'completed' && ($run['status'] ?? '') !== 'skipped') {
        continue;
    }
    $jsonPath = $run['output_json'] ?? null;
    if ($jsonPath === null || !is_file($jsonPath)) {
        fwrite(STDERR, "WARNING: Missing output for {$run['seed']} ppa={$run['ppa']}: {$jsonPath}\n");
        continue;
    }
    $payload = json_decode(file_get_contents($jsonPath), true);
    if (!is_array($payload)) {
        fwrite(STDERR, "WARNING: Invalid JSON in {$jsonPath}\n");
        continue;
    }

    if (($run['simulator'] ?? '') === 'B') {
        $simBPayloads[] = ['run' => $run, 'data' => $payload];
    } elseif (($run['simulator'] ?? '') === 'C') {
        $simCPayloads[] = ['run' => $run, 'data' => $payload];
    }
}

echo "Loaded " . count($simBPayloads) . " Sim B and " . count($simCPayloads) . " Sim C payloads.\n";

if (empty($simBPayloads) && empty($simCPayloads)) {
    fwrite(STDERR, "ERROR: No valid payloads found. Cannot produce analysis.\n");
    exit(1);
}

// --- Helper functions ---

function median(array $values): float {
    if (empty($values)) return 0.0;
    sort($values);
    $n = count($values);
    $mid = (int)floor(($n - 1) / 2);
    if ($n % 2 === 0) {
        return ($values[$mid] + $values[$mid + 1]) / 2.0;
    }
    return (float)$values[$mid];
}

function mean(array $values): float {
    if (empty($values)) return 0.0;
    return array_sum($values) / count($values);
}

function coefficientOfVariation(array $values): float {
    if (count($values) < 2) return 0.0;
    $m = mean($values);
    if (abs($m) < 0.0001) return 0.0;
    $variance = 0.0;
    foreach ($values as $v) {
        $variance += ($v - $m) * ($v - $m);
    }
    $variance /= count($values);
    return sqrt($variance) / abs($m);
}

function percentile(array $sorted, float $p): float {
    if (empty($sorted)) return 0.0;
    $n = count($sorted);
    $rank = ($p / 100.0) * ($n - 1);
    $lower = (int)floor($rank);
    $upper = (int)ceil($rank);
    if ($lower === $upper) return (float)$sorted[$lower];
    $frac = $rank - $lower;
    return $sorted[$lower] * (1.0 - $frac) + $sorted[$upper] * $frac;
}

// ==============================================================
// SECTION 1: Lock-in vs Expiry
// ==============================================================

function analyzeLockInExpiry(array $simBPayloads, array $simCPayloads): array {
    $byArchetype = [];
    $overallLockIns = 0;
    $overallTotal = 0;

    foreach ($simBPayloads as $entry) {
        $data = $entry['data'];
        foreach ((array)($data['archetypes'] ?? []) as $key => $arch) {
            if (!isset($byArchetype[$key])) {
                $byArchetype[$key] = ['lock_ins' => 0, 'expiry' => 0, 'total' => 0, 'label' => $arch['label'] ?? $key];
            }
            $byArchetype[$key]['lock_ins'] += (int)($arch['lock_in_count'] ?? 0);
            $byArchetype[$key]['expiry']   += (int)($arch['natural_expiry_count'] ?? 0);
            $byArchetype[$key]['total']    += (int)($arch['players'] ?? 0);
            $overallLockIns += (int)($arch['lock_in_count'] ?? 0);
            $overallTotal   += (int)($arch['players'] ?? 0);
        }
    }

    $archetypeRates = [];
    foreach ($byArchetype as $key => $a) {
        $total = max(1, $a['total']);
        $archetypeRates[$key] = [
            'label'             => $a['label'],
            'lock_in_rate'      => round($a['lock_ins'] / $total, 4),
            'natural_expiry_rate' => round($a['expiry'] / $total, 4),
            'sample_size'       => $a['total'],
        ];
    }

    $lockInTimingPhases = ['EARLY' => 0, 'MID' => 0, 'LATE_ACTIVE' => 0, 'BLACKOUT' => 0, 'NONE' => 0];
    foreach ($simBPayloads as $entry) {
        $diag = $entry['data']['diagnostics'] ?? [];
        foreach ($lockInTimingPhases as $phase => $_) {
            $lockInTimingPhases[$phase] += (int)($diag['lock_in_timing'][$phase] ?? 0);
        }
    }

    $totalTiming = max(1, array_sum($lockInTimingPhases));
    $lockInTimingRates = [];
    foreach ($lockInTimingPhases as $phase => $count) {
        $lockInTimingRates[$phase] = round($count / $totalTiming, 4);
    }

    return [
        'overall_lock_in_rate'      => $overallTotal > 0 ? round($overallLockIns / $overallTotal, 4) : 0.0,
        'overall_natural_expiry_rate' => $overallTotal > 0 ? round(1.0 - $overallLockIns / $overallTotal, 4) : 0.0,
        'overall_sample_size'       => $overallTotal,
        'by_archetype'              => $archetypeRates,
        'lock_in_timing_distribution' => $lockInTimingRates,
        'lock_in_timing_counts'     => $lockInTimingPhases,
    ];
}

// ==============================================================
// SECTION 2: Star Accumulation
// ==============================================================

function analyzeStarAccumulation(array $simBPayloads): array {
    $byArchetype = [];

    foreach ($simBPayloads as $entry) {
        $data = $entry['data'];
        foreach ((array)($data['archetypes'] ?? []) as $key => $arch) {
            if (!isset($byArchetype[$key])) {
                $byArchetype[$key] = ['label' => $arch['label'] ?? $key, 'stars_by_phase' => [], 'global_stars' => [], 'scores' => []];
            }
            foreach (['EARLY', 'MID', 'LATE_ACTIVE', 'BLACKOUT'] as $phase) {
                $byArchetype[$key]['stars_by_phase'][$phase][] = (int)($arch['stars_purchased_by_phase'][$phase] ?? 0);
            }
            $byArchetype[$key]['global_stars'][] = (int)($arch['global_stars_gained'] ?? 0);
        }

        foreach ((array)($data['players'] ?? []) as $player) {
            $key = $player['archetype_key'] ?? 'unknown';
            if (isset($byArchetype[$key])) {
                $byArchetype[$key]['scores'][] = (int)($player['final_effective_score'] ?? 0);
            }
        }
    }

    $result = [];
    foreach ($byArchetype as $key => $arch) {
        $phaseStats = [];
        foreach (['EARLY', 'MID', 'LATE_ACTIVE', 'BLACKOUT'] as $phase) {
            $vals = $arch['stars_by_phase'][$phase] ?? [];
            $phaseStats[$phase] = [
                'mean'   => round(mean($vals), 2),
                'median' => round(median($vals), 2),
            ];
        }
        $result[$key] = [
            'label'              => $arch['label'],
            'stars_by_phase'     => $phaseStats,
            'global_stars_mean'  => round(mean($arch['global_stars']), 2),
            'global_stars_median' => round(median($arch['global_stars']), 2),
            'score_mean'         => round(mean($arch['scores']), 2),
            'score_median'       => round(median($arch['scores']), 2),
        ];
    }

    return $result;
}

// ==============================================================
// SECTION 3: Sigil Tier Distribution
// ==============================================================

function analyzeSigilDistribution(array $simBPayloads): array {
    $byArchetype = [];
    $tiers = ['1','2','3','4','5','6'];

    foreach ($simBPayloads as $entry) {
        $data = $entry['data'];
        foreach ((array)($data['archetypes'] ?? []) as $key => $arch) {
            if (!isset($byArchetype[$key])) {
                $byArchetype[$key] = [
                    'label' => $arch['label'] ?? $key,
                    'acquired_by_tier' => array_fill_keys($tiers, []),
                    'spent_by_action' => [],
                    't6_total' => [],
                    't6_by_source' => ['drop' => [], 'combine' => [], 'theft' => []],
                    'players' => [],
                ];
            }
            foreach ($tiers as $t) {
                $byArchetype[$key]['acquired_by_tier'][$t][] = (int)($arch['sigils_acquired_by_tier'][$t] ?? 0);
            }
            foreach (['boost','combine','freeze','theft','melt'] as $action) {
                $byArchetype[$key]['spent_by_action'][$action][] = (int)($arch['sigils_spent_by_action'][$action] ?? 0);
            }
            $byArchetype[$key]['t6_total'][] = (int)($arch['t6_total_acquired'] ?? 0);
            foreach (['drop','combine','theft'] as $src) {
                $byArchetype[$key]['t6_by_source'][$src][] = (int)($arch['t6_by_source'][$src] ?? 0);
            }
            $byArchetype[$key]['players'][] = (int)($arch['players'] ?? 0);
        }
    }

    $result = [];
    foreach ($byArchetype as $key => $arch) {
        $tierStats = [];
        $perPlayerSamples = max(1, (int)mean($arch['players']));
        foreach ($tiers as $t) {
            $vals = $arch['acquired_by_tier'][$t];
            $tierStats[$t] = [
                'total_mean'      => round(mean($vals), 2),
                'per_player_mean' => $perPlayerSamples > 0 ? round(mean($vals) / $perPlayerSamples, 2) : 0,
            ];
        }

        $spentStats = [];
        foreach (['boost','combine','freeze','theft','melt'] as $action) {
            $vals = $arch['spent_by_action'][$action] ?? [];
            $spentStats[$action] = round(mean($vals), 2);
        }

        $t6Sources = [];
        foreach (['drop','combine','theft'] as $src) {
            $t6Sources[$src] = round(mean($arch['t6_by_source'][$src]), 2);
        }

        $result[$key] = [
            'label'            => $arch['label'],
            'acquired_by_tier' => $tierStats,
            'spent_by_action'  => $spentStats,
            't6_mean_total'    => round(mean($arch['t6_total']), 2),
            't6_by_source'     => $t6Sources,
        ];
    }

    return $result;
}

// ==============================================================
// SECTION 4: Boost Usage
// ==============================================================

function analyzeBoostUsage(array $simBPayloads): array {
    $byArchetype = [];

    foreach ($simBPayloads as $entry) {
        $data = $entry['data'];
        foreach ((array)($data['archetypes'] ?? []) as $key => $arch) {
            if (!isset($byArchetype[$key])) {
                $byArchetype[$key] = ['label' => $arch['label'] ?? $key, 'boost_activations' => [], 'scores' => []];
            }
            $boostCount = (int)($arch['sigils_spent_by_action']['boost'] ?? 0);
            $byArchetype[$key]['boost_activations'][] = $boostCount;
            $byArchetype[$key]['scores'][] = (int)($arch['average_final_effective_score'] ?? 0);
        }
    }

    $result = [];
    foreach ($byArchetype as $key => $arch) {
        $result[$key] = [
            'label'                   => $arch['label'],
            'mean_boost_activations'  => round(mean($arch['boost_activations']), 2),
            'mean_score'              => round(mean($arch['scores']), 2),
        ];
    }

    // Compute boost ROI: boost_focused vs regular
    $boostFocusedScore = $result['boost_focused']['mean_score'] ?? 0;
    $regularScore = $result['regular']['mean_score'] ?? 1;
    $boostROIRatio = $regularScore > 0 ? round($boostFocusedScore / $regularScore, 4) : 0.0;

    return [
        'by_archetype' => $result,
        'boost_focused_vs_regular_ratio' => $boostROIRatio,
    ];
}

// ==============================================================
// SECTION 5: Ranking Concentration
// ==============================================================

function analyzeRankingConcentration(array $simBPayloads, array $simCPayloads): array {
    // Sim B: per-run score concentration
    $top10Shares = [];

    foreach ($simBPayloads as $entry) {
        $players = (array)($entry['data']['players'] ?? []);
        $scores = [];
        foreach ($players as $p) {
            $scores[] = (int)($p['final_effective_score'] ?? 0);
        }
        if (empty($scores)) continue;

        rsort($scores);
        $total = array_sum($scores);
        if ($total <= 0) continue;

        $top10Count = max(1, (int)ceil(count($scores) * 0.1));
        $top10Sum = array_sum(array_slice($scores, 0, $top10Count));
        $top10Shares[] = $top10Sum / $total;
    }

    // Sim C: concentration drift
    $finalConcentrations = [];
    foreach ($simCPayloads as $entry) {
        $drift = (array)($entry['data']['concentration_drift'] ?? []);
        if (!empty($drift)) {
            $last = end($drift);
            $finalConcentrations[] = (float)($last['top_10_percent_share'] ?? 0);
        }
    }

    return [
        'sim_b_top10_share_mean'   => round(mean($top10Shares), 4),
        'sim_b_top10_share_median' => round(median($top10Shares), 4),
        'sim_b_top10_share_values' => array_map(fn($v) => round($v, 4), $top10Shares),
        'sim_c_final_top10_share_mean'   => round(mean($finalConcentrations), 4),
        'sim_c_final_top10_share_values' => array_map(fn($v) => round($v, 4), $finalConcentrations),
    ];
}

// ==============================================================
// SECTION 6: Archetype Outcome Spread
// ==============================================================

function analyzeArchetypeOutcomeSpread(array $simBPayloads): array {
    $byArchetype = [];

    foreach ($simBPayloads as $entry) {
        foreach ((array)($entry['data']['archetypes'] ?? []) as $key => $arch) {
            if (!isset($byArchetype[$key])) {
                $byArchetype[$key] = ['label' => $arch['label'] ?? $key, 'median_scores' => []];
            }
            $byArchetype[$key]['median_scores'][] = (int)($arch['median_final_effective_score'] ?? 0);
        }
    }

    $archetypeMeans = [];
    foreach ($byArchetype as $key => $arch) {
        $archetypeMeans[$key] = [
            'label'        => $arch['label'],
            'cross_seed_mean' => round(mean($arch['median_scores']), 2),
        ];
    }

    $allMeans = array_map(fn($a) => $a['cross_seed_mean'], $archetypeMeans);
    $overallMean = mean($allMeans);

    $dominant = [];
    $nonViable = [];
    foreach ($archetypeMeans as $key => $a) {
        $ratio = $overallMean > 0 ? $a['cross_seed_mean'] / $overallMean : 0;
        $archetypeMeans[$key]['ratio_to_overall'] = round($ratio, 4);
        if ($ratio > 2.0) $dominant[] = $key;
        if ($ratio < 0.5) $nonViable[] = $key;
    }

    return [
        'overall_mean_of_medians' => round($overallMean, 2),
        'by_archetype'            => $archetypeMeans,
        'dominant_archetypes'     => $dominant,
        'non_viable_archetypes'   => $nonViable,
    ];
}

// ==============================================================
// SECTION 7: Final Standing Distribution
// ==============================================================

function analyzeFinalStandings(array $simBPayloads): array {
    $allScores = [];
    $lockedInScores = [];
    $expiredScores = [];

    foreach ($simBPayloads as $entry) {
        foreach ((array)($entry['data']['players'] ?? []) as $p) {
            $score = (int)($p['final_effective_score'] ?? 0);
            $allScores[] = $score;
            if (!empty($p['locked_in'])) {
                $lockedInScores[] = $score;
            } else {
                $expiredScores[] = $score;
            }
        }
    }

    sort($allScores);
    sort($lockedInScores);
    sort($expiredScores);

    $histogram = [];
    foreach ([0, 50, 100, 200, 300, 500, 750, 1000, 2000, 5000] as $bucket) {
        $histogram[$bucket] = 0;
    }
    foreach ($allScores as $s) {
        $placed = false;
        $buckets = array_keys($histogram);
        for ($i = count($buckets) - 1; $i >= 0; $i--) {
            if ($s >= $buckets[$i]) {
                $histogram[$buckets[$i]]++;
                $placed = true;
                break;
            }
        }
        if (!$placed) $histogram[0]++;
    }

    return [
        'total_players'      => count($allScores),
        'mean_score'         => round(mean($allScores), 2),
        'median_score'       => round(median($allScores), 2),
        'p10'                => round(percentile($allScores, 10), 2),
        'p25'                => round(percentile($allScores, 25), 2),
        'p75'                => round(percentile($allScores, 75), 2),
        'p90'                => round(percentile($allScores, 90), 2),
        'locked_in_mean'     => round(mean($lockedInScores), 2),
        'locked_in_median'   => round(median($lockedInScores), 2),
        'expired_mean'       => round(mean($expiredScores), 2),
        'expired_median'     => round(median($expiredScores), 2),
        'locked_in_expired_gap' => round(mean($lockedInScores) - mean($expiredScores), 2),
        'score_histogram'    => $histogram,
    ];
}

// ==============================================================
// SECTION 8: Dominant Strategies
// ==============================================================

function analyzeDominantStrategies(array $simBPayloads): array {
    // Look for archetype+phase combos with outsized star purchases (proxy for aggressive strategy)
    $combos = [];

    foreach ($simBPayloads as $entry) {
        foreach ((array)($entry['data']['archetypes'] ?? []) as $key => $arch) {
            foreach (['EARLY', 'MID', 'LATE_ACTIVE', 'BLACKOUT'] as $phase) {
                $comboKey = "{$key}_{$phase}";
                if (!isset($combos[$comboKey])) {
                    $combos[$comboKey] = [
                        'archetype' => $key,
                        'phase'     => $phase,
                        'label'     => ($arch['label'] ?? $key) . " / {$phase}",
                        'scores'    => [],
                        'stars'     => [],
                    ];
                }
                $combos[$comboKey]['scores'][] = (int)($arch['average_final_effective_score'] ?? 0);
                $combos[$comboKey]['stars'][]  = (int)($arch['stars_purchased_by_phase'][$phase] ?? 0);
            }
        }
    }

    $overallMedianScore = 0;
    $allScores = [];
    foreach ($combos as $c) {
        $allScores = array_merge($allScores, $c['scores']);
    }
    $overallMedianScore = max(1, median($allScores));

    $dominant = [];
    foreach ($combos as $comboKey => $c) {
        $meanScore = mean($c['scores']);
        $ratio = $meanScore / $overallMedianScore;
        if ($ratio > 1.5) {
            $dominant[] = [
                'combo'      => $c['label'],
                'archetype'  => $c['archetype'],
                'phase'      => $c['phase'],
                'mean_score' => round($meanScore, 2),
                'ratio'      => round($ratio, 4),
            ];
        }
    }

    usort($dominant, fn($a, $b) => $b['ratio'] <=> $a['ratio']);

    return [
        'overall_median_score' => round($overallMedianScore, 2),
        'dominant_combos'      => $dominant,
    ];
}

// ==============================================================
// SECTION 9: Hoarding vs Spending
// ==============================================================

function analyzeHoardingSpending(array $simBPayloads): array {
    $byArchetype = [];

    foreach ($simBPayloads as $entry) {
        foreach ((array)($entry['data']['players'] ?? []) as $p) {
            $key = $p['archetype_key'] ?? 'unknown';
            if (!isset($byArchetype[$key])) {
                $byArchetype[$key] = [
                    'label'              => $p['archetype_label'] ?? $key,
                    'coins_earned'       => [],
                    'stars_purchased'    => [],
                    'hoarding_sink'      => [],
                    'spend_window'       => [],
                ];
            }

            $metrics = $p['metrics'] ?? [];
            $totalCoins = 0;
            $totalStars = 0;
            foreach (['EARLY','MID','LATE_ACTIVE','BLACKOUT'] as $phase) {
                $totalCoins += (int)($metrics['coins_earned_by_phase'][$phase] ?? 0);
                $totalStars += (int)($metrics['stars_purchased_by_phase'][$phase] ?? 0);
            }
            $byArchetype[$key]['coins_earned'][]    = $totalCoins;
            $byArchetype[$key]['stars_purchased'][]  = $totalStars;
            $byArchetype[$key]['hoarding_sink'][]    = (int)($metrics['hoarding_sink_total'] ?? 0);
            $byArchetype[$key]['spend_window'][]     = (int)($metrics['spend_window_total'] ?? 0);
        }
    }

    $result = [];
    foreach ($byArchetype as $key => $arch) {
        $meanCoins = mean($arch['coins_earned']);
        $meanStars = mean($arch['stars_purchased']);
        $result[$key] = [
            'label'              => $arch['label'],
            'mean_coins_earned'  => round($meanCoins, 2),
            'mean_stars_purchased' => round($meanStars, 2),
            'mean_hoarding_sink' => round(mean($arch['hoarding_sink']), 2),
            'mean_spend_window'  => round(mean($arch['spend_window']), 2),
            'coin_to_star_ratio' => $meanStars > 0 ? round($meanCoins / $meanStars, 2) : null,
        ];
    }

    // Hoarder vs regular comparison
    $hoarderScore = 0;
    $regularScore = 0;
    foreach ($simBPayloads as $entry) {
        foreach ((array)($entry['data']['archetypes'] ?? []) as $key => $arch) {
            if ($key === 'hoarder') $hoarderScore += (int)($arch['median_final_effective_score'] ?? 0);
            if ($key === 'regular') $regularScore += (int)($arch['median_final_effective_score'] ?? 0);
        }
    }
    $n = max(1, count($simBPayloads));
    $hoarderVsRegularRatio = $regularScore > 0 ? round(($hoarderScore / $n) / ($regularScore / $n), 4) : 0.0;

    return [
        'by_archetype'               => $result,
        'hoarder_vs_regular_ratio'   => $hoarderVsRegularRatio,
    ];
}

// ==============================================================
// SECTION 10: Phase-by-Phase Behavior
// ==============================================================

function analyzePhaseByPhase(array $simBPayloads): array {
    $phases = ['EARLY', 'MID', 'LATE_ACTIVE', 'BLACKOUT'];
    $actions = ['boost', 'combine', 'freeze', 'theft'];

    $totals = [];
    foreach ($phases as $phase) {
        $totals[$phase] = array_fill_keys($actions, 0);
    }

    foreach ($simBPayloads as $entry) {
        $diag = $entry['data']['diagnostics'] ?? [];
        $vol = $diag['action_volume_by_phase'] ?? [];
        foreach ($phases as $phase) {
            foreach ($actions as $action) {
                $totals[$phase][$action] += (int)($vol[$phase][$action] ?? 0);
            }
        }
    }

    $grandTotal = 0;
    foreach ($totals as $phase => $acts) {
        $grandTotal += array_sum($acts);
    }

    $phaseShares = [];
    foreach ($totals as $phase => $acts) {
        $phaseTotal = array_sum($acts);
        $phaseShares[$phase] = [
            'actions'     => $acts,
            'phase_total' => $phaseTotal,
            'share'       => $grandTotal > 0 ? round($phaseTotal / $grandTotal, 4) : 0,
        ];
    }

    // Engagement rates
    $engagementByPhase = [];
    foreach ($simBPayloads as $entry) {
        $diag = $entry['data']['diagnostics'] ?? [];
        $engagementByPhase[] = (float)($diag['late_active_engaged_rate'] ?? 0);
    }

    return [
        'action_distribution' => $phaseShares,
        'grand_total_actions' => $grandTotal,
        'late_active_engaged_rate_mean' => round(mean($engagementByPhase), 4),
    ];
}

// ==============================================================
// SECTION 11: Cross-Seed Stability
// ==============================================================

function analyzeCrossSeedStability(array $simBPayloads): array {
    // Group key metrics by seed
    $bySeed = [];
    foreach ($simBPayloads as $entry) {
        $seed = $entry['run']['seed'] ?? 'unknown';
        if (!isset($bySeed[$seed])) {
            $bySeed[$seed] = ['lock_in_rates' => [], 'mean_scores' => [], 'expiry_rates' => []];
        }
        $diag = $entry['data']['diagnostics'] ?? [];
        $bySeed[$seed]['expiry_rates'][] = (float)($diag['natural_expiry_rate'] ?? 0);

        $players = (array)($entry['data']['players'] ?? []);
        $scores = array_map(fn($p) => (int)($p['final_effective_score'] ?? 0), $players);
        $total = max(1, count($players));
        $lockedIn = count(array_filter($players, fn($p) => !empty($p['locked_in'])));
        $bySeed[$seed]['lock_in_rates'][] = $lockedIn / $total;
        $bySeed[$seed]['mean_scores'][] = mean($scores);
    }

    // Aggregate across all seeds (one value per seed by averaging within-seed)
    $seedLockInRates = [];
    $seedMeanScores = [];
    $seedExpiryRates = [];
    foreach ($bySeed as $seed => $data) {
        $seedLockInRates[] = mean($data['lock_in_rates']);
        $seedMeanScores[]  = mean($data['mean_scores']);
        $seedExpiryRates[] = mean($data['expiry_rates']);
    }

    $metrics = [
        'lock_in_rate' => [
            'values' => array_map(fn($v) => round($v, 4), $seedLockInRates),
            'cv'     => round(coefficientOfVariation($seedLockInRates), 4),
        ],
        'mean_score' => [
            'values' => array_map(fn($v) => round($v, 2), $seedMeanScores),
            'cv'     => round(coefficientOfVariation($seedMeanScores), 4),
        ],
        'expiry_rate' => [
            'values' => array_map(fn($v) => round($v, 4), $seedExpiryRates),
            'cv'     => round(coefficientOfVariation($seedExpiryRates), 4),
        ],
    ];

    $unstable = [];
    foreach ($metrics as $name => $m) {
        if ($m['cv'] > 0.15) {
            $unstable[] = $name;
        }
    }

    return [
        'seed_count'       => count($bySeed),
        'metrics'          => $metrics,
        'unstable_metrics' => $unstable,
        'stability_threshold' => 0.15,
    ];
}

// ==============================================================
// SECTION 12: Star Pricing
// ==============================================================

function analyzeStarPricing(array $simBPayloads): array {
    $perSeed = [];

    foreach ($simBPayloads as $entry) {
        $seed = $entry['run']['seed'] ?? 'unknown';
        $summary = $entry['data']['diagnostics']['star_price_summary'] ?? null;
        if ($summary === null) {
            continue;
        }
        if (!isset($perSeed[$seed])) {
            $perSeed[$seed] = ['means' => [], 'cap_shares' => [], 'floor_shares' => [], 'min' => PHP_INT_MAX, 'max' => 0];
        }
        $perSeed[$seed]['means'][] = (float)($summary['mean'] ?? 0);
        $perSeed[$seed]['cap_shares'][] = (float)($summary['cap_share'] ?? 0);
        $perSeed[$seed]['floor_shares'][] = (float)($summary['floor_share'] ?? 0);
        if ((int)($summary['min'] ?? PHP_INT_MAX) < $perSeed[$seed]['min']) $perSeed[$seed]['min'] = (int)$summary['min'];
        if ((int)($summary['max'] ?? 0) > $perSeed[$seed]['max']) $perSeed[$seed]['max'] = (int)$summary['max'];
    }

    if (empty($perSeed)) {
        return ['available' => false, 'reason' => 'No star_price_summary data in simulation outputs.'];
    }

    // Average mean price per seed, then compute CV across seeds
    $seedMeans = [];
    $allCapShares = [];
    $allFloorShares = [];
    foreach ($perSeed as $seed => $data) {
        $seedMeans[] = mean($data['means']);
        $allCapShares = array_merge($allCapShares, $data['cap_shares']);
        $allFloorShares = array_merge($allFloorShares, $data['floor_shares']);
    }

    $priceCv = coefficientOfVariation($seedMeans);
    $meanCapShare = mean($allCapShares);
    $meanFloorShare = mean($allFloorShares);
    $stuckShare = $meanCapShare + $meanFloorShare;

    return [
        'available'       => true,
        'seed_count'      => count($perSeed),
        'mean_price_by_seed' => array_map(fn($v) => round($v, 2), $seedMeans),
        'price_cv_across_seeds' => round($priceCv, 4),
        'mean_cap_share'  => round($meanCapShare, 4),
        'mean_floor_share' => round($meanFloorShare, 4),
        'stuck_at_cap_or_floor_share' => round($stuckShare, 4),
        'global_min_price' => min(array_column(array_values($perSeed), 'min')),
        'global_max_price' => max(array_column(array_values($perSeed), 'max')),
    ];
}

// ==============================================================
// SECTION 13: Progression Pacing (direct score at phase boundary)
// ==============================================================

function analyzeProgressionPacing(array $simBPayloads): array {
    $byArchetype = [];

    foreach ($simBPayloads as $entry) {
        foreach ((array)($entry['data']['archetypes'] ?? []) as $key => $arch) {
            if (!isset($byArchetype[$key])) {
                $byArchetype[$key] = ['label' => $arch['label'] ?? $key, 'phase_end_scores' => ['EARLY' => [], 'MID' => [], 'LATE_ACTIVE' => []], 'final_scores' => []];
            }
            $scoreSnap = $arch['score_at_phase_end'] ?? [];
            foreach (['EARLY', 'MID', 'LATE_ACTIVE'] as $phase) {
                $val = $scoreSnap[$phase] ?? null;
                if ($val !== null && isset($val['median'])) {
                    $byArchetype[$key]['phase_end_scores'][$phase][] = (int)$val['median'];
                }
            }
            $byArchetype[$key]['final_scores'][] = (int)($arch['median_final_effective_score'] ?? 0);
        }
    }

    // Also get the overall final median from players
    $allFinalScores = [];
    foreach ($simBPayloads as $entry) {
        foreach ((array)($entry['data']['players'] ?? []) as $p) {
            $allFinalScores[] = (int)($p['final_effective_score'] ?? 0);
        }
    }
    sort($allFinalScores);
    $overallFinalMedian = median($allFinalScores);

    $result = [];
    foreach ($byArchetype as $key => $arch) {
        $phases = [];
        foreach (['EARLY', 'MID', 'LATE_ACTIVE'] as $phase) {
            $vals = $arch['phase_end_scores'][$phase];
            if (!empty($vals)) {
                $med = median($vals);
                $phases[$phase] = [
                    'median_score' => round($med, 2),
                    'ratio_to_final' => $overallFinalMedian > 0 ? round($med / $overallFinalMedian, 4) : 0,
                ];
            } else {
                $phases[$phase] = null;
            }
        }
        $result[$key] = [
            'label' => $arch['label'],
            'phase_end_scores' => $phases,
            'archetype_final_median' => round(median($arch['final_scores']), 2),
        ];
    }

    // Aggregate across all archetypes: median score at each phase boundary
    $aggregated = [];
    foreach (['EARLY', 'MID', 'LATE_ACTIVE'] as $phase) {
        $allPhaseScores = [];
        foreach ($byArchetype as $arch) {
            $allPhaseScores = array_merge($allPhaseScores, $arch['phase_end_scores'][$phase]);
        }
        if (!empty($allPhaseScores)) {
            $med = median($allPhaseScores);
            $aggregated[$phase] = [
                'median_score' => round($med, 2),
                'ratio_to_final' => $overallFinalMedian > 0 ? round($med / $overallFinalMedian, 4) : 0,
            ];
        } else {
            $aggregated[$phase] = null;
        }
    }

    $available = !empty($aggregated['EARLY']) || !empty($aggregated['MID']);

    return [
        'available'           => $available,
        'overall_final_median' => round($overallFinalMedian, 2),
        'aggregated'          => $aggregated,
        'by_archetype'        => $result,
    ];
}

// ==============================================================
// SECTION 14: Mechanic Attribution (boost coin contribution)
// ==============================================================

function analyzeMechanicAttribution(array $simBPayloads): array {
    $byArchetype = [];
    $allPlayers = [];

    foreach ($simBPayloads as $entry) {
        foreach ((array)($entry['data']['archetypes'] ?? []) as $key => $arch) {
            if (!isset($byArchetype[$key])) {
                $byArchetype[$key] = [
                    'label' => $arch['label'] ?? $key,
                    'total_coins' => [],
                    'boosted_coins' => [],
                    'ticks_boosted' => [],
                    'ticks_frozen' => [],
                ];
            }
            $totalCoins = array_sum($arch['coins_earned_by_phase'] ?? []);
            $boostedCoins = (int)($arch['coins_earned_while_boosted'] ?? 0);
            $byArchetype[$key]['total_coins'][] = $totalCoins;
            $byArchetype[$key]['boosted_coins'][] = $boostedCoins;
            $byArchetype[$key]['ticks_boosted'][] = (int)($arch['ticks_boosted'] ?? 0);
            $byArchetype[$key]['ticks_frozen'][] = (int)($arch['ticks_frozen'] ?? 0);
        }

        // Collect per-player data for top-quartile analysis
        foreach ((array)($entry['data']['players'] ?? []) as $p) {
            $metrics = $p['metrics'] ?? [];
            $totalCoins = 0;
            foreach (['EARLY','MID','LATE_ACTIVE','BLACKOUT'] as $phase) {
                $totalCoins += (int)($metrics['coins_earned_by_phase'][$phase] ?? 0);
            }
            $allPlayers[] = [
                'archetype_key' => $p['archetype_key'] ?? 'unknown',
                'final_score' => (int)($p['final_effective_score'] ?? 0),
                'total_coins' => $totalCoins,
                'boosted_coins' => (int)($metrics['coins_earned_while_boosted'] ?? 0),
                'ticks_boosted' => (int)($metrics['ticks_boosted'] ?? 0),
                'ticks_frozen' => (int)($metrics['ticks_frozen'] ?? 0),
            ];
        }
    }

    // Per-archetype boost contribution ratio
    $archetypeResult = [];
    foreach ($byArchetype as $key => $arch) {
        $meanTotal = mean($arch['total_coins']);
        $meanBoosted = mean($arch['boosted_coins']);
        $archetypeResult[$key] = [
            'label' => $arch['label'],
            'mean_total_coins' => round($meanTotal, 2),
            'mean_boosted_coins' => round($meanBoosted, 2),
            'boost_coin_share' => $meanTotal > 0 ? round($meanBoosted / $meanTotal, 4) : 0,
            'mean_ticks_boosted' => round(mean($arch['ticks_boosted']), 2),
            'mean_ticks_frozen' => round(mean($arch['ticks_frozen']), 2),
        ];
    }

    // Top-quartile analysis for overpowered-mechanic detection
    if (!empty($allPlayers)) {
        usort($allPlayers, fn($a, $b) => $b['final_score'] <=> $a['final_score']);
        $q1Count = max(1, (int)ceil(count($allPlayers) * 0.25));
        $topQuartile = array_slice($allPlayers, 0, $q1Count);
        $bottomThreeQuartiles = array_slice($allPlayers, $q1Count);

        $topMeanCoins = mean(array_column($topQuartile, 'total_coins'));
        $topMeanBoosted = mean(array_column($topQuartile, 'boosted_coins'));
        $botMeanCoins = mean(array_column($bottomThreeQuartiles, 'total_coins'));
        $botMeanBoosted = mean(array_column($bottomThreeQuartiles, 'boosted_coins'));

        $coinDelta = $topMeanCoins - $botMeanCoins;
        $boostedDelta = $topMeanBoosted - $botMeanBoosted;
        $boostContributionToScoreDelta = $coinDelta > 0 ? round($boostedDelta / $coinDelta, 4) : 0;

        $topQuartileAnalysis = [
            'players_in_top_quartile' => count($topQuartile),
            'top_mean_coins' => round($topMeanCoins, 2),
            'top_mean_boosted_coins' => round($topMeanBoosted, 2),
            'bottom_mean_coins' => round($botMeanCoins, 2),
            'bottom_mean_boosted_coins' => round($botMeanBoosted, 2),
            'coin_delta_top_vs_rest' => round($coinDelta, 2),
            'boosted_coin_delta' => round($boostedDelta, 2),
            'boost_share_of_coin_delta' => $boostContributionToScoreDelta,
        ];
    } else {
        $topQuartileAnalysis = null;
    }

    return [
        'available'              => true,
        'attribution_method'     => 'coins_earned_while_boosted: fraction of total coin accrual that occurred during active boost ticks',
        'by_archetype'           => $archetypeResult,
        'top_quartile_analysis'  => $topQuartileAnalysis,
    ];
}

// ==============================================================
// Build the full report
// ==============================================================

echo "Analyzing lock-in vs expiry...\n";
$lockInExpiry = analyzeLockInExpiry($simBPayloads, $simCPayloads);

echo "Analyzing star accumulation...\n";
$starAccumulation = analyzeStarAccumulation($simBPayloads);

echo "Analyzing sigil distribution...\n";
$sigilDistribution = analyzeSigilDistribution($simBPayloads);

echo "Analyzing boost usage...\n";
$boostUsage = analyzeBoostUsage($simBPayloads);

echo "Analyzing ranking concentration...\n";
$rankConcentration = analyzeRankingConcentration($simBPayloads, $simCPayloads);

echo "Analyzing archetype outcome spread...\n";
$archetypeSpread = analyzeArchetypeOutcomeSpread($simBPayloads);

echo "Analyzing final standings...\n";
$finalStandings = analyzeFinalStandings($simBPayloads);

echo "Analyzing dominant strategies...\n";
$dominantStrategies = analyzeDominantStrategies($simBPayloads);

echo "Analyzing hoarding vs spending...\n";
$hoardingSpending = analyzeHoardingSpending($simBPayloads);

echo "Analyzing phase-by-phase behavior...\n";
$phaseByPhase = analyzePhaseByPhase($simBPayloads);

echo "Analyzing cross-seed stability...\n";
$crossSeedStability = analyzeCrossSeedStability($simBPayloads);

echo "Analyzing star pricing...\n";
$starPricing = analyzeStarPricing($simBPayloads);

echo "Analyzing progression pacing...\n";
$progressionPacing = analyzeProgressionPacing($simBPayloads);

echo "Analyzing mechanic attribution...\n";
$mechanicAttribution = analyzeMechanicAttribution($simBPayloads);

$report = [
    'schema_version'        => 'tmc-baseline-analysis.v1',
    'generated_at'          => gmdate('c'),
    'manifest_path'         => $manifestPath,
    'season_config'         => $manifest['season_config'] ?? null,
    'sim_b_runs_loaded'     => count($simBPayloads),
    'sim_c_runs_loaded'     => count($simCPayloads),
    'lock_in_vs_expiry'     => $lockInExpiry,
    'star_accumulation'     => $starAccumulation,
    'sigil_tier_distribution' => $sigilDistribution,
    'boost_usage'           => $boostUsage,
    'ranking_concentration' => $rankConcentration,
    'archetype_outcome_spread' => $archetypeSpread,
    'final_standing_distribution' => $finalStandings,
    'dominant_strategies'   => $dominantStrategies,
    'hoarding_vs_spending'  => $hoardingSpending,
    'phase_by_phase_behavior' => $phaseByPhase,
    'cross_seed_stability'  => $crossSeedStability,
    'star_pricing'          => $starPricing,
    'progression_pacing'    => $progressionPacing,
    'mechanic_attribution'  => $mechanicAttribution,
];

// --- Write JSON report ---
$reportPath = $outputDir . DIRECTORY_SEPARATOR . 'baseline_analysis_report.json';
file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "\nReport: {$reportPath}\n";

// --- Write Markdown summary ---
$md = generateMarkdownSummary($report);
$summaryPath = $outputDir . DIRECTORY_SEPARATOR . 'baseline_summary.md';
file_put_contents($summaryPath, $md);
echo "Summary: {$summaryPath}\n";

function generateMarkdownSummary(array $r): string {
    $lines = [];
    $lines[] = '# Baseline Analysis Summary';
    $lines[] = '';
    $lines[] = 'Generated: ' . $r['generated_at'];
    $lines[] = 'Sim B runs: ' . $r['sim_b_runs_loaded'] . ' | Sim C runs: ' . $r['sim_c_runs_loaded'];
    $lines[] = '';

    // Lock-in vs Expiry
    $lie = $r['lock_in_vs_expiry'];
    $lines[] = '## 1. Lock-In vs Expiry';
    $lines[] = '';
    $lines[] = sprintf('- Overall lock-in rate: **%.1f%%** (n=%d)', $lie['overall_lock_in_rate'] * 100, $lie['overall_sample_size']);
    $lines[] = sprintf('- Overall natural expiry rate: **%.1f%%**', $lie['overall_natural_expiry_rate'] * 100);
    $lines[] = '';
    $lines[] = '**Lock-in timing distribution:**';
    foreach ($lie['lock_in_timing_distribution'] as $phase => $rate) {
        $lines[] = sprintf('- %s: %.1f%%', $phase, $rate * 100);
    }
    $lines[] = '';
    $lines[] = '**By archetype:**';
    $lines[] = '| Archetype | Lock-in Rate | Expiry Rate | Sample |';
    $lines[] = '|---|---|---|---|';
    foreach ($lie['by_archetype'] as $key => $a) {
        $lines[] = sprintf('| %s | %.1f%% | %.1f%% | %d |', $a['label'], $a['lock_in_rate'] * 100, $a['natural_expiry_rate'] * 100, $a['sample_size']);
    }
    $lines[] = '';

    // Star Accumulation
    $lines[] = '## 2. Star Accumulation';
    $lines[] = '';
    $lines[] = '| Archetype | Score Mean | Score Median | Global Stars Mean |';
    $lines[] = '|---|---|---|---|';
    foreach ($r['star_accumulation'] as $key => $a) {
        $lines[] = sprintf('| %s | %.0f | %.0f | %.0f |', $a['label'], $a['score_mean'], $a['score_median'], $a['global_stars_mean']);
    }
    $lines[] = '';

    // Sigil Distribution
    $lines[] = '## 3. Sigil Tier Distribution';
    $lines[] = '';
    $lines[] = '*(Per-player mean acquisition by tier, averaged across runs)*';
    $lines[] = '';
    $lines[] = '| Archetype | T1 | T2 | T3 | T4 | T5 | T6 |';
    $lines[] = '|---|---|---|---|---|---|---|';
    foreach ($r['sigil_tier_distribution'] as $key => $a) {
        $t = $a['acquired_by_tier'];
        $lines[] = sprintf('| %s | %.1f | %.1f | %.1f | %.1f | %.1f | %.1f |',
            $a['label'],
            $t['1']['per_player_mean'], $t['2']['per_player_mean'], $t['3']['per_player_mean'],
            $t['4']['per_player_mean'], $t['5']['per_player_mean'], $t['6']['per_player_mean']);
    }
    $lines[] = '';

    // Boost Usage
    $bu = $r['boost_usage'];
    $lines[] = '## 4. Boost Usage';
    $lines[] = '';
    $lines[] = sprintf('Boost-focused vs Regular score ratio: **%.2f**', $bu['boost_focused_vs_regular_ratio']);
    $lines[] = '';

    // Ranking Concentration
    $rc = $r['ranking_concentration'];
    $lines[] = '## 5. Ranking Concentration';
    $lines[] = '';
    $lines[] = sprintf('- Sim B top-10%% share (mean): **%.1f%%**', $rc['sim_b_top10_share_mean'] * 100);
    $lines[] = sprintf('- Sim C final top-10%% share (mean): **%.1f%%**', $rc['sim_c_final_top10_share_mean'] * 100);
    $lines[] = '';

    // Archetype Outcome Spread
    $aos = $r['archetype_outcome_spread'];
    $lines[] = '## 6. Archetype Outcome Spread';
    $lines[] = '';
    $lines[] = sprintf('Overall mean of medians: **%.0f**', $aos['overall_mean_of_medians']);
    $lines[] = '';
    if (!empty($aos['dominant_archetypes'])) {
        $lines[] = 'Dominant (>2x mean): ' . implode(', ', $aos['dominant_archetypes']);
    }
    if (!empty($aos['non_viable_archetypes'])) {
        $lines[] = 'Non-viable (<0.5x mean): ' . implode(', ', $aos['non_viable_archetypes']);
    }
    $lines[] = '';
    $lines[] = '| Archetype | Mean Score | Ratio to Overall |';
    $lines[] = '|---|---|---|';
    foreach ($aos['by_archetype'] as $key => $a) {
        $lines[] = sprintf('| %s | %.0f | %.2f |', $a['label'], $a['cross_seed_mean'], $a['ratio_to_overall']);
    }
    $lines[] = '';

    // Final Standings
    $fs = $r['final_standing_distribution'];
    $lines[] = '## 7. Final Standing Distribution';
    $lines[] = '';
    $lines[] = sprintf('- Total players: %d', $fs['total_players']);
    $lines[] = sprintf('- Mean: %.0f | Median: %.0f | P10: %.0f | P90: %.0f', $fs['mean_score'], $fs['median_score'], $fs['p10'], $fs['p90']);
    $lines[] = sprintf('- Locked-in mean: %.0f | Expired mean: %.0f | Gap: %.0f', $fs['locked_in_mean'], $fs['expired_mean'], $fs['locked_in_expired_gap']);
    $lines[] = '';

    // Dominant Strategies
    $ds = $r['dominant_strategies'];
    $lines[] = '## 8. Dominant Strategies';
    $lines[] = '';
    if (empty($ds['dominant_combos'])) {
        $lines[] = 'No archetype+phase combos exceed 1.5x median threshold.';
    } else {
        $lines[] = '| Combo | Mean Score | Ratio |';
        $lines[] = '|---|---|---|';
        foreach ($ds['dominant_combos'] as $c) {
            $lines[] = sprintf('| %s | %.0f | %.2f |', $c['combo'], $c['mean_score'], $c['ratio']);
        }
    }
    $lines[] = '';

    // Hoarding vs Spending
    $hs = $r['hoarding_vs_spending'];
    $lines[] = '## 9. Hoarding vs Spending';
    $lines[] = '';
    $lines[] = sprintf('Hoarder vs Regular score ratio: **%.2f**', $hs['hoarder_vs_regular_ratio']);
    $lines[] = '';

    // Phase-by-Phase
    $pp = $r['phase_by_phase_behavior'];
    $lines[] = '## 10. Phase-by-Phase Behavior';
    $lines[] = '';
    $lines[] = sprintf('Grand total actions: %d', $pp['grand_total_actions']);
    $lines[] = sprintf('Late-active engaged rate (mean): **%.1f%%**', $pp['late_active_engaged_rate_mean'] * 100);
    $lines[] = '';
    $lines[] = '| Phase | Actions | Share |';
    $lines[] = '|---|---|---|';
    foreach ($pp['action_distribution'] as $phase => $data) {
        $lines[] = sprintf('| %s | %d | %.1f%% |', $phase, $data['phase_total'], $data['share'] * 100);
    }
    $lines[] = '';

    // Cross-Seed Stability
    $css = $r['cross_seed_stability'];
    $lines[] = '## 11. Cross-Seed Stability';
    $lines[] = '';
    $lines[] = sprintf('Seeds: %d | Threshold CV: %.2f', $css['seed_count'], $css['stability_threshold']);
    $lines[] = '';
    $lines[] = '| Metric | CV | Status |';
    $lines[] = '|---|---|---|';
    foreach ($css['metrics'] as $name => $m) {
        $status = $m['cv'] > $css['stability_threshold'] ? 'UNSTABLE' : 'OK';
        $lines[] = sprintf('| %s | %.4f | %s |', $name, $m['cv'], $status);
    }
    if (!empty($css['unstable_metrics'])) {
        $lines[] = '';
        $lines[] = 'Unstable metrics: ' . implode(', ', $css['unstable_metrics']);
    }
    $lines[] = '';

    // Star Pricing
    $sp = $r['star_pricing'] ?? null;
    $lines[] = '## 12. Star Pricing';
    $lines[] = '';
    if ($sp && ($sp['available'] ?? false)) {
        $lines[] = sprintf('Seeds: %d | Price CV across seeds: **%.4f**', $sp['seed_count'], $sp['price_cv_across_seeds']);
        $lines[] = sprintf('- Global min price: %d | Global max price: %d', $sp['global_min_price'], $sp['global_max_price']);
        $lines[] = sprintf('- Stuck at cap share: **%.1f%%** | Stuck at floor share: **%.1f%%**', $sp['mean_cap_share'] * 100, $sp['mean_floor_share'] * 100);
        $lines[] = sprintf('- Combined stuck share: **%.1f%%**', $sp['stuck_at_cap_or_floor_share'] * 100);
    } else {
        $lines[] = 'Star pricing data not available in simulation outputs.';
    }
    $lines[] = '';

    // Progression Pacing
    $ppg = $r['progression_pacing'] ?? null;
    $lines[] = '## 13. Progression Pacing';
    $lines[] = '';
    if ($ppg && ($ppg['available'] ?? false)) {
        $lines[] = sprintf('Overall final median score: **%.0f**', $ppg['overall_final_median']);
        $lines[] = '';
        $lines[] = '**Aggregated score at phase boundaries (ratio to final):**';
        $lines[] = '';
        $lines[] = '| Phase | Median Score | Ratio to Final |';
        $lines[] = '|---|---|---|';
        foreach (['EARLY', 'MID', 'LATE_ACTIVE'] as $phase) {
            $d = $ppg['aggregated'][$phase] ?? null;
            if ($d) {
                $lines[] = sprintf('| %s | %.0f | %.4f |', $phase, $d['median_score'], $d['ratio_to_final']);
            } else {
                $lines[] = sprintf('| %s | — | — |', $phase);
            }
        }
        $lines[] = '';
        $lines[] = '**By archetype:**';
        $lines[] = '';
        $lines[] = '| Archetype | EARLY | MID | LATE_ACTIVE | Final |';
        $lines[] = '|---|---|---|---|---|';
        foreach ($ppg['by_archetype'] as $key => $a) {
            $early = ($a['phase_end_scores']['EARLY'] ?? null) ? sprintf('%.0f', $a['phase_end_scores']['EARLY']['median_score']) : '—';
            $mid = ($a['phase_end_scores']['MID'] ?? null) ? sprintf('%.0f', $a['phase_end_scores']['MID']['median_score']) : '—';
            $late = ($a['phase_end_scores']['LATE_ACTIVE'] ?? null) ? sprintf('%.0f', $a['phase_end_scores']['LATE_ACTIVE']['median_score']) : '—';
            $lines[] = sprintf('| %s | %s | %s | %s | %.0f |', $a['label'], $early, $mid, $late, $a['archetype_final_median']);
        }
    } else {
        $lines[] = 'Progression pacing data not available (no phase-end score snapshots).';
    }
    $lines[] = '';

    // Mechanic Attribution
    $ma = $r['mechanic_attribution'] ?? null;
    $lines[] = '## 14. Mechanic Attribution';
    $lines[] = '';
    if ($ma && ($ma['available'] ?? false)) {
        $lines[] = '**Per-archetype boost contribution:**';
        $lines[] = '';
        $lines[] = '| Archetype | Boost Coin Share | Mean Ticks Boosted | Mean Ticks Frozen |';
        $lines[] = '|---|---|---|---|';
        foreach ($ma['by_archetype'] as $key => $a) {
            $lines[] = sprintf('| %s | %.1f%% | %.0f | %.0f |', $a['label'], $a['boost_coin_share'] * 100, $a['mean_ticks_boosted'], $a['mean_ticks_frozen']);
        }
        $lines[] = '';
        if ($ma['top_quartile_analysis'] ?? null) {
            $tq = $ma['top_quartile_analysis'];
            $lines[] = sprintf('**Top-quartile analysis** (n=%d):', $tq['players_in_top_quartile']);
            $lines[] = sprintf('- Top coin delta vs rest: %.0f', $tq['coin_delta_top_vs_rest']);
            $lines[] = sprintf('- Boost share of coin delta: **%.1f%%**', $tq['boost_share_of_coin_delta'] * 100);
        }
    } else {
        $lines[] = 'Mechanic attribution data not available.';
    }
    $lines[] = '';

    return implode("\n", $lines) . "\n";
}
