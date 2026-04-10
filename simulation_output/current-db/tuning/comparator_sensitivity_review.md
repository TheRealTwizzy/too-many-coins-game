# Comparator Sensitivity Review — `skip_rejoin_exploit_worsened`

Generated as part of Phase C.5 regression remediation.

## Summary

The `skip_rejoin_exploit_worsened` flag triggered on **7 of 9 SimC comparisons** across all v1 packages, making it the single most frequent regression blocker. This review analyzes whether the flag's threshold is appropriately calibrated.

## Flag Definition

From `ResultComparator::detectRegressionFlags()`:

```
if skip_strategy_edge_delta > 0.0 → flag "skip_rejoin_exploit_worsened"
```

**Threshold: Zero tolerance.** Any positive value triggers rejection.

## Observed Values (v1 Phase D)

| Seed | Package | skip_strategy_edge_delta | Flagged? |
|---|---|---|---|
| tuning-verify-v1 | conservative | +7,452.86 | YES |
| tuning-verify-v1 | balanced | +1,036.42 | YES |
| tuning-verify-v1 | aggressive | +7,414.75 | YES |
| tuning-verify-v2 | conservative | +8,776.46 | YES |
| tuning-verify-v2 | balanced | +1,773.41 | YES |
| tuning-verify-v2 | aggressive | +1,525.68 | YES |
| tuning-verify-v3 | conservative | **-7,454.08** | NO |
| tuning-verify-v3 | balanced | **-9,618.71** | NO |
| tuning-verify-v3 | aggressive | +591.22 | YES |

## Key Observations

### 1. Extreme Seed Sensitivity

The metric swings from **-9,618** (large improvement) to **+8,776** (large regression) across seeds for the *same package* (conservative). This indicates the `skip_strategy_edge_delta` is highly seed-dependent and not a stable signal.

### 2. Magnitude Range is ~18,000 Units

The total observed range is approximately 18,000 units (-9,618 to +8,776). A zero-tolerance threshold at 0.0 means the flag triggers whenever the metric falls on the positive side of this wide distribution.

### 3. Conservative Package Produces Largest Values

Counterintuitively, the conservative package (smallest changes: hoarding +7% to +15%) produces the *largest* skip_strategy_edge values (+7,452, +8,776). This suggests the metric is measuring a nonlinear system effect rather than a proportional response to tuning magnitude.

### 4. Improvement Cases Exist

Seeds v3-conservative (-7,454) and v3-balanced (-9,618) show the *same tuning* can massively improve the skip metric. The flag only captures negative outcomes.

## Sensitivity Analysis

### Option A: Keep Zero Tolerance (Current)

- **Pro:** Maximum safety against skip/rejoin exploit regression
- **Con:** Rejects packages that may improve the metric on average across seeds. Extremely hard to pass any hoarding tuning since the metric is seed-sensitive.
- **Expected v2 impact:** May still trigger on 3-5/9 SimC runs even with dampened multipliers

### Option B: Absolute Threshold (Recommended)

Proposed: `skip_strategy_edge_delta > 2000.0` (approximately 10% of observed range)

- **Pro:** Filters genuinely large regressions while tolerating seed noise
- **Con:** Permits moderate skip exploit increases
- **Rationale:** Values below 2,000 represent small movements relative to the 18,000-unit observed range and are within seed-level noise

### Option C: Relative Threshold (Alternative)

Proposed: `skip_strategy_edge_delta / baseline_skip_strategy_edge > 0.10` (10% relative increase)

- **Pro:** Scales with baseline magnitude
- **Con:** Requires baseline skip_strategy_edge to be tracked (not currently stored)

### Option D: Cross-Seed Median (Deferred)

Average the `skip_strategy_edge_delta` across seeds before flagging.

- **Pro:** Most robust against seed noise
- **Con:** Requires architectural change to the comparator (currently per-seed)

## Recommendation

**Apply Option B (absolute threshold of 2,000) for Phase D v2 verification.** This filters the 5 genuinely large regressions (+7,414 to +8,776) while passing the moderate values (+591 to +1,773) that are within seed noise.

If v2 verification still shows widespread flagging, consider Option D as a follow-up comparator enhancement.

## Action Required

This review is **informational only.** No comparator changes are included in the v2 generator output. If the team decides to adjust the threshold, modify `ResultComparator::detectRegressionFlags()`:

```php
// Current (zero tolerance):
if ($avgDeltas['skip_strategy_edge_delta'] > 0.0) {

// Proposed Option B:
if ($avgDeltas['skip_strategy_edge_delta'] > 2000.0) {
```

## Impact on v2 Verification

Even without comparator changes, v2's dampened hoarding multipliers (7-25% vs v1's 15-60%) should produce smaller `skip_strategy_edge_delta` values. The v2 packages also add counterweights (affordability bias, reactivation window) that may independently reduce the skip/rejoin advantage by making lock-in more accessible.
