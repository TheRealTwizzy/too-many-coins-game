<?php
/**
 * Phase D3 — Verification Summary Aggregator
 *
 * Aggregates comparison outputs from multiple seeds into a single
 * verification_summary.json + verification_summary.md with per-package
 * dispositions and pass/fail thresholds.
 */

$options = [
    'comparison' => [],
    'diagnosis' => null,
    'tuning-candidates' => null,
    'output' => __DIR__ . '/../simulation_output/current-db/verification',
];

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--comparison=')) {
        $options['comparison'][] = substr($arg, 13);
    } elseif (str_starts_with($arg, '--diagnosis=')) {
        $options['diagnosis'] = substr($arg, 12);
    } elseif (str_starts_with($arg, '--tuning-candidates=')) {
        $options['tuning-candidates'] = substr($arg, 20);
    } elseif (str_starts_with($arg, '--output=')) {
        $options['output'] = substr($arg, 9);
    } elseif ($arg === '--help') {
        echo <<<'HELP'
Phase D3 — Verification Summary Aggregator

Usage:
  php scripts/summarize_verification.php --comparison=FILE [--comparison=FILE ...] [OPTIONS]

Required:
  --comparison=FILE          Comparison JSON from Sim E (repeatable, one per seed)

Options:
  --diagnosis=FILE           diagnosis_report.json for finding severity lookup
  --tuning-candidates=FILE   tuning_candidates.json for finding-to-package mapping
  --output=DIR               Output directory (default: simulation_output/current-db/verification)
  --help                     Show this help

Outputs:
  verification_summary.json   Machine-readable cross-seed aggregation
  verification_summary.md     Human-readable report with per-package disposition

Disposition rules (from approved plan):
  VERIFIED  — disposition = "candidate for production tuning" on >=2/3 seeds AND zero regression flags
  PARTIAL   — "mixed / revisit" on any seed OR 1 regression flag not affecting HIGH-severity finding
  REJECTED  — "reject" on any seed OR regression flag affecting a HIGH-severity finding
HELP;
        exit(0);
    }
}

if ($options['comparison'] === []) {
    fwrite(STDERR, "Error: at least one --comparison=FILE is required.\n");
    exit(1);
}

// ── Load comparison outputs ──

$comparisons = [];
foreach ($options['comparison'] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Comparison file not found: $path\n");
        exit(1);
    }
    $data = json_decode(file_get_contents($path), true);
    if (!is_array($data) || !isset($data['scenarios'])) {
        fwrite(STDERR, "Invalid comparison file: $path\n");
        exit(1);
    }
    $comparisons[] = $data;
}

$seedCount = count($comparisons);

// ── Load diagnosis for severity mapping ──

$findingSeverity = [];
if ($options['diagnosis'] !== null && is_file($options['diagnosis'])) {
    $diag = json_decode(file_get_contents($options['diagnosis']), true);
    foreach (($diag['findings'] ?? []) as $f) {
        $findingSeverity[(string)$f['id']] = (string)$f['severity'];
    }
}

// ── Load tuning candidates for finding-to-package mapping ──

$packageFindings = []; // package_name => [finding_id => true]
$packageChanges = [];  // package_name => [change array]
if ($options['tuning-candidates'] !== null && is_file($options['tuning-candidates'])) {
    $tc = json_decode(file_get_contents($options['tuning-candidates']), true);
    foreach (($tc['packages'] ?? []) as $pkg) {
        $name = (string)$pkg['package_name'];
        $packageFindings[$name] = [];
        $packageChanges[$name] = $pkg['changes'] ?? [];
        foreach (($pkg['changes'] ?? []) as $change) {
            $fid = (string)$change['finding_id'];
            // A change may target multiple findings (comma-separated)
            foreach (explode(',', $fid) as $f) {
                $packageFindings[$name][trim($f)] = true;
            }
        }
    }
}

// Map scenario names to package names.
// Supports tuning-<package>-vN patterns for all tuning generations.
$scenarioToPackage = [
    'tuning-conservative-v1' => 'conservative',
    'tuning-balanced-v1' => 'balanced',
    'tuning-aggressive-v1' => 'aggressive',
    'tuning-conservative-v2' => 'conservative',
    'tuning-balanced-v2' => 'balanced',
    'tuning-aggressive-v2' => 'aggressive',
];

function resolvePackageNameFromScenario(string $scenarioName, array $scenarioToPackage): string {
    if (isset($scenarioToPackage[$scenarioName])) {
        return $scenarioToPackage[$scenarioName];
    }
    if (preg_match('/^tuning-(conservative|balanced|aggressive)-v\d+$/', $scenarioName, $m) === 1) {
        return (string)$m[1];
    }
    return $scenarioName;
}

// ── Aggregate per-scenario across seeds ──

$scenarioAgg = []; // scenario_name => {seeds: [...per-seed data], ...}

foreach ($comparisons as $compIdx => $comp) {
    $seed = (string)($comp['seed'] ?? "seed-$compIdx");
    foreach ((array)($comp['scenarios'] ?? []) as $sc) {
        $scName = (string)$sc['scenario_name'];
        if (!isset($scenarioAgg[$scName])) {
            $scenarioAgg[$scName] = [
                'scenario_name' => $scName,
                'seeds' => [],
                'total_wins' => 0,
                'total_losses' => 0,
                'total_mixed' => 0,
                'all_regression_flags' => [],
                'dispositions_by_seed' => [],
            ];
        }
        $scenarioAgg[$scName]['seeds'][] = [
            'seed' => $seed,
            'wins' => (int)$sc['wins'],
            'losses' => (int)$sc['losses'],
            'mixed_tradeoffs' => (int)$sc['mixed_tradeoffs'],
            'disposition' => (string)$sc['recommended_disposition'],
            'regression_flags' => (array)($sc['regression_flags'] ?? []),
            'confidence_notes' => (string)($sc['confidence_notes'] ?? ''),
        ];
        $scenarioAgg[$scName]['total_wins'] += (int)$sc['wins'];
        $scenarioAgg[$scName]['total_losses'] += (int)$sc['losses'];
        $scenarioAgg[$scName]['total_mixed'] += (int)$sc['mixed_tradeoffs'];
        $scenarioAgg[$scName]['dispositions_by_seed'][$seed] = (string)$sc['recommended_disposition'];
        foreach ((array)($sc['regression_flags'] ?? []) as $flag) {
            $scenarioAgg[$scName]['all_regression_flags'][$flag] = true;
        }
    }
}

// ── Apply D4 pass/fail thresholds ──

// Map: disposition string from comparator => category
// "candidate for production tuning" => pass
// "reject" => reject
// "keep testing" / anything else => mixed
function classifyDisposition(string $d): string {
    if ($d === 'candidate for production tuning') return 'pass';
    if ($d === 'reject') return 'reject';
    return 'mixed';
}

// Check if any regression flag affects a HIGH-severity finding
function flagAffectsHigh(string $flag, array $packageFindingIds, array $findingSeverity): bool {
    // Hoarder-related flags affect B11 (HIGH)
    $hoarderFlags = [
        'long_run_concentration_worsened',
        'candidate_improves_B_but_worsens_C',
        'seasonal_fairness_improves_but_long_run_concentration_worsens',
    ];
    if (in_array($flag, $hoarderFlags, true)) {
        // These can relate to concentration / hoarding which maps to B11 (HIGH)
        foreach ($packageFindingIds as $fid => $_) {
            if (($findingSeverity[$fid] ?? '') === 'HIGH') {
                return true;
            }
        }
    }
    // dominant_archetype_shifted affects B8/B9 (HIGH non-viable archetypes)
    if ($flag === 'dominant_archetype_shifted' || $flag === 'reduced_one_dominant_but_created_new_dominant') {
        return true; // These directly affect archetype balance (HIGH findings B8, B9)
    }
    // Lock-in/engagement flags - check if package targets HIGH findings
    if ($flag === 'lock_in_down_but_expiry_dominance_up' || $flag === 'engagement_up_but_t6_supply_spike') {
        foreach ($packageFindingIds as $fid => $_) {
            if (($findingSeverity[$fid] ?? '') === 'HIGH') {
                return true;
            }
        }
    }
    return false;
}

$packageResults = [];

foreach ($scenarioAgg as $scName => $agg) {
    $pkgName = resolvePackageNameFromScenario($scName, $scenarioToPackage);
    $pkgFindingIds = $packageFindings[$pkgName] ?? [];
    $flags = array_keys($agg['all_regression_flags']);
    $flagCount = count($flags);

    // Count disposition categories across seeds
    $passCount = 0;
    $rejectCount = 0;
    $mixedCount = 0;
    foreach ($agg['dispositions_by_seed'] as $seed => $disp) {
        $cls = classifyDisposition($disp);
        if ($cls === 'pass') $passCount++;
        elseif ($cls === 'reject') $rejectCount++;
        else $mixedCount++;
    }

    // D4 threshold rules
    $overallDisposition = 'PARTIAL'; // default

    // REJECTED: reject on any seed OR regression flag affecting HIGH-severity finding
    $hasReject = ($rejectCount > 0);
    $hasHighRegressionFlag = false;
    foreach ($flags as $flag) {
        if (flagAffectsHigh($flag, $pkgFindingIds, $findingSeverity)) {
            $hasHighRegressionFlag = true;
            break;
        }
    }

    if ($hasReject || $hasHighRegressionFlag) {
        $overallDisposition = 'REJECTED';
    }
    // VERIFIED: "candidate for production tuning" on >=2/3 seeds AND zero regression flags
    elseif ($passCount >= ceil($seedCount * 2 / 3) && $flagCount === 0) {
        $overallDisposition = 'VERIFIED';
    }
    // PARTIAL: everything else (mixed/revisit on any seed OR 1 flag not affecting HIGH)
    // Already defaulted to PARTIAL

    // Per-finding pass/fail (check if targeted metric dimensions improved)
    $findingResults = [];
    foreach ($pkgFindingIds as $fid => $_) {
        // A finding is "addressed" if the scenario has positive wins in related dimensions
        // We use total_wins > total_losses as a proxy
        $findingResults[$fid] = [
            'finding_id' => $fid,
            'severity' => $findingSeverity[$fid] ?? 'UNKNOWN',
            'improved' => $agg['total_wins'] > $agg['total_losses'],
            'notes' => $agg['total_wins'] > $agg['total_losses']
                ? 'Net positive across seeds'
                : ($agg['total_wins'] === $agg['total_losses']
                    ? 'Neutral — no clear improvement'
                    : 'Net negative — did not improve targeted metric'),
        ];
    }

    $packageResults[$scName] = [
        'scenario_name' => $scName,
        'package_name' => $pkgName,
        'seed_count' => $seedCount,
        'total_wins' => $agg['total_wins'],
        'total_losses' => $agg['total_losses'],
        'total_mixed' => $agg['total_mixed'],
        'pass_seeds' => $passCount,
        'reject_seeds' => $rejectCount,
        'mixed_seeds' => $mixedCount,
        'regression_flags' => $flags,
        'regression_flag_count' => $flagCount,
        'has_high_severity_regression' => $hasHighRegressionFlag,
        'overall_disposition' => $overallDisposition,
        'per_seed' => $agg['seeds'],
        'finding_results' => array_values($findingResults),
    ];
}

// ── Cross-package comparison ──

$crossPackageNotes = [];
$pkgNames = array_keys($packageResults);
for ($i = 0; $i < count($pkgNames); $i++) {
    for ($j = $i + 1; $j < count($pkgNames); $j++) {
        $a = $packageResults[$pkgNames[$i]];
        $b = $packageResults[$pkgNames[$j]];
        // Check if one improves where another regresses
        if ($a['total_wins'] > $a['total_losses'] && $b['total_losses'] > $b['total_wins']) {
            $crossPackageNotes[] = sprintf(
                '%s shows net improvement (W:%d L:%d) while %s shows net regression (W:%d L:%d)',
                $a['package_name'], $a['total_wins'], $a['total_losses'],
                $b['package_name'], $b['total_wins'], $b['total_losses']
            );
        }
        if ($b['total_wins'] > $b['total_losses'] && $a['total_losses'] > $a['total_wins']) {
            $crossPackageNotes[] = sprintf(
                '%s shows net improvement (W:%d L:%d) while %s shows net regression (W:%d L:%d)',
                $b['package_name'], $b['total_wins'], $b['total_losses'],
                $a['package_name'], $a['total_wins'], $a['total_losses']
            );
        }
    }
}

// ── Stability notes ──

$stabilityNotes = [];
foreach ($packageResults as $scName => $pr) {
    $disps = array_values($pr['per_seed']);
    $dispSet = [];
    foreach ($disps as $s) {
        $dispSet[$s['disposition']] = true;
    }
    if (count($dispSet) > 1) {
        $stabilityNotes[] = sprintf(
            '%s has inconsistent dispositions across seeds: %s',
            $pr['package_name'],
            implode(', ', array_map(function($s) {
                return $s['seed'] . '=' . $s['disposition'];
            }, $disps))
        );
    }
}

// ── Determine recommendation ──

$bestPackage = null;
$bestScore = -1;
foreach ($packageResults as $pr) {
    if ($pr['overall_disposition'] === 'VERIFIED') {
        $score = 3 * $pr['total_wins'] - $pr['total_losses'];
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestPackage = $pr['package_name'];
        }
    }
}
if ($bestPackage === null) {
    // Fall back to PARTIAL with best score
    foreach ($packageResults as $pr) {
        if ($pr['overall_disposition'] === 'PARTIAL') {
            $score = 2 * $pr['total_wins'] - 2 * $pr['total_losses'] - $pr['regression_flag_count'];
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestPackage = $pr['package_name'];
            }
        }
    }
}

$recommendation = 'No package meets VERIFIED threshold.';
if ($bestPackage !== null) {
    $disp = $packageResults[array_key_first(
        array_filter($packageResults, fn($p) => $p['package_name'] === $bestPackage)
    )]['overall_disposition'];
    if ($disp === 'VERIFIED') {
        $recommendation = "Package '$bestPackage' is VERIFIED and recommended for Phase E promotion.";
    } else {
        $recommendation = "No package is fully VERIFIED. Package '$bestPackage' ($disp) is the strongest candidate but requires manual review before Phase E.";
    }
}

// ── Build summary payload ──

$summary = [
    'schema_version' => 'tmc-verification-summary.v1',
    'generated_at' => gmdate('c'),
    'seed_count' => $seedCount,
    'comparison_files' => $options['comparison'],
    'packages' => array_values($packageResults),
    'cross_package_notes' => $crossPackageNotes,
    'stability_notes' => $stabilityNotes,
    'recommendation' => $recommendation,
];

// ── Write outputs ──

$outputDir = rtrim($options['output'], DIRECTORY_SEPARATOR);
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0777, true);
}

$jsonPath = $outputDir . DIRECTORY_SEPARATOR . 'verification_summary.json';
file_put_contents($jsonPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// ── Generate Markdown ──

$md = "# Verification Summary\n\n";
$md .= "Generated: " . $summary['generated_at'] . "\n";
$md .= "Seeds: " . $seedCount . "\n\n";

$md .= "## Package Dispositions\n\n";
$md .= "| Package | Disposition | Pass Seeds | Reject Seeds | Mixed Seeds | Total W | Total L | Regression Flags |\n";
$md .= "|---------|-------------|------------|--------------|-------------|---------|---------|------------------|\n";
foreach ($packageResults as $pr) {
    $md .= sprintf(
        "| %s | **%s** | %d/%d | %d/%d | %d/%d | %d | %d | %d |\n",
        $pr['package_name'],
        $pr['overall_disposition'],
        $pr['pass_seeds'], $seedCount,
        $pr['reject_seeds'], $seedCount,
        $pr['mixed_seeds'], $seedCount,
        $pr['total_wins'],
        $pr['total_losses'],
        $pr['regression_flag_count']
    );
}

$md .= "\n---\n\n";

foreach ($packageResults as $pr) {
    $md .= "## Package: " . $pr['package_name'] . "\n\n";
    $md .= "**Overall Disposition: " . $pr['overall_disposition'] . "**\n\n";

    $md .= "### Per-Seed Results\n\n";
    $md .= "| Seed | Wins | Losses | Mixed | Disposition | Regression Flags |\n";
    $md .= "|------|------|--------|-------|-------------|------------------|\n";
    foreach ($pr['per_seed'] as $s) {
        $flagStr = empty($s['regression_flags']) ? '—' : implode(', ', $s['regression_flags']);
        $md .= sprintf(
            "| %s | %d | %d | %d | %s | %s |\n",
            $s['seed'], $s['wins'], $s['losses'], $s['mixed_tradeoffs'],
            $s['disposition'], $flagStr
        );
    }

    if (!empty($pr['finding_results'])) {
        $md .= "\n### Per-Finding Assessment\n\n";
        $md .= "| Finding | Severity | Improved | Notes |\n";
        $md .= "|---------|----------|----------|-------|\n";
        foreach ($pr['finding_results'] as $fr) {
            $md .= sprintf(
                "| %s | %s | %s | %s |\n",
                $fr['finding_id'],
                $fr['severity'],
                $fr['improved'] ? 'YES' : 'NO',
                $fr['notes']
            );
        }
    }

    if (!empty($pr['regression_flags'])) {
        $md .= "\n### Regression Flags\n\n";
        foreach ($pr['regression_flags'] as $flag) {
            $affectsHigh = flagAffectsHigh($flag, $packageFindings[$pr['package_name']] ?? [], $findingSeverity);
            $md .= "- `$flag`" . ($affectsHigh ? ' **(affects HIGH-severity finding)**' : '') . "\n";
        }
    }

    $md .= "\n---\n\n";
}

if (!empty($crossPackageNotes)) {
    $md .= "## Cross-Package Comparison\n\n";
    foreach ($crossPackageNotes as $note) {
        $md .= "- $note\n";
    }
    $md .= "\n";
}

if (!empty($stabilityNotes)) {
    $md .= "## Stability Notes\n\n";
    foreach ($stabilityNotes as $note) {
        $md .= "- $note\n";
    }
    $md .= "\n";
}

$md .= "## Recommendation\n\n";
$md .= $summary['recommendation'] . "\n";

$mdPath = $outputDir . DIRECTORY_SEPARATOR . 'verification_summary.md';
file_put_contents($mdPath, $md);

echo "Verification Summary" . PHP_EOL;
echo "Seeds: $seedCount" . PHP_EOL;
echo "JSON: $jsonPath" . PHP_EOL;
echo "Markdown: $mdPath" . PHP_EOL;
echo PHP_EOL;
foreach ($packageResults as $pr) {
    echo sprintf(
        "%s: %s (W:%d L:%d flags:%d pass:%d/%d reject:%d/%d)",
        $pr['package_name'],
        $pr['overall_disposition'],
        $pr['total_wins'],
        $pr['total_losses'],
        $pr['regression_flag_count'],
        $pr['pass_seeds'], $seedCount,
        $pr['reject_seeds'], $seedCount
    ) . PHP_EOL;
}
echo PHP_EOL;
echo "Recommendation: " . $summary['recommendation'] . PHP_EOL;
