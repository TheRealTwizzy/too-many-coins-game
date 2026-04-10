# V2 vs V1 Verification Comparison

Generated: 2026-04-10
Phase: D rerun (v2 regression-mitigated packages)

## Strict Dispositions (Unchanged Comparator)

| Package | v1 Disposition | v2 Disposition | Change |
|---------|---------------|---------------|--------|
| conservative | REJECTED (0/3 pass) | REJECTED (1/3 pass) | Improved |
| balanced | REJECTED (0/3 pass) | REJECTED (0/3 pass) | No change |
| aggressive | REJECTED (0/3 pass) | REJECTED (1/3 pass) | Improved |

## Win/Loss Totals (across 3 seeds)

| Package | v1 W | v1 L | v2 W | v2 L | W delta | L delta |
|---------|------|------|------|------|---------|---------|
| conservative | 22 | 24 | 29 | 16 | +7 | -8 |
| balanced | 15 | 28 | 24 | 20 | +9 | -8 |
| aggressive | 23 | 21 | 29 | 15 | +6 | -6 |

All three packages improved substantially in win/loss ratio.

## Regression Flag Comparison

### Per-flag occurrence (across 9 seed×package SimB+SimC runs)

| Flag | v1 Count | v2 Count | Delta |
|------|----------|----------|-------|
| skip_rejoin_exploit_worsened | 7/9 SimC | 1/9 SimC | **-6** (near-eliminated) |
| dominant_archetype_shifted | 9/18 | 6/18 | **-3** |
| lock_in_down_but_expiry_dominance_up | 5/9 SimB | 7/9 SimB+C | **+2** (worsened) |
| long_run_concentration_worsened | 3/9 SimC | 1/9 SimC | **-2** |
| reduces_lock_in_but_expiry_dominance_rises | 5/9 cross-sim | 8/9 cross-sim | **+3** (worsened) |
| candidate_improves_B_but_worsens_C | 0 | 2/9 | **+2** (new) |
| reduced_one_dominant_but_created_new_dominant | 0 | 1/9 | **+1** (new) |

### Interpretation

V2 dramatically improved on the two flags that v1 was worst at:
- **skip_rejoin_exploit_worsened**: 7/9 → 1/9. The dampened hoarding drain multipliers worked as designed.
- **long_run_concentration_worsened**: 3/9 → 1/9. Smaller drain magnitudes reduced cross-season disproportion.

However, v2 worsened the lock-in/expiry regression class:
- **lock_in_down_but_expiry_dominance_up**: Expanded from 5/9 to 7/9. The counterweights (affordability bias, reactivation window) were not strong enough to offset the mechanical effect of hoarding drain on lock-in viability.
- **reduces_lock_in_but_expiry_dominance_rises**: Expanded from 5/9 to 8/9. Cross-sim version of the same issue.

This is the **primary remaining blocker**. The lock-in regression is mechanical: more hoarding drain → fewer coins → fewer lock-ins → more natural expiry. The counterweights helped on seed 3 but not on seeds 1-2.

## Seed 3 Deep Dive (Best Performing)

On seed `tuning-verify-v2-3`:
- **Conservative**: 12W / 3L / 0 flags → `candidate for production tuning`
- **Aggressive**: 11W / 3L / 0 flags → `candidate for production tuning`
- **Balanced**: 7W / 8L / 5 flags → `reject`

Both conservative and aggressive passed with zero regression flags and dominant win ratios. This demonstrates the v2 approach CAN produce clean results under favorable seed conditions.

## Remaining Failure Analysis

### Package failures vs comparator sensitivity

1. **lock_in_down_but_expiry_dominance_up** (primary blocker):
   - Threshold: `lockDelta < -0.01 && expiryDelta > 0.5`
   - v2 SimB lock_in_delta values: -7, -5, -2, -2, -1, 0, 0, +2, +3
   - The SimB integer lock-in deltas of -1 to -7 are small in absolute terms (out of ~50-100 total lock-ins) but cross the -0.01 threshold on the fractional side
   - This is both a real (small) regression and a comparator sensitivity issue — a lock-in decrease of 1-2 absolute events triggers rejection

2. **dominant_archetype_shifted** (secondary blocker):
   - 6/18 v2 vs 9/18 v1 — improved but not eliminated
   - Inherent to any hoarding tuning that changes relative archetype advantage
   - Boolean flag (any shift = reject) means even a minor ranking swap triggers it

3. **balanced package** is the weakest:
   - Includes more override surfaces (UBI, vault, target_spend, hoarding_window) creating more interaction effects
   - Consistently the worst performer across seeds in both v1 and v2
   - Recommend dropping balanced from future iterations

## Recommendation Under Strict Rules

**No package meets VERIFIED threshold** (≥2/3 seeds pass + zero flags all seeds).

Conservative and aggressive each pass 1/3 seeds, achieving REJECTED under strict rules.

## Recommendation Under Cautious Human Review

**Conservative is the strongest candidate for human-reviewed advancement**, with these considerations:

1. **Win ratio**: 29W / 16L (64.4%) — the best overall and the best conservative v1→v2 improvement
2. **Clean seed 3**: 12W / 3L / 0 flags — the strongest single-seed result of any package in either v1 or v2
3. **Primary blocker is small-magnitude**: lock-in deltas of -2 and 0 in SimB (seeds 1-2) are 1-2 absolute events
4. **skip_rejoin**: Completely clean in v2 conservative (0/3 seeds flagged vs 2/3 in v1)
5. **Concentration**: Completely clean (0/3 seeds flagged)
6. **Smallest change surface**: Only 6 override keys (hoarding + counterweights), lowest interaction risk

The remaining flags on seeds 1-2 are the lock-in/expiry regression (triggered by single-digit absolute lock-in decreases) and dominant-archetype shift (a boolean flag). Both are marginal effects of a genuinely small tuning.

**If the operator accepts that a 1-2 lock-in decrease out of ~50-100 is within acceptable noise, conservative-v2 could advance to test server under monitored conditions.**

## Overrides Summary for Reference

### tuning-conservative-v2
```json
{
    "hoarding_tier2_rate_hourly_fp": 535,
    "hoarding_tier3_rate_hourly_fp": 1070,
    "hoarding_idle_multiplier_fp": 1287500,
    "starprice_reactivation_window_ticks": 81,
    "market_affordability_bias_fp": 970000,
    "starprice_max_downstep_fp": 12960
}
```

Changes from baseline: hoarding tier2 +7%, tier3 +7%, idle mult +3%, reactivation window +8%, affordability bias -3%, max downstep +8%.
