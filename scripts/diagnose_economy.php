<?php
/**
 * Phase B — Economy Diagnosis Engine
 *
 * Consumes the Phase A baseline analysis report and applies threshold-based
 * detection rules to identify economy balance issues.
 *
 * Usage:
 *   php scripts/diagnose_economy.php --report=<baseline_analysis_report.json> [OPTIONS]
 *
 * Options:
 *   --report=FILE     Path to baseline_analysis_report.json (required)
 *   --output=DIR      Output directory (default: simulation_output/current-db/diagnosis/)
 *   --help            Show this help
 *
 * Outputs:
 *   diagnosis_report.json    Structured findings array
 *   diagnosis_summary.md     Human-readable findings ranked by severity
 */

// --- CLI argument parsing ---

$options = [
    'report' => null,
    'output' => null,
];

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--report=')) {
        $options['report'] = substr($arg, 9);
    } elseif (str_starts_with($arg, '--output=')) {
        $options['output'] = substr($arg, 9);
    } elseif ($arg === '--help') {
        echo <<<'HELP'
Phase B — Economy Diagnosis Engine

Consumes the Phase A baseline analysis report and applies threshold-based
detection rules to identify economy balance issues.

Usage:
  php scripts/diagnose_economy.php --report=<baseline_analysis_report.json> [OPTIONS]

Options:
  --report=FILE     Path to baseline_analysis_report.json (required)
  --output=DIR      Output directory (default: simulation_output/current-db/diagnosis/)
  --help            Show this help

Outputs:
  diagnosis_report.json    Structured findings array
  diagnosis_summary.md     Human-readable findings ranked by severity

HELP;
        exit(0);
    }
}

if ($options['report'] === null) {
    fwrite(STDERR, "ERROR: --report is required.\n");
    exit(1);
}

$reportPath = realpath($options['report']);
if ($reportPath === false || !is_file($reportPath)) {
    fwrite(STDERR, "ERROR: Report not found: {$options['report']}\n");
    exit(1);
}

$report = json_decode(file_get_contents($reportPath), true);
if (!is_array($report) || !isset($report['schema_version'])) {
    fwrite(STDERR, "ERROR: Invalid baseline analysis report format.\n");
    exit(1);
}

if ($report['schema_version'] !== 'tmc-baseline-analysis.v1') {
    fwrite(STDERR, "WARNING: Unexpected schema version: {$report['schema_version']}\n");
}

$outputDir = $options['output'] ?? 'simulation_output/current-db/diagnosis';
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0777, true);
}

echo "Loading baseline analysis report from: {$reportPath}\n";
echo "Sim B runs: {$report['sim_b_runs_loaded']}, Sim C runs: {$report['sim_c_runs_loaded']}\n\n";

// ================================================================
// Detection rules
// ================================================================

$findings = [];
$unsupported = [];
$findingId = 0;

/**
 * Helper: create a finding entry.
 */
function finding(
    int    &$id,
    string $severity,
    string $category,
    string $description,
    array  $metricEvidence,
    array  $affectedArchetypes = [],
    array  $affectedPhases = [],
    string $triggerRule = '',
    $threshold = null,
    $observedValue = null,
    string $confidence = 'HIGH',
    string $notes = ''
): array {
    $id++;
    return [
        'id'                  => "B{$id}",
        'severity'            => $severity,
        'category'            => $category,
        'description'         => $description,
        'metric_evidence'     => $metricEvidence,
        'affected_archetypes' => $affectedArchetypes,
        'affected_phases'     => $affectedPhases,
        'trigger_rule'        => $triggerRule,
        'threshold'           => $threshold,
        'observed_value'      => $observedValue,
        'confidence'          => $confidence,
        'notes'               => $notes,
    ];
}

// ----------------------------------------------------------------
// Rule 1: Underused mechanics — any action type <5% usage rate
// ----------------------------------------------------------------
echo "Checking: Underused mechanics...\n";
$ppb = $report['phase_by_phase_behavior'] ?? [];
$grandTotal = (int)($ppb['grand_total_actions'] ?? 0);

if ($grandTotal > 0) {
    // Sum each action type across all phases
    $actionTotals = ['boost' => 0, 'combine' => 0, 'freeze' => 0, 'theft' => 0];
    foreach (($ppb['action_distribution'] ?? []) as $phase => $data) {
        foreach ($actionTotals as $action => &$total) {
            $total += (int)($data['actions'][$action] ?? 0);
        }
        unset($total);
    }

    foreach ($actionTotals as $action => $count) {
        $share = $count / $grandTotal;
        if ($share < 0.05) {
            $findings[] = finding(
                $findingId,
                'MEDIUM',
                'underused_mechanics',
                "Action '{$action}' has only " . round($share * 100, 1) . "% usage rate across all archetypes and phases (threshold: <5%).",
                ['action' => $action, 'count' => $count, 'grand_total' => $grandTotal, 'share' => round($share, 4)],
                [], // all archetypes
                array_keys($ppb['action_distribution'] ?? []),
                'any action type with <5% usage rate across all archetypes',
                0.05,
                round($share, 4),
                'HIGH',
            );
        }
    }
} else {
    $unsupported[] = [
        'rule' => 'underused_mechanics',
        'reason' => 'No action volume data available (grand_total_actions = 0).',
    ];
}

// ----------------------------------------------------------------
// Rule 2: Overpowered mechanics — single mechanic >40% of
//         top-quartile score delta
// ----------------------------------------------------------------
echo "Checking: Overpowered mechanics...\n";
$mechAttr = $report['mechanic_attribution'] ?? [];
if (!empty($mechAttr['available']) && ($mechAttr['top_quartile_analysis'] ?? null)) {
    $tqAnalysis = $mechAttr['top_quartile_analysis'];
    $boostShareDelta = (float)($tqAnalysis['boost_share_of_coin_delta'] ?? 0);
    if ($boostShareDelta > 0.40) {
        $findings[] = finding(
            $findingId,
            'HIGH',
            'overpowered_mechanics',
            'Boost mechanic contributes ' . round($boostShareDelta * 100, 1) . '% of coin earning delta between top-quartile and remaining players (threshold: >40%).',
            [
                'boost_share_of_coin_delta' => round($boostShareDelta, 4),
                'coin_delta_top_vs_rest' => $tqAnalysis['coin_delta_top_vs_rest'],
                'boosted_coin_delta' => $tqAnalysis['boosted_coin_delta'],
                'players_in_top_quartile' => $tqAnalysis['players_in_top_quartile'],
            ],
            [],
            [],
            'boost share of top-quartile coin delta >40%',
            0.40,
            round($boostShareDelta, 4),
        );
    }

    // Also check per-archetype for any archetype where boost accounts for >60% of coins
    foreach ($mechAttr['by_archetype'] ?? [] as $key => $arch) {
        $share = (float)($arch['boost_coin_share'] ?? 0);
        if ($share > 0.60) {
            $findings[] = finding(
                $findingId,
                'MEDIUM',
                'overpowered_mechanics',
                'Archetype ' . ($arch['label'] ?? $key) . ' earns ' . round($share * 100, 1) . '% of coins while boosted (threshold: >60% per archetype).',
                ['archetype' => $key, 'boost_coin_share' => round($share, 4), 'mean_ticks_boosted' => $arch['mean_ticks_boosted']],
                [$key],
                [],
                'single archetype boost coin share >60%',
                0.60,
                round($share, 4),
            );
        }
    }
} else {
    $unsupported[] = [
        'rule' => 'overpowered_mechanics',
        'reason' => 'mechanic_attribution section not available in baseline analysis report.',
    ];
}

// ----------------------------------------------------------------
// Rule 3: Weak progression pacing — median score at phase boundary
//         <20% of final median
// ----------------------------------------------------------------
echo "Checking: Weak progression pacing...\n";
$progPacing = $report['progression_pacing'] ?? [];
if (!empty($progPacing['available'])) {
    $overallFinalMedian = (float)($progPacing['overall_final_median'] ?? 0);
    if ($overallFinalMedian > 0) {
        // Check aggregated phase-end scores directly
        foreach (['EARLY', 'MID'] as $phase) {
            $phaseData = $progPacing['aggregated'][$phase] ?? null;
            if ($phaseData === null) continue;
            $ratio = (float)($phaseData['ratio_to_final'] ?? 0);
            if ($ratio < 0.20) {
                $findings[] = finding(
                    $findingId,
                    'MEDIUM',
                    'weak_progression_pacing',
                    "Median effective score at {$phase} phase boundary is only " . round($ratio * 100, 1) . "% of final median (threshold: <20%).",
                    [
                        'phase' => $phase,
                        'median_score_at_boundary' => $phaseData['median_score'],
                        'final_median_score' => $overallFinalMedian,
                        'ratio' => round($ratio, 4),
                    ],
                    [],
                    [$phase],
                    'median score at phase boundary <20% of final median',
                    0.20,
                    round($ratio, 4),
                );
            }
        }

        // Also check per-archetype for severely behind archetypes
        foreach ($progPacing['by_archetype'] ?? [] as $key => $arch) {
            foreach (['EARLY', 'MID'] as $phase) {
                $phaseData = $arch['phase_end_scores'][$phase] ?? null;
                if ($phaseData === null) continue;
                $ratio = (float)($phaseData['ratio_to_final'] ?? 0);
                if ($ratio < 0.10) {
                    $findings[] = finding(
                        $findingId,
                        'MEDIUM',
                        'weak_progression_pacing',
                        'Archetype ' . ($arch['label'] ?? $key) . " has only " . round($ratio * 100, 1) . "% of final score at {$phase} boundary (threshold: <10% per archetype).",
                        [
                            'archetype' => $key,
                            'phase' => $phase,
                            'median_score_at_boundary' => $phaseData['median_score'],
                            'ratio' => round($ratio, 4),
                        ],
                        [$key],
                        [$phase],
                        'archetype score at phase boundary <10% of final median',
                        0.10,
                        round($ratio, 4),
                    );
                }
            }
        }
    }
} else {
    // Fallback: use cumulative star purchases as proxy
    $starAcc = $report['star_accumulation'] ?? [];
    $finalStandings = $report['final_standing_distribution'] ?? [];
    $overallMedianScore = (float)($finalStandings['median_score'] ?? 0);
    if ($overallMedianScore > 0 && !empty($starAcc)) {
        $phases = ['EARLY', 'MID', 'LATE_ACTIVE', 'BLACKOUT'];
        $allCumulativeByPhase = [];
        foreach ($phases as $phase) {
            $allCumulativeByPhase[$phase] = [];
        }
        foreach ($starAcc as $key => $arch) {
            $cumulative = 0.0;
            foreach ($phases as $phase) {
                $cumulative += (float)($arch['stars_by_phase'][$phase]['median'] ?? 0);
                $allCumulativeByPhase[$phase][] = $cumulative;
            }
        }
        foreach (['EARLY', 'MID'] as $phase) {
            $vals = $allCumulativeByPhase[$phase] ?? [];
            if (empty($vals)) continue;
            $medianAtBoundary = median($vals);
            $ratio = $medianAtBoundary / $overallMedianScore;
            if ($ratio < 0.20) {
                $findings[] = finding(
                    $findingId,
                    'MEDIUM',
                    'weak_progression_pacing',
                    "Median cumulative stars at {$phase} phase boundary is only " . round($ratio * 100, 1) . "% of final median score (threshold: <20%).",
                    ['phase' => $phase, 'median_cumulative_stars' => round($medianAtBoundary, 2), 'final_median_score' => $overallMedianScore, 'ratio' => round($ratio, 4)],
                    [],
                    [$phase],
                    'median score at phase boundary <20% of final median',
                    0.20,
                    round($ratio, 4),
                    'LOW',
                    'Fallback: uses cumulative star purchases as proxy — progression_pacing section not available.',
                );
            }
        }
    } else {
        $unsupported[] = [
            'rule' => 'weak_progression_pacing',
            'reason' => 'No progression_pacing or star_accumulation data available.',
        ];
    }
}

// ----------------------------------------------------------------
// Rule 4: Concentrated wealth — top-10% share >35% of total score
// ----------------------------------------------------------------
echo "Checking: Concentrated wealth...\n";
$rc = $report['ranking_concentration'] ?? [];
$top10Mean = (float)($rc['sim_b_top10_share_mean'] ?? 0);

if ($top10Mean > 0.35) {
    $findings[] = finding(
        $findingId,
        'HIGH',
        'concentrated_wealth',
        "Top-10% of players hold " . round($top10Mean * 100, 1) . "% of total score (threshold: >35%).",
        ['sim_b_top10_share_mean' => round($top10Mean, 4), 'sim_b_top10_share_median' => $rc['sim_b_top10_share_median'] ?? null],
        [],
        [],
        'top-10% share >35% of total score',
        0.35,
        round($top10Mean, 4),
    );
}

// Also check Sim C if available
$simCTop10 = (float)($rc['sim_c_final_top10_share_mean'] ?? 0);
if ($simCTop10 > 0.35) {
    $findings[] = finding(
        $findingId,
        'HIGH',
        'concentrated_wealth',
        "Sim C lifetime: top-10% share is " . round($simCTop10 * 100, 1) . "% (threshold: >35%).",
        ['sim_c_final_top10_share_mean' => round($simCTop10, 4)],
        [],
        [],
        'top-10% share >35% of total score (lifetime)',
        0.35,
        round($simCTop10, 4),
    );
}

// ----------------------------------------------------------------
// Rule 5: Lock-in timing pathologies — >60% in single phase
//         OR <15% lock-in rate overall
// ----------------------------------------------------------------
echo "Checking: Lock-in timing pathologies...\n";
$lie = $report['lock_in_vs_expiry'] ?? [];
$overallLockIn = (float)($lie['overall_lock_in_rate'] ?? 0);
$timingDist = $lie['lock_in_timing_distribution'] ?? [];

// Check overall rate
if ($overallLockIn < 0.15) {
    $findings[] = finding(
        $findingId,
        'HIGH',
        'lock_in_timing_pathologies',
        "Overall lock-in rate is only " . round($overallLockIn * 100, 1) . "% (threshold: <15%).",
        ['overall_lock_in_rate' => round($overallLockIn, 4), 'sample_size' => $lie['overall_sample_size'] ?? 0],
        [],
        [],
        '<15% lock-in rate overall',
        0.15,
        round($overallLockIn, 4),
    );
}

// Check single-phase concentration (excluding NONE)
foreach ($timingDist as $phase => $rate) {
    if ($phase === 'NONE') continue;
    if ((float)$rate > 0.60) {
        $findings[] = finding(
            $findingId,
            'HIGH',
            'lock_in_timing_pathologies',
            round($rate * 100, 1) . "% of lock-ins occur in {$phase} phase (threshold: >60% in single phase).",
            ['phase' => $phase, 'lock_in_share' => round($rate, 4), 'full_distribution' => $timingDist],
            [],
            [$phase],
            '>60% of lock-ins in single phase',
            0.60,
            round($rate, 4),
        );
    }
}

// ----------------------------------------------------------------
// Rule 6: Excessive expiry — natural expiry rate >50%
// ----------------------------------------------------------------
echo "Checking: Excessive expiry...\n";
$expiryRate = (float)($lie['overall_natural_expiry_rate'] ?? 0);
if ($expiryRate > 0.50) {
    $findings[] = finding(
        $findingId,
        'HIGH',
        'excessive_expiry',
        "Natural expiry rate is " . round($expiryRate * 100, 1) . "% (threshold: >50%).",
        ['overall_natural_expiry_rate' => round($expiryRate, 4)],
        [],
        [],
        'natural expiry rate >50% across all archetypes',
        0.50,
        round($expiryRate, 4),
    );
}

// ----------------------------------------------------------------
// Rule 7: Insufficient expiry — natural expiry rate <10%
// ----------------------------------------------------------------
echo "Checking: Insufficient expiry...\n";
if ($expiryRate < 0.10) {
    $findings[] = finding(
        $findingId,
        'MEDIUM',
        'insufficient_expiry',
        "Natural expiry rate is only " . round($expiryRate * 100, 1) . "% — no pressure to lock in (threshold: <10%).",
        ['overall_natural_expiry_rate' => round($expiryRate, 4)],
        [],
        [],
        'natural expiry rate <10%',
        0.10,
        round($expiryRate, 4),
    );
}

// ----------------------------------------------------------------
// Rule 8: Sigil scarcity — median T4+ acquisition <1.0 per player
// ----------------------------------------------------------------
echo "Checking: Sigil scarcity...\n";
$sigilDist = $report['sigil_tier_distribution'] ?? [];

if (!empty($sigilDist)) {
    // Average T4+ per_player_mean across all archetypes
    $t4PlusPerPlayer = [];
    foreach ($sigilDist as $key => $arch) {
        $t = $arch['acquired_by_tier'] ?? [];
        $sum = (float)($t['4']['per_player_mean'] ?? 0)
             + (float)($t['5']['per_player_mean'] ?? 0)
             + (float)($t['6']['per_player_mean'] ?? 0);
        $t4PlusPerPlayer[$key] = $sum;
    }
    $allValues = array_values($t4PlusPerPlayer);
    sort($allValues);
    $medianT4Plus = median($allValues);

    if ($medianT4Plus < 1.0) {
        // Find worst archetypes
        $affectedArch = [];
        foreach ($t4PlusPerPlayer as $key => $val) {
            if ($val < 1.0) $affectedArch[] = $key;
        }
        $findings[] = finding(
            $findingId,
            'MEDIUM',
            'sigil_scarcity',
            "Median T4+ sigil acquisition is " . round($medianT4Plus, 2) . " per player per season (threshold: <1.0).",
            ['median_t4_plus_per_player' => round($medianT4Plus, 2), 'per_archetype' => array_map(fn($v) => round($v, 2), $t4PlusPerPlayer)],
            $affectedArch,
            [],
            'median T4+ acquisition <1.0 per player per season',
            1.0,
            round($medianT4Plus, 2),
        );
    }
}

// ----------------------------------------------------------------
// Rule 9: Sigil overabundance — median total >20 per player
// ----------------------------------------------------------------
echo "Checking: Sigil overabundance...\n";

if (!empty($sigilDist)) {
    $totalPerPlayer = [];
    foreach ($sigilDist as $key => $arch) {
        $t = $arch['acquired_by_tier'] ?? [];
        $sum = 0.0;
        foreach (['1','2','3','4','5','6'] as $tier) {
            $sum += (float)($t[$tier]['per_player_mean'] ?? 0);
        }
        $totalPerPlayer[$key] = $sum;
    }
    $allValues = array_values($totalPerPlayer);
    sort($allValues);
    $medianTotal = median($allValues);

    if ($medianTotal > 20.0) {
        $affectedArch = [];
        foreach ($totalPerPlayer as $key => $val) {
            if ($val > 20.0) $affectedArch[] = $key;
        }
        $findings[] = finding(
            $findingId,
            'MEDIUM',
            'sigil_overabundance',
            "Median total sigil inventory is " . round($medianTotal, 1) . " per player (threshold: >20, cap is 25).",
            ['median_total_per_player' => round($medianTotal, 1), 'per_archetype' => array_map(fn($v) => round($v, 1), $totalPerPlayer)],
            $affectedArch,
            [],
            'median total sigil inventory >20 per player',
            20.0,
            round($medianTotal, 1),
        );
    }
}

// ----------------------------------------------------------------
// Rule 10: Star pricing issues — CV >0.3 or price stuck at cap/floor
// ----------------------------------------------------------------
echo "Checking: Star pricing issues...\n";
$starPricing = $report['star_pricing'] ?? [];
if (!empty($starPricing['available'])) {
    $priceCvAcrossSeeds = (float)($starPricing['price_cv_across_seeds'] ?? 0);
    $stuckShare = (float)($starPricing['stuck_at_cap_or_floor_share'] ?? 0);
    $capShare = (float)($starPricing['mean_cap_share'] ?? 0);
    $floorShare = (float)($starPricing['mean_floor_share'] ?? 0);

    if ($priceCvAcrossSeeds > 0.30) {
        $findings[] = finding(
            $findingId,
            'MEDIUM',
            'star_pricing_issues',
            'Star price mean varies significantly across seeds (CV = ' . round($priceCvAcrossSeeds, 4) . ', threshold: >0.30).',
            [
                'price_cv_across_seeds' => round($priceCvAcrossSeeds, 4),
                'mean_price_by_seed' => $starPricing['mean_price_by_seed'] ?? [],
            ],
            [],
            [],
            'star price CV across seeds >0.30',
            0.30,
            round($priceCvAcrossSeeds, 4),
        );
    }

    if ($stuckShare > 0.50) {
        $findings[] = finding(
            $findingId,
            'MEDIUM',
            'star_pricing_issues',
            'Star price stuck at cap or floor for ' . round($stuckShare * 100, 1) . '% of ticks (threshold: >50%).',
            [
                'cap_share' => round($capShare, 4),
                'floor_share' => round($floorShare, 4),
                'stuck_share' => round($stuckShare, 4),
                'global_min_price' => $starPricing['global_min_price'] ?? null,
                'global_max_price' => $starPricing['global_max_price'] ?? null,
            ],
            [],
            [],
            'price stuck at cap/floor >50% of ticks',
            0.50,
            round($stuckShare, 4),
        );
    } elseif ($capShare > 0.30) {
        $findings[] = finding(
            $findingId,
            'LOW',
            'star_pricing_issues',
            'Star price stuck at cap for ' . round($capShare * 100, 1) . '% of ticks (warning threshold: >30%).',
            ['cap_share' => round($capShare, 4)],
            [],
            [],
            'price stuck at cap >30% of ticks',
            0.30,
            round($capShare, 4),
        );
    } elseif ($floorShare > 0.30) {
        $findings[] = finding(
            $findingId,
            'LOW',
            'star_pricing_issues',
            'Star price stuck at floor for ' . round($floorShare * 100, 1) . '% of ticks (warning threshold: >30%).',
            ['floor_share' => round($floorShare, 4)],
            [],
            [],
            'price stuck at floor >30% of ticks',
            0.30,
            round($floorShare, 4),
        );
    }
} else {
    $unsupported[] = [
        'rule' => 'star_pricing_issues',
        'reason' => 'star_pricing section not available in baseline analysis report.',
    ];
}

// ----------------------------------------------------------------
// Rule 11: Non-viable archetype — median final score <50% of overall
// ----------------------------------------------------------------
echo "Checking: Non-viable archetype...\n";
$aos = $report['archetype_outcome_spread'] ?? [];
$nonViable = $aos['non_viable_archetypes'] ?? [];

foreach (($aos['by_archetype'] ?? []) as $key => $arch) {
    $ratio = (float)($arch['ratio_to_overall'] ?? 1.0);
    if ($ratio < 0.50) {
        $findings[] = finding(
            $findingId,
            'HIGH',
            'non_viable_archetype',
            "Archetype '{$key}' ({$arch['label']}) has median score at " . round($ratio * 100, 1) . "% of overall median (threshold: <50%).",
            ['archetype' => $key, 'label' => $arch['label'], 'cross_seed_mean' => $arch['cross_seed_mean'], 'ratio_to_overall' => $ratio, 'overall_mean' => $aos['overall_mean_of_medians'] ?? 0],
            [$key],
            [],
            'any archetype with median final score <50% of overall median',
            0.50,
            round($ratio, 4),
        );
    }
}

// ----------------------------------------------------------------
// Rule 12: Dominant archetype — median final score >200% of overall
// ----------------------------------------------------------------
echo "Checking: Dominant archetype...\n";

foreach (($aos['by_archetype'] ?? []) as $key => $arch) {
    $ratio = (float)($arch['ratio_to_overall'] ?? 1.0);
    if ($ratio > 2.0) {
        $findings[] = finding(
            $findingId,
            'HIGH',
            'dominant_archetype',
            "Archetype '{$key}' ({$arch['label']}) has median score at " . round($ratio * 100, 1) . "% of overall median (threshold: >200%).",
            ['archetype' => $key, 'label' => $arch['label'], 'cross_seed_mean' => $arch['cross_seed_mean'], 'ratio_to_overall' => $ratio, 'overall_mean' => $aos['overall_mean_of_medians'] ?? 0],
            [$key],
            [],
            'any archetype with median final score >200% of overall median',
            2.0,
            round($ratio, 4),
        );
    }
}

// ----------------------------------------------------------------
// Rule 13: Bad player experience — mostly_idle or casual <5% lock-in
// ----------------------------------------------------------------
echo "Checking: Bad player experience...\n";
$byArchetype = $lie['by_archetype'] ?? [];

foreach (['mostly_idle', 'casual'] as $archKey) {
    if (!isset($byArchetype[$archKey])) continue;
    $lockInRate = (float)($byArchetype[$archKey]['lock_in_rate'] ?? 0);
    if ($lockInRate < 0.05) {
        $findings[] = finding(
            $findingId,
            'HIGH',
            'bad_player_experience',
            "Archetype '{$archKey}' has only " . round($lockInRate * 100, 1) . "% lock-in rate (threshold: <5%).",
            ['archetype' => $archKey, 'lock_in_rate' => round($lockInRate, 4), 'sample_size' => $byArchetype[$archKey]['sample_size'] ?? 0],
            [$archKey],
            [],
            'mostly_idle or casual archetype with <5% lock-in rate',
            0.05,
            round($lockInRate, 4),
        );
    }
}

// ----------------------------------------------------------------
// Rule 14: Boost ROI imbalance — boost_focused payoff <80% of regular
// ----------------------------------------------------------------
echo "Checking: Boost ROI imbalance...\n";
$bu = $report['boost_usage'] ?? [];
$boostRatio = (float)($bu['boost_focused_vs_regular_ratio'] ?? 0);

if ($boostRatio > 0 && $boostRatio < 0.80) {
    $findings[] = finding(
        $findingId,
        'MEDIUM',
        'boost_roi_imbalance',
        "Boost-focused archetype payoff is " . round($boostRatio * 100, 1) . "% of regular archetype (threshold: <80%).",
        ['boost_focused_vs_regular_ratio' => round($boostRatio, 4)],
        ['boost_focused'],
        [],
        'boost_focused payoff <80% of regular archetype payoff',
        0.80,
        round($boostRatio, 4),
    );
}

// ----------------------------------------------------------------
// Rule 15: Hoarding advantage — hoarder >150% of regular final score
// ----------------------------------------------------------------
echo "Checking: Hoarding advantage...\n";
$hs = $report['hoarding_vs_spending'] ?? [];
$hoarderRatio = (float)($hs['hoarder_vs_regular_ratio'] ?? 0);

if ($hoarderRatio > 1.50) {
    $findings[] = finding(
        $findingId,
        'HIGH',
        'hoarding_advantage',
        "Hoarder final score is " . round($hoarderRatio * 100, 1) . "% of regular (threshold: >150%).",
        ['hoarder_vs_regular_ratio' => round($hoarderRatio, 4)],
        ['hoarder'],
        [],
        'hoarder final score >150% of regular final score',
        1.50,
        round($hoarderRatio, 4),
    );
}

// ----------------------------------------------------------------
// Rule 16: Phase dead zones — any phase with <10% of total actions
// ----------------------------------------------------------------
echo "Checking: Phase dead zones...\n";

if ($grandTotal > 0) {
    foreach (($ppb['action_distribution'] ?? []) as $phase => $data) {
        $share = (float)($data['share'] ?? 0);
        if ($share < 0.10) {
            $findings[] = finding(
                $findingId,
                'MEDIUM',
                'phase_dead_zones',
                "{$phase} phase has only " . round($share * 100, 1) . "% of total actions (threshold: <10%).",
                ['phase' => $phase, 'phase_total' => $data['phase_total'] ?? 0, 'share' => round($share, 4), 'grand_total' => $grandTotal],
                [],
                [$phase],
                'any phase with <10% of total actions',
                0.10,
                round($share, 4),
            );
        }
    }
}

// ----------------------------------------------------------------
// Rule 17: Cross-seed instability — any key metric with CV >0.20
// ----------------------------------------------------------------
echo "Checking: Cross-seed instability...\n";
$css = $report['cross_seed_stability'] ?? [];
$metrics = $css['metrics'] ?? [];

foreach ($metrics as $metricName => $m) {
    $cv = (float)($m['cv'] ?? 0);
    if ($cv > 0.20) {
        $findings[] = finding(
            $findingId,
            'LOW',
            'cross_seed_instability',
            "Metric '{$metricName}' has CV of " . round($cv, 4) . " across seeds (threshold: >0.20).",
            ['metric' => $metricName, 'cv' => round($cv, 4), 'values' => $m['values'] ?? []],
            [],
            [],
            'any key metric with CV >0.20',
            0.20,
            round($cv, 4),
        );
    }
}

// ================================================================
// Build diagnosis report
// ================================================================

// Sort findings: HIGH → MEDIUM → LOW
$severityOrder = ['HIGH' => 0, 'MEDIUM' => 1, 'LOW' => 2];
usort($findings, function ($a, $b) use ($severityOrder) {
    $sa = $severityOrder[$a['severity']] ?? 9;
    $sb = $severityOrder[$b['severity']] ?? 9;
    if ($sa !== $sb) return $sa - $sb;
    return ((int)substr($a['id'], 1)) - ((int)substr($b['id'], 1));
});

$diagnosisReport = [
    'schema_version'       => 'tmc-diagnosis.v1',
    'generated_at'         => gmdate('c'),
    'source_report'        => $reportPath,
    'source_schema'        => $report['schema_version'],
    'sim_b_runs'           => $report['sim_b_runs_loaded'] ?? 0,
    'sim_c_runs'           => $report['sim_c_runs_loaded'] ?? 0,
    'total_findings'       => count($findings),
    'findings_by_severity' => [
        'HIGH'   => count(array_filter($findings, fn($f) => $f['severity'] === 'HIGH')),
        'MEDIUM' => count(array_filter($findings, fn($f) => $f['severity'] === 'MEDIUM')),
        'LOW'    => count(array_filter($findings, fn($f) => $f['severity'] === 'LOW')),
    ],
    'findings'             => $findings,
    'unsupported_rules'    => $unsupported,
];

// --- Write JSON report ---
$reportOutPath = $outputDir . DIRECTORY_SEPARATOR . 'diagnosis_report.json';
file_put_contents($reportOutPath, json_encode($diagnosisReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "\nDiagnosis report: {$reportOutPath}\n";

// --- Write Markdown summary ---
$mdPath = $outputDir . DIRECTORY_SEPARATOR . 'diagnosis_summary.md';
file_put_contents($mdPath, generateDiagnosisSummary($diagnosisReport));
echo "Diagnosis summary: {$mdPath}\n";

echo "\nDone. {$diagnosisReport['total_findings']} finding(s) detected.\n";

// ================================================================
// Markdown summary generator
// ================================================================

function generateDiagnosisSummary(array $diag): string {
    $lines = [];
    $lines[] = '# Economy Diagnosis Summary';
    $lines[] = '';
    $lines[] = 'Generated: ' . $diag['generated_at'];
    $lines[] = 'Source: ' . basename($diag['source_report']);
    $lines[] = "Sim B runs: {$diag['sim_b_runs']} | Sim C runs: {$diag['sim_c_runs']}";
    $lines[] = '';
    $lines[] = '## Overview';
    $lines[] = '';
    $lines[] = "Total findings: **{$diag['total_findings']}**";
    $lines[] = '';
    $lines[] = '| Severity | Count |';
    $lines[] = '|---|---|';
    foreach ($diag['findings_by_severity'] as $sev => $cnt) {
        $lines[] = "| {$sev} | {$cnt} |";
    }
    $lines[] = '';

    if (empty($diag['findings'])) {
        $lines[] = '> No economy balance issues detected at current thresholds.';
        $lines[] = '';
    } else {
        // Group by severity
        $grouped = ['HIGH' => [], 'MEDIUM' => [], 'LOW' => []];
        foreach ($diag['findings'] as $f) {
            $grouped[$f['severity']][] = $f;
        }

        foreach (['HIGH', 'MEDIUM', 'LOW'] as $sev) {
            if (empty($grouped[$sev])) continue;

            $lines[] = "## {$sev} Severity Findings";
            $lines[] = '';

            foreach ($grouped[$sev] as $f) {
                $lines[] = "### [{$f['id']}] {$f['category']}";
                $lines[] = '';
                $lines[] = $f['description'];
                $lines[] = '';

                // Metric evidence
                $lines[] = '**Evidence:**';
                foreach ($f['metric_evidence'] as $ek => $ev) {
                    if (is_array($ev)) {
                        $lines[] = "- `{$ek}`: " . json_encode($ev);
                    } else {
                        $lines[] = "- `{$ek}`: {$ev}";
                    }
                }
                $lines[] = '';

                if ($f['threshold'] !== null && $f['observed_value'] !== null) {
                    $lines[] = "**Threshold:** {$f['threshold']} | **Observed:** {$f['observed_value']}";
                    $lines[] = '';
                }

                if (!empty($f['affected_archetypes'])) {
                    $lines[] = '**Affected archetypes:** ' . implode(', ', $f['affected_archetypes']);
                    $lines[] = '';
                }

                if (!empty($f['affected_phases'])) {
                    $lines[] = '**Affected phases:** ' . implode(', ', $f['affected_phases']);
                    $lines[] = '';
                }

                if ($f['confidence'] !== 'HIGH') {
                    $lines[] = "**Confidence:** {$f['confidence']}";
                    $lines[] = '';
                }

                if (!empty($f['notes'])) {
                    $lines[] = "> {$f['notes']}";
                    $lines[] = '';
                }

                $lines[] = '---';
                $lines[] = '';
            }
        }
    }

    // Unsupported rules
    if (!empty($diag['unsupported_rules'])) {
        $lines[] = '## Unsupported / Low-Confidence Rules';
        $lines[] = '';
        $lines[] = 'The following detection rules could not be computed from available Phase A outputs:';
        $lines[] = '';
        foreach ($diag['unsupported_rules'] as $u) {
            $lines[] = "### {$u['rule']}";
            $lines[] = '';
            $lines[] = $u['reason'];
            if (isset($u['severity_if_supported'])) {
                $lines[] = "- Would be **{$u['severity_if_supported']}** severity if data were available.";
            }
            if (isset($u['threshold'])) {
                $lines[] = "- Threshold: {$u['threshold']}";
            }
            $lines[] = '';
        }
    }

    $lines[] = '---';
    $lines[] = '*This report is auto-generated by `scripts/diagnose_economy.php` and is deterministic for a given baseline analysis input.*';
    $lines[] = '';

    return implode("\n", $lines);
}

// --- Helpers (duplicated from analyze_baseline.php for standalone use) ---

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
