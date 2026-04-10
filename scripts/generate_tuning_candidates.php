<?php
/**
 * Phase C — Candidate Tuning Package Generator
 *
 * Consumes the Phase B diagnosis report and produces ranked, simulation-testable
 * economy tuning packages (conservative / balanced / aggressive).
 *
 * Usage:
 *   php scripts/generate_tuning_candidates.php --diagnosis=<diagnosis_report.json> [OPTIONS]
 *
 * Options:
 *   --diagnosis=FILE       Path to diagnosis_report.json (required)
 *   --season-config=FILE   Path to exported season config JSON (for current_value lookup; optional)
 *   --output=DIR           Output directory (default: simulation_output/current-db/tuning/)
 *   --help                 Show this help
 *
 * Outputs:
 *   tuning_candidates.json   Ranked candidate packages + escalations + scenarios
 *   tuning_candidates.md     Human-readable summary
 */

require_once __DIR__ . '/simulation/SimulationSeason.php';

// ================================================================
// CLI argument parsing
// ================================================================

$options = [
    'diagnosis'      => null,
    'season-config'  => null,
    'output'         => __DIR__ . '/../simulation_output/current-db/tuning',
];

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--diagnosis=')) {
        $options['diagnosis'] = substr($arg, 12);
    } elseif (str_starts_with($arg, '--season-config=')) {
        $options['season-config'] = substr($arg, 16);
    } elseif (str_starts_with($arg, '--output=')) {
        $options['output'] = substr($arg, 9);
    } elseif ($arg === '--help') {
        echo <<<'HELP'
Phase C — Candidate Tuning Package Generator

Consumes the Phase B diagnosis report and produces ranked economy tuning packages.

Usage:
  php scripts/generate_tuning_candidates.php --diagnosis=<diagnosis_report.json> [OPTIONS]

Options:
  --diagnosis=FILE       Path to diagnosis_report.json (required)
  --season-config=FILE   Path to exported season config JSON (optional; for current_value)
  --output=DIR           Output directory (default: simulation_output/current-db/tuning/)
  --help                 Show this help

Outputs:
  tuning_candidates.json   Ranked candidate packages + escalations + scenarios
  tuning_candidates.md     Human-readable summary

HELP;
        exit(0);
    }
}

if ($options['diagnosis'] === null) {
    fwrite(STDERR, "ERROR: --diagnosis is required.\n");
    fwrite(STDERR, "Run Phase B first:\n");
    fwrite(STDERR, "  php scripts/diagnose_economy.php --report=simulation_output/current-db/baseline-batch/baseline_analysis_report.json\n");
    exit(1);
}

$diagnosisPath = realpath($options['diagnosis']);
if ($diagnosisPath === false || !is_file($diagnosisPath)) {
    fwrite(STDERR, "ERROR: Diagnosis report not found: {$options['diagnosis']}\n");
    exit(1);
}

$diagnosis = json_decode(file_get_contents($diagnosisPath), true);
if (!is_array($diagnosis) || !isset($diagnosis['schema_version'])) {
    fwrite(STDERR, "ERROR: Invalid diagnosis report format.\n");
    exit(1);
}

if ($diagnosis['schema_version'] !== 'tmc-diagnosis.v1') {
    fwrite(STDERR, "WARNING: Unexpected diagnosis schema version: {$diagnosis['schema_version']}\n");
}

$findings = $diagnosis['findings'] ?? [];
if (empty($findings)) {
    fwrite(STDERR, "WARNING: Diagnosis report contains zero findings. No tuning candidates to generate.\n");
}

// ================================================================
// Load baseline season values (for current_value fields)
// ================================================================

$seasonConfig = null;
if ($options['season-config'] !== null) {
    $scPath = realpath($options['season-config']);
    if ($scPath !== false && is_file($scPath)) {
        $seasonConfig = json_decode(file_get_contents($scPath), true);
        echo "Loaded season config from: {$scPath}\n";
    }
}

// Fallback: use SimulationSeason::build() defaults
$baselineSeason = SimulationSeason::build(1, 'baseline-defaults');

function getBaselineValue(string $key, ?array $seasonConfig, array $baselineSeason) {
    if ($seasonConfig !== null && array_key_exists($key, $seasonConfig)) {
        return $seasonConfig[$key];
    }
    if (array_key_exists($key, $baselineSeason)) {
        return $baselineSeason[$key];
    }
    return null;
}

$outputDir = $options['output'];
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0777, true);
}

echo "Diagnosis report: {$diagnosisPath}\n";
echo "Total findings: " . count($findings) . "\n";
echo "Output dir: {$outputDir}\n\n";

// ================================================================
// Tuning Change Registry
//
// Maps finding categories to season-column overrides with
// conservative / balanced / aggressive magnitude tiers.
// ================================================================

/**
 * Each entry defines how to tune for a given finding category.
 * Fields:
 *   targets[]       — array of tuning actions, each with:
 *     key           — season column name
 *     direction     — 'increase' | 'decrease'
 *     conservative  — multiplier or absolute shift for conservative package
 *     balanced      — multiplier or absolute shift for balanced package
 *     aggressive    — multiplier or absolute shift for aggressive package
 *     mode          — 'multiply' (apply factor to current) | 'shift' (add delta)
 *     type          — change type tag for output schema
 *     mechanic      — impacted game mechanic
 *   categories[]    — PolicyScenarioCatalog category keys
 *   tunable         — true if addressable via season columns
 *   escalation_info — if not tunable, describes the blocked surface
 */
$TUNING_REGISTRY = [
    'concentrated_wealth' => [
        'tunable' => true,
        'categories' => ['hoarding_preservation_pressure'],
        'targets' => [
            [
                'key' => 'hoarding_tier1_rate_hourly_fp',
                'direction' => 'increase',
                'conservative' => 1.10, 'balanced' => 1.25, 'aggressive' => 1.50,
                'mode' => 'multiply', 'type' => 'sink_source',
                'mechanic' => 'hoarding_sink',
                'player_effect' => 'Excess coin reserves drain slightly faster',
                'economy_effect' => 'Reduces top-10% share of total score',
            ],
            [
                'key' => 'hoarding_tier2_rate_hourly_fp',
                'direction' => 'increase',
                'conservative' => 1.10, 'balanced' => 1.25, 'aggressive' => 1.50,
                'mode' => 'multiply', 'type' => 'sink_source',
                'mechanic' => 'hoarding_sink',
                'player_effect' => 'Large coin hoards are taxed more aggressively',
                'economy_effect' => 'Reduces wealth concentration at top quartile',
            ],
            [
                'key' => 'hoarding_sink_cap_ratio_fp',
                'direction' => 'decrease',
                'conservative' => 0.95, 'balanced' => 0.85, 'aggressive' => 0.70,
                'mode' => 'multiply', 'type' => 'cap',
                'mechanic' => 'hoarding_sink',
                'player_effect' => 'Max hoarding drain per cycle increases',
                'economy_effect' => 'Stronger wealth redistribution at top end',
            ],
        ],
    ],

    'lock_in_timing_pathologies' => [
        'tunable' => true,
        'categories' => ['lock_in_expiry_incentives', 'star_conversion_pricing'],
        'targets' => [
            [
                'key' => 'starprice_reactivation_window_ticks',
                'direction' => 'increase',
                'conservative' => 1.10, 'balanced' => 1.25, 'aggressive' => 1.50,
                'mode' => 'multiply', 'type' => 'timer',
                'mechanic' => 'star_pricing',
                'player_effect' => 'More time to re-enter after idle before price penalty',
                'economy_effect' => 'Spreads lock-in timing across phases',
            ],
            [
                'key' => 'market_affordability_bias_fp',
                'direction' => 'decrease',
                'conservative' => 0.97, 'balanced' => 0.92, 'aggressive' => 0.85,
                'mode' => 'multiply', 'type' => 'pricing',
                'mechanic' => 'star_pricing',
                'player_effect' => 'Stars are slightly more affordable mid-season',
                'economy_effect' => 'Encourages earlier lock-in instead of end-rush',
            ],
        ],
    ],

    'excessive_expiry' => [
        'tunable' => true,
        'categories' => ['star_conversion_pricing', 'lock_in_expiry_incentives'],
        'targets' => [
            [
                'key' => 'market_affordability_bias_fp',
                'direction' => 'decrease',
                'conservative' => 0.95, 'balanced' => 0.88, 'aggressive' => 0.80,
                'mode' => 'multiply', 'type' => 'pricing',
                'mechanic' => 'star_pricing',
                'player_effect' => 'Stars become more affordable, making lock-in easier',
                'economy_effect' => 'Reduces natural expiry rate toward healthy range',
            ],
            [
                'key' => 'starprice_max_downstep_fp',
                'direction' => 'increase',
                'conservative' => 1.10, 'balanced' => 1.25, 'aggressive' => 1.50,
                'mode' => 'multiply', 'type' => 'pacing',
                'mechanic' => 'star_pricing',
                'player_effect' => 'Star price can drop faster when demand is low',
                'economy_effect' => 'More responsive pricing reduces price-stuck expiry',
            ],
        ],
    ],

    'insufficient_expiry' => [
        'tunable' => true,
        'categories' => ['star_conversion_pricing', 'lock_in_expiry_incentives'],
        'targets' => [
            [
                'key' => 'market_affordability_bias_fp',
                'direction' => 'increase',
                'conservative' => 1.05, 'balanced' => 1.12, 'aggressive' => 1.20,
                'mode' => 'multiply', 'type' => 'pricing',
                'mechanic' => 'star_pricing',
                'player_effect' => 'Stars become more expensive, adding conversion pressure',
                'economy_effect' => 'Increases expiry rate to create meaningful lock-in decisions',
            ],
            [
                'key' => 'starprice_max_upstep_fp',
                'direction' => 'increase',
                'conservative' => 1.10, 'balanced' => 1.25, 'aggressive' => 1.50,
                'mode' => 'multiply', 'type' => 'pacing',
                'mechanic' => 'star_pricing',
                'player_effect' => 'Star prices rise faster during high-demand phases',
                'economy_effect' => 'Creates urgency to lock in before price spikes',
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
                'conservative' => 1.15, 'balanced' => 1.35, 'aggressive' => 1.60,
                'mode' => 'multiply', 'type' => 'sink_source',
                'mechanic' => 'hoarding_sink',
                'player_effect' => 'Hoarding large reserves incurs higher drain',
                'economy_effect' => 'Reduces hoarder advantage over active spenders',
            ],
            [
                'key' => 'hoarding_tier3_rate_hourly_fp',
                'direction' => 'increase',
                'conservative' => 1.15, 'balanced' => 1.35, 'aggressive' => 1.60,
                'mode' => 'multiply', 'type' => 'sink_source',
                'mechanic' => 'hoarding_sink',
                'player_effect' => 'Extreme hoards face significantly higher drain',
                'economy_effect' => 'Caps hoarder long-run conversion advantage',
            ],
            [
                'key' => 'hoarding_idle_multiplier_fp',
                'direction' => 'increase',
                'conservative' => 1.05, 'balanced' => 1.15, 'aggressive' => 1.30,
                'mode' => 'multiply', 'type' => 'sink_source',
                'mechanic' => 'hoarding_sink',
                'player_effect' => 'Idle players with hoards lose coins faster',
                'economy_effect' => 'Discourages idle-hoard strategies',
            ],
        ],
    ],

    'boost_roi_imbalance' => [
        'tunable' => true,
        'categories' => ['boost_related', 'hoarding_preservation_pressure'],
        'targets' => [
            [
                'key' => 'target_spend_rate_per_tick',
                'direction' => 'decrease',
                'conservative' => 0.92, 'balanced' => 0.80, 'aggressive' => 0.70,
                'mode' => 'multiply', 'type' => 'pacing',
                'mechanic' => 'boost_economy',
                'player_effect' => 'Boost-heavy players retain more coin value',
                'economy_effect' => 'Improves boost ROI relative to non-boost paths',
            ],
            [
                'key' => 'hoarding_min_factor_fp',
                'direction' => 'increase',
                'conservative' => 1.10, 'balanced' => 1.30, 'aggressive' => 1.60,
                'mode' => 'multiply', 'type' => 'config_value',
                'mechanic' => 'hoarding_sink',
                'player_effect' => 'Higher floor on hoarding sink preserves more boost-earned coins',
                'economy_effect' => 'Reduces penalty on boost-focused play patterns',
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
                'conservative' => 0.90, 'balanced' => 0.75, 'aggressive' => 0.60,
                'mode' => 'multiply', 'type' => 'pacing',
                'mechanic' => 'star_pricing',
                'player_effect' => 'Star price volatility is reduced',
                'economy_effect' => 'Price stays off cap/floor more of the season',
            ],
            [
                'key' => 'starprice_max_downstep_fp',
                'direction' => 'decrease',
                'conservative' => 0.90, 'balanced' => 0.75, 'aggressive' => 0.60,
                'mode' => 'multiply', 'type' => 'pacing',
                'mechanic' => 'star_pricing',
                'player_effect' => 'Star price adjusts more smoothly',
                'economy_effect' => 'Reduces CV of star price across seeds',
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
                'conservative' => 1.10, 'balanced' => 1.25, 'aggressive' => 1.50,
                'mode' => 'multiply', 'type' => 'reward',
                'mechanic' => 'ubi',
                'player_effect' => 'Idle players earn marginally more coins passively',
                'economy_effect' => 'Improves casual/idle lock-in viability',
            ],
            [
                'key' => 'hoarding_safe_min_coins',
                'direction' => 'increase',
                'conservative' => 1.15, 'balanced' => 1.40, 'aggressive' => 1.75,
                'mode' => 'multiply', 'type' => 'cap',
                'mechanic' => 'hoarding_sink',
                'player_effect' => 'Casual players keep more coins before drain kicks in',
                'economy_effect' => 'Reduces punitive drain on low-engagement players',
            ],
        ],
    ],

    'sigil_scarcity' => [
        'tunable' => true,
        'categories' => ['sigil_drop_tier_combine'],
        'targets' => [
            [
                'key' => 'vault_config',
                'direction' => 'increase_supply',
                'conservative' => 'vault_supply_small', 'balanced' => 'vault_supply_medium', 'aggressive' => 'vault_supply_large',
                'mode' => 'vault_tuning', 'type' => 'config_value',
                'mechanic' => 'sigil_vault',
                'player_effect' => 'More sigils available in the vault per season',
                'economy_effect' => 'Increases T4+ acquisition rate',
            ],
        ],
        'escalation_partial' => [
            'reason' => 'Drop rate constants (SIGIL_DROP_CHANCE_FP, SIGIL_TIER_ODDS) are in config.php, not season columns',
            'subsystem' => 'sigil_drop_engine',
            'runtime_path' => 'includes/config.php SIGIL_DROP_CHANCE_FP / includes/economy.php adjustedSigilTierOdds()',
        ],
    ],

    'sigil_overabundance' => [
        'tunable' => true,
        'categories' => ['sigil_drop_tier_combine'],
        'targets' => [
            [
                'key' => 'vault_config',
                'direction' => 'decrease_supply',
                'conservative' => 'vault_supply_reduce_small', 'balanced' => 'vault_supply_reduce_medium', 'aggressive' => 'vault_supply_reduce_large',
                'mode' => 'vault_tuning', 'type' => 'config_value',
                'mechanic' => 'sigil_vault',
                'player_effect' => 'Fewer sigils available in the vault per season',
                'economy_effect' => 'Reduces total sigil inventory toward balanced range',
            ],
        ],
        'escalation_partial' => [
            'reason' => 'Drop rate constants (SIGIL_DROP_CHANCE_FP) are in config.php, not season columns',
            'subsystem' => 'sigil_drop_engine',
            'runtime_path' => 'includes/config.php SIGIL_DROP_CHANCE_FP',
        ],
    ],

    'weak_progression_pacing' => [
        'tunable' => true,
        'categories' => ['boost_related'],
        'targets' => [
            [
                'key' => 'base_ubi_active_per_tick',
                'direction' => 'increase',
                'conservative' => 1.08, 'balanced' => 1.17, 'aggressive' => 1.33,
                'mode' => 'multiply', 'type' => 'reward',
                'mechanic' => 'ubi',
                'player_effect' => 'Faster early-season coin accumulation',
                'economy_effect' => 'Raises median score at early phase boundary',
            ],
        ],
    ],

    'phase_dead_zones' => [
        'tunable' => true,
        'categories' => ['phase_timing', 'hoarding_preservation_pressure'],
        'targets' => [
            [
                'key' => 'hoarding_window_ticks',
                'direction' => 'decrease',
                'conservative' => 0.92, 'balanced' => 0.83, 'aggressive' => 0.75,
                'mode' => 'multiply', 'type' => 'timer',
                'mechanic' => 'hoarding_sink',
                'player_effect' => 'Hoarding evaluation window is shorter, creating more urgency',
                'economy_effect' => 'Drives action into quieter phases',
            ],
        ],
    ],

    // --- NOT directly tunable via season columns → escalate ---

    'underused_mechanics' => [
        'tunable' => false,
        'categories' => [],
        'targets' => [],
        'escalation_info' => [
            'reason' => 'Freeze/theft/combine action rates depend on config.php constants (SIGIL_FREEZE_SPEND_TIERS, SIGIL_THEFT_*, SIGIL_COMBINE_RECIPES) and BoostCatalog definitions, not season columns',
            'subsystem' => 'sigil_abilities',
            'runtime_path' => 'includes/config.php + includes/actions.php (performFreeze/performTheft/performCombine)',
        ],
    ],

    'overpowered_mechanics' => [
        'tunable' => false,
        'categories' => [],
        'targets' => [],
        'escalation_info' => [
            'reason' => 'Boost power/stack limits are hardcoded in BoostCatalog::DEFINITIONS and config.php, not season columns',
            'subsystem' => 'boost_catalog',
            'runtime_path' => 'includes/boost_catalog.php DEFINITIONS[] + includes/config.php BOOST_GUARANTEED_FLOOR_*',
        ],
    ],

    'non_viable_archetype' => [
        'tunable' => false,
        'categories' => [],
        'targets' => [],
        'escalation_info' => [
            'reason' => 'Non-viable archetype root cause depends on which archetype and which mechanic is underperforming; may require multi-surface tuning',
            'subsystem' => 'economy_multi_surface',
            'runtime_path' => 'Depends on finding details — may involve UBI, boost, star pricing, or hoarding surfaces',
        ],
    ],

    'dominant_archetype' => [
        'tunable' => false,
        'categories' => [],
        'targets' => [],
        'escalation_info' => [
            'reason' => 'Dominant archetype root cause depends on which archetype and which exploit path; may require multi-surface tuning',
            'subsystem' => 'economy_multi_surface',
            'runtime_path' => 'Depends on finding details — may involve hoarding, boost, star pricing, or lock-in surfaces',
        ],
    ],

    'cross_seed_instability' => [
        'tunable' => false,
        'categories' => [],
        'targets' => [],
        'escalation_info' => [
            'reason' => 'Cross-seed instability is informational — indicates variance in simulation, not a specific tunable surface',
            'subsystem' => 'simulation_variance',
            'runtime_path' => 'N/A — informational finding, no runtime code change needed',
        ],
    ],
];

// ================================================================
// Vault config tuning helpers
// ================================================================

function tuneVaultConfig($currentVaultJson, string $mode): string {
    $vault = is_string($currentVaultJson) ? json_decode($currentVaultJson, true) : $currentVaultJson;
    if (!is_array($vault)) {
        return is_string($currentVaultJson) ? $currentVaultJson : json_encode($currentVaultJson);
    }

    $multipliers = [
        'vault_supply_small'          => ['supply' => 1.15, 'cost' => 0.95],
        'vault_supply_medium'         => ['supply' => 1.30, 'cost' => 0.90],
        'vault_supply_large'          => ['supply' => 1.50, 'cost' => 0.85],
        'vault_supply_reduce_small'   => ['supply' => 0.90, 'cost' => 1.10],
        'vault_supply_reduce_medium'  => ['supply' => 0.80, 'cost' => 1.20],
        'vault_supply_reduce_large'   => ['supply' => 0.70, 'cost' => 1.35],
    ];

    if (!isset($multipliers[$mode])) {
        return is_string($currentVaultJson) ? $currentVaultJson : json_encode($currentVaultJson);
    }

    $m = $multipliers[$mode];
    foreach ($vault as &$tier) {
        if (isset($tier['supply'])) {
            $tier['supply'] = max(1, (int)round($tier['supply'] * $m['supply']));
        }
        if (isset($tier['cost_table']) && is_array($tier['cost_table'])) {
            foreach ($tier['cost_table'] as &$entry) {
                if (isset($entry['cost'])) {
                    $entry['cost'] = max(1, (int)round($entry['cost'] * $m['cost']));
                }
            }
            unset($entry);
        }
    }
    unset($tier);

    return json_encode($vault, JSON_UNESCAPED_SLASHES);
}

// ================================================================
// Package severity filters
// ================================================================

$PACKAGE_DEFS = [
    'conservative' => [
        'severity_filter' => ['HIGH'],
        'tier' => 'conservative',
        'max_risk' => 'LOW',
        'description' => 'Targets only HIGH-severity findings with minimal value changes and LOW risk.',
    ],
    'balanced' => [
        'severity_filter' => ['HIGH', 'MEDIUM'],
        'tier' => 'balanced',
        'max_risk' => 'MEDIUM',
        'description' => 'Targets HIGH and MEDIUM-severity findings with moderate value changes.',
    ],
    'aggressive' => [
        'severity_filter' => ['HIGH', 'MEDIUM', 'LOW'],
        'tier' => 'aggressive',
        'max_risk' => 'HIGH',
        'description' => 'Targets all findings with larger changes. May include MEDIUM-HIGH risk.',
    ],
];

// ================================================================
// Build packages
// ================================================================

$packages = [];
$escalations = [];
$findingsProcessed = 0;
$findingsTunable = 0;
$findingsEscalated = 0;
$findingsSkipped = 0;

// Index findings by category for dedup
$findingsByCategory = [];
foreach ($findings as $finding) {
    $cat = $finding['category'] ?? 'unknown';
    $findingsByCategory[$cat][] = $finding;
}

// First pass: identify escalations
foreach ($findingsByCategory as $cat => $catFindings) {
    $registry = $TUNING_REGISTRY[$cat] ?? null;
    if ($registry === null) {
        // Unknown category — escalate
        foreach ($catFindings as $f) {
            $escalations[] = [
                'finding_id'           => $f['id'],
                'category'             => $cat,
                'severity'             => $f['severity'],
                'requires_logic_change' => true,
                'affected_subsystem'   => 'unknown',
                'runtime_path'         => 'Unknown category — not in tuning registry',
                'description'          => $f['description'],
            ];
            $findingsEscalated++;
        }
        continue;
    }

    if (!$registry['tunable']) {
        foreach ($catFindings as $f) {
            $escalations[] = [
                'finding_id'           => $f['id'],
                'category'             => $cat,
                'severity'             => $f['severity'],
                'requires_logic_change' => true,
                'affected_subsystem'   => $registry['escalation_info']['subsystem'] ?? 'unknown',
                'runtime_path'         => $registry['escalation_info']['runtime_path'] ?? '',
                'description'          => $f['description'],
            ];
            $findingsEscalated++;
        }
        continue;
    }

    // Partial escalation: tunable but also has non-config aspects
    if (isset($registry['escalation_partial'])) {
        foreach ($catFindings as $f) {
            $escalations[] = [
                'finding_id'           => $f['id'],
                'category'             => $cat,
                'severity'             => $f['severity'],
                'requires_logic_change' => true,
                'affected_subsystem'   => $registry['escalation_partial']['subsystem'] ?? 'unknown',
                'runtime_path'         => $registry['escalation_partial']['runtime_path'] ?? '',
                'description'          => 'PARTIAL: Season-column tuning applied, but full fix requires: ' . ($registry['escalation_partial']['reason'] ?? ''),
                'partial'              => true,
            ];
        }
    }
}

// Second pass: build changes for each package tier
$changeId = 0;

foreach ($PACKAGE_DEFS as $pkgName => $pkgDef) {
    $changes = [];

    foreach ($findingsByCategory as $cat => $catFindings) {
        $registry = $TUNING_REGISTRY[$cat] ?? null;
        if ($registry === null || !$registry['tunable'] || empty($registry['targets'])) {
            continue;
        }

        // Filter findings by severity for this package
        $qualifyingFindings = array_filter($catFindings, function ($f) use ($pkgDef) {
            return in_array($f['severity'], $pkgDef['severity_filter'], true);
        });

        if (empty($qualifyingFindings)) {
            continue;
        }

        // Use first qualifying finding as primary reference; link all finding IDs
        $primaryFinding = reset($qualifyingFindings);
        $linkedFindingIds = array_map(fn($f) => $f['id'], $qualifyingFindings);
        $findingIdStr = count($linkedFindingIds) === 1 ? $linkedFindingIds[0] : implode(',', $linkedFindingIds);

        foreach ($registry['targets'] as $target) {
            $changeId++;
            $key = $target['key'];
            $tier = $pkgDef['tier'];
            $currentValue = getBaselineValue($key, $seasonConfig, $baselineSeason);

            // Compute proposed value
            $proposedValue = $currentValue;
            if ($target['mode'] === 'multiply' && is_numeric($currentValue)) {
                $factor = $target[$tier] ?? 1.0;
                $proposedValue = (int)round((float)$currentValue * (float)$factor);
            } elseif ($target['mode'] === 'vault_tuning') {
                $vaultMode = $target[$tier] ?? null;
                if ($vaultMode !== null && $currentValue !== null) {
                    $proposedValue = tuneVaultConfig($currentValue, $vaultMode);
                }
            }

            // Determine risk level based on magnitude of change
            $riskLevel = 'LOW';
            if ($target['mode'] === 'multiply' && is_numeric($currentValue) && $currentValue != 0) {
                $changeRatio = abs(($proposedValue - $currentValue) / $currentValue);
                if ($changeRatio > 0.30) {
                    $riskLevel = 'HIGH';
                } elseif ($changeRatio > 0.15) {
                    $riskLevel = 'MEDIUM';
                }
            } elseif ($target['mode'] === 'vault_tuning') {
                $riskLevel = ($tier === 'aggressive') ? 'MEDIUM' : 'LOW';
            }

            // Cap risk at package max
            $riskOrder = ['LOW' => 0, 'MEDIUM' => 1, 'HIGH' => 2];
            $maxRiskVal = $riskOrder[$pkgDef['max_risk']] ?? 2;
            if (($riskOrder[$riskLevel] ?? 0) > $maxRiskVal) {
                $riskLevel = $pkgDef['max_risk'];
                // Also clamp the proposed value toward current if risk is too high
                if ($target['mode'] === 'multiply' && is_numeric($currentValue)) {
                    $maxFactor = $pkgDef['max_risk'] === 'LOW' ? 0.10 : 0.25;
                    $direction = ($proposedValue > $currentValue) ? 1 : -1;
                    $clampedDelta = (int)round($currentValue * $maxFactor) * $direction;
                    $proposedValue = (int)$currentValue + $clampedDelta;
                }
            }

            // Build scenario test description
            $metricDirection = ($target['direction'] === 'increase' || $target['direction'] === 'increase_supply') ? 'should increase' : 'should decrease';
            $scenarioName = "tuning-{$pkgName}-v1";

            $changes[] = [
                'change_id'              => "C{$changeId}",
                'finding_id'             => $findingIdStr,
                'type'                   => $target['type'],
                'target'                 => $key,
                'current_value'          => $currentValue,
                'proposed_value'         => $proposedValue,
                'reason'                 => $primaryFinding['description'],
                'impacted_mechanic'      => $target['mechanic'],
                'expected_player_effect' => $target['player_effect'],
                'expected_economy_effect' => $target['economy_effect'],
                'risk_level'             => $riskLevel,
                'simulation_test'        => "{$scenarioName}: {$target['mechanic']} metric {$metricDirection}",
                'source_file'            => 'schema.sql (seasons table column)',
                'source_surface'         => 'season_column',
                'confidence'             => $primaryFinding['confidence'] ?? 'HIGH',
                'notes'                  => $primaryFinding['notes'] ?? '',
            ];
        }
    }

    if (!empty($changes)) {
        $packages[] = [
            'package_name'    => $pkgName,
            'description'     => $pkgDef['description'],
            'severity_filter' => $pkgDef['severity_filter'],
            'max_risk'        => $pkgDef['max_risk'],
            'changes'         => $changes,
        ];
    }
}

// Count tunable/processed
foreach ($findingsByCategory as $cat => $catFindings) {
    $registry = $TUNING_REGISTRY[$cat] ?? null;
    $findingsProcessed += count($catFindings);
    if ($registry !== null && $registry['tunable']) {
        $findingsTunable += count($catFindings);
    }
}
$findingsSkipped = $findingsProcessed - $findingsTunable - $findingsEscalated;

// ================================================================
// Build scenario definitions for Phase D
// ================================================================

$scenarios = [];
foreach ($packages as $pkg) {
    $overrides = [];
    $categorySet = [];

    foreach ($pkg['changes'] as $change) {
        $key = $change['target'];
        // Skip vault_config for scenario overrides if it's a JSON string
        // (PolicyScenarioCatalog expects scalar overrides for most keys)
        if ($key === 'vault_config') {
            $categorySet['sigil_drop_tier_combine'] = true;
            $overrides[$key] = $change['proposed_value'];
            continue;
        }
        $overrides[$key] = $change['proposed_value'];

        // Determine category from registry
        $findingCat = null;
        foreach ($findingsByCategory as $cat => $_) {
            $reg = $TUNING_REGISTRY[$cat] ?? null;
            if ($reg && $reg['tunable']) {
                foreach ($reg['targets'] as $t) {
                    if ($t['key'] === $key) {
                        foreach ($reg['categories'] as $c) {
                            $categorySet[$c] = true;
                        }
                        break 2;
                    }
                }
            }
        }
    }

    if (!empty($overrides)) {
        $scenarios[] = [
            'name'        => "tuning-{$pkg['package_name']}-v1",
            'description' => "Phase C tuning scenario ({$pkg['package_name']}): " . $pkg['description'],
            'categories'  => array_keys($categorySet),
            'overrides'   => $overrides,
        ];
    }
}

// ================================================================
// Assemble output
// ================================================================

$output = [
    'schema_version'       => 'tmc-tuning-candidates.v1',
    'generated_at'         => gmdate('c'),
    'diagnosis_report_path' => $diagnosisPath,
    'packages'             => $packages,
    'escalations'          => $escalations,
    'scenarios'            => $scenarios,
    'metadata'             => [
        'findings_processed' => $findingsProcessed,
        'findings_tunable'   => $findingsTunable,
        'findings_escalated' => $findingsEscalated,
        'findings_skipped'   => $findingsSkipped,
        'packages_generated' => count($packages),
        'scenarios_generated' => count($scenarios),
    ],
];

$jsonPath = $outputDir . DIRECTORY_SEPARATOR . 'tuning_candidates.json';
file_put_contents($jsonPath, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "=== Phase C Complete ===\n";
echo "Packages generated: " . count($packages) . "\n";
echo "Escalations: " . count($escalations) . "\n";
echo "Scenarios: " . count($scenarios) . "\n";
echo "JSON: {$jsonPath}\n";

// ================================================================
// Generate human-readable summary
// ================================================================

$mdPath = $outputDir . DIRECTORY_SEPARATOR . 'tuning_candidates.md';
$md = "# Economy Tuning Candidates\n\n";
$md .= "Generated: " . gmdate('Y-m-d H:i:s') . " UTC\n";
$md .= "Diagnosis source: `{$diagnosisPath}`\n\n";
$md .= "## Summary\n\n";
$md .= "| Metric | Value |\n|---|---|\n";
$md .= "| Findings processed | {$findingsProcessed} |\n";
$md .= "| Findings tunable | {$findingsTunable} |\n";
$md .= "| Findings escalated | {$findingsEscalated} |\n";
$md .= "| Findings skipped | {$findingsSkipped} |\n";
$md .= "| Packages generated | " . count($packages) . " |\n";
$md .= "| Scenarios generated | " . count($scenarios) . " |\n\n";

foreach ($packages as $pkg) {
    $md .= "---\n\n## Package: {$pkg['package_name']}\n\n";
    $md .= "_{$pkg['description']}_\n\n";
    $md .= "Severity filter: " . implode(', ', $pkg['severity_filter']) . "  \n";
    $md .= "Max risk: {$pkg['max_risk']}  \n";
    $md .= "Changes: " . count($pkg['changes']) . "\n\n";

    $md .= "| # | Target | Current | Proposed | Risk | Mechanic | Finding |\n";
    $md .= "|---|--------|---------|----------|------|----------|---------|\n";
    foreach ($pkg['changes'] as $change) {
        $cur = is_string($change['current_value']) && strlen($change['current_value']) > 30
            ? substr($change['current_value'], 0, 27) . '...'
            : $change['current_value'];
        $prop = is_string($change['proposed_value']) && strlen($change['proposed_value']) > 30
            ? substr($change['proposed_value'], 0, 27) . '...'
            : $change['proposed_value'];
        $md .= "| {$change['change_id']} | `{$change['target']}` | {$cur} | {$prop} | {$change['risk_level']} | {$change['impacted_mechanic']} | {$change['finding_id']} |\n";
    }
    $md .= "\n";
}

if (!empty($escalations)) {
    $md .= "---\n\n## Escalations (requires logic change)\n\n";
    $md .= "| Finding | Category | Severity | Subsystem | Reason |\n";
    $md .= "|---------|----------|----------|-----------|--------|\n";
    foreach ($escalations as $esc) {
        $partial = !empty($esc['partial']) ? ' (PARTIAL)' : '';
        $reason = strlen($esc['description']) > 80 ? substr($esc['description'], 0, 77) . '...' : $esc['description'];
        $md .= "| {$esc['finding_id']} | {$esc['category']}{$partial} | {$esc['severity']} | {$esc['affected_subsystem']} | {$reason} |\n";
    }
    $md .= "\n";
}

if (!empty($scenarios)) {
    $md .= "---\n\n## Registered Scenarios (for Phase D verification)\n\n";
    foreach ($scenarios as $scn) {
        $md .= "### `{$scn['name']}`\n\n";
        $md .= "_{$scn['description']}_\n\n";
        $md .= "Categories: " . implode(', ', $scn['categories']) . "\n\n";
        $md .= "Overrides:\n\n";
        $md .= "```json\n" . json_encode($scn['overrides'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n```\n\n";
    }
}

$md .= "---\n\n## Next Steps\n\n";
$md .= "1. Review packages and escalations\n";
$md .= "2. Run Phase D verification sweep:\n";
$md .= "   ```\n";
$md .= "   php scripts/simulate_policy_sweep.php \\\n";
$md .= "     --seed=tuning-verify-v1 \\\n";
$md .= "     --scenarios=tuning-conservative-v1,tuning-balanced-v1,tuning-aggressive-v1 \\\n";
$md .= "     --include-baseline=1 --simulators=B,C --players-per-archetype=10 --seasons=12\n";
$md .= "   ```\n";
$md .= "3. Compare results with `compare_simulation_results.php`\n";

file_put_contents($mdPath, $md);
echo "Markdown: {$mdPath}\n";

exit(0);
