# Active Surface Reconciliation Report

## A. Canonical Active Tuning Surface After Reconciliation

The truthful candidate/search surface is now:

- `base_ubi_active_per_tick`
- `base_ubi_idle_factor_fp`
- `ubi_min_per_tick`
- `inflation_table`
- `hoarding_min_factor_fp`
- `hoarding_sink_enabled`
- `hoarding_safe_hours`
- `hoarding_safe_min_coins`
- `hoarding_tier1_excess_cap`
- `hoarding_tier2_excess_cap`
- `hoarding_tier1_rate_hourly_fp`
- `hoarding_tier2_rate_hourly_fp`
- `hoarding_tier3_rate_hourly_fp`
- `hoarding_sink_cap_ratio_fp`
- `hoarding_idle_multiplier_fp`
- `starprice_table`
- `star_price_cap`
- `starprice_idle_weight_fp`
- `starprice_active_only`
- `starprice_max_upstep_fp`
- `starprice_max_downstep_fp`

## B. Disputed Knobs And Root Causes

- `hoarding_window_ticks`: generator emitted it, schema/lint allowed it, but canonical runtime never reads it.
- `target_spend_rate_per_tick`: generator emitted it, schema/lint allowed it, but the only reader is `Economy::hoardingFactor()` and that helper is unused.
- `starprice_reactivation_window_ticks`: schema/generator implied a live path, but canonical pricing/lock-in logic never reads it.
- `starprice_demand_table`: schema/lint allowed it, but canonical pricing never reads it.
- `market_affordability_bias_fp`: schema/generator implied a live path, but canonical pricing never reads it.
- `vault_config`: valid stored config, but Phase 1 explicitly excludes vault-market spending from the canonical simulation/search lane.
- `starprice_model_version`: already deprecated and rejected by candidate lint.

## C. Exact Fixes Made

- Filtered candidate lint and staged generation through the canonical candidate search surface in `scripts/simulation/CanonicalEconomyConfigContract.php`.
- Updated category allowlists in `scripts/simulation/EconomicCandidateValidator.php` to remove dormant knobs and map lock-in/expiry categories to live star-pricing knobs.
- Reworked staged generator multipliers, counterweights, and registry targets in `scripts/optimization/TuningCandidateGenerator.php` so they only emit live knobs.
- Marked dormant keys out of candidate scope in `scripts/simulation/SimulationConfigPreflight.php`.
- Rewrote built-in sweep scenarios in `scripts/simulation/PolicyScenarioCatalog.php` to use live knobs.
- Updated causal key mappings in `scripts/simulation/ResultComparator.php` to stop ranking dead knobs as likely causes.
- Filtered agentic subsystem-owned parameters through the canonical candidate surface in `scripts/optimization/AgenticOptimization.php`.

## D. Knobs Removed From Search

- `hoarding_window_ticks`
- `target_spend_rate_per_tick`
- `starprice_reactivation_window_ticks`
- `starprice_demand_table`
- `market_affordability_bias_fp`
- `vault_config`

## E. Knobs Newly Wired Into Runtime

None.

There was not enough repo evidence to safely invent semantics for the dormant star-affordability/reactivation knobs without weakening canonical truthfulness. The reconciliation therefore chose the smaller truthful surface instead of speculative runtime wiring.

## F. Tests Added

- `EconomicCandidateValidatorTest::testDormantSearchSurfaceKeysAreRejected`
- `SimulationConfigPreflightTest::testDormantSearchSurfaceKeyFailsPreflight`
- updated `TuningCandidateGeneratorTest` coverage to prove dormant knobs are no longer emitted and that the replacement phase-dead-zone lane uses `hoarding_safe_hours`
- updated rejection-attribution/comparator smoke tests to use live knobs in their causal metadata

## G. Recommended Next Balance-Search Lane

The next qualification lane should focus on live lock-in / expiry / star-pricing pressure controls:

1. `starprice_idle_weight_fp` down slightly to reduce idle-supply price pressure.
2. `starprice_max_downstep_fp` up slightly to let prices relax faster after demand spikes.
3. `starprice_max_upstep_fp` down slightly if late-season spikes still dominate.
4. Pair the above with gentle hoarding-sink timing or floor changes:
   `hoarding_safe_hours`, `hoarding_min_factor_fp`, and only then the sink rate/cap knobs.
5. Use `base_ubi_active_per_tick` as the boost/lock-in support counterweight instead of dead spend-target knobs.

That keeps the next search lane on knobs the runtime actually honors while still addressing the original lock-in / expiry / star-pricing pressure cluster.

## H. Does This Close The `search_space_issue` Blocker?

Yes.

The blocker was that staged generation and lint advertised a broader search surface than canonical preflight/runtime could truthfully execute. After this reconciliation, generator, lint, preflight, comparator attribution, and the active runtime/search lane all agree on the same smaller surface.
