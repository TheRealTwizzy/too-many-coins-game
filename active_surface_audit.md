# Active Surface Audit

## Scope

This audit reconciles four surfaces against the canonical runtime/simulation path:

- canonical config schema and candidate linting
- staged generator families and counterweights
- effective-config preflight activity classification
- the live simulation/runtime economy code that actually consumes season config

The canonical runtime evidence came from direct consumers in `includes/economy.php`, `includes/tick_engine.php`, `includes/actions.php`, and the Phase 1 simulation layer in `scripts/simulation/*`.

## Findings

The old search surface was larger than the true active surface.

- `hoarding_window_ticks`
- `target_spend_rate_per_tick`
- `starprice_reactivation_window_ticks`
- `starprice_demand_table`
- `market_affordability_bias_fp`
- `vault_config`

All six keys linted as candidate-valid before reconciliation. Five were also treated as tunable by staged generation or policy scenarios. None are part of the truthful canonical balance surface for the current Phase 1 runtime/simulation lane.

## Active Surface After Reconciliation

`active_referenced`

- `base_ubi_active_per_tick`
- `base_ubi_idle_factor_fp`
- `ubi_min_per_tick`
- `inflation_table`
- `hoarding_min_factor_fp`
- `hoarding_sink_enabled`
- `starprice_table`
- `star_price_cap`
- `starprice_active_only`
- `starprice_max_upstep_fp`
- `starprice_max_downstep_fp`

`active_but_conditionally_gated`

- `hoarding_safe_hours`
- `hoarding_safe_min_coins`
- `hoarding_tier1_excess_cap`
- `hoarding_tier2_excess_cap`
- `hoarding_tier1_rate_hourly_fp`
- `hoarding_tier2_rate_hourly_fp`
- `hoarding_tier3_rate_hourly_fp`
- `hoarding_sink_cap_ratio_fp`
- `hoarding_idle_multiplier_fp`
- `starprice_idle_weight_fp`

## Disputed Knobs

`generator_mismatch`

- `hoarding_window_ticks`: generator emitted it, but no canonical runtime/simulation path reads it.
- `target_spend_rate_per_tick`: generator emitted it and lint accepted it, but the only reader is `Economy::hoardingFactor()`, which is never called.

`runtime_wiring_missing`

- `starprice_reactivation_window_ticks`: schema/generator implied live use, but canonical star pricing and lock-in logic never consult it.
- `starprice_demand_table`: schema accepted it, but canonical star pricing never applies it.
- `market_affordability_bias_fp`: schema/generator implied live use, but canonical pricing never applies it.

`valid_but_intentionally_dormant`

- `vault_config`: helper logic exists, but Phase 1 explicitly excludes vault-market spending from the canonical search surface.

`deprecated_should_remove`

- `starprice_model_version`: already rejected by candidate lint as runtime-owned.

## Reconciliation Implemented

- Candidate lint now validates against the truthful candidate search surface instead of the full patchable schema.
- Staged generator families and counterweights were rewritten to use only live knobs.
- Built-in policy sweep scenarios were updated to stop emitting dormant knobs.
- Preflight candidate-scope metadata now treats the dormant keys as out of surface instead of candidate-active.
- Comparator attribution key lists were updated so likely-causal ranking no longer privileges dead knobs.
- Agentic decomposition now filters subsystem-owned parameters through the canonical candidate search surface.

## Verification

Focused test slice passed:

- `CanonicalEconomyConfigContractTest`
- `EconomicCandidateValidatorTest`
- `TuningCandidateGeneratorTest`
- `SimulationConfigPreflightTest`
- `SimulationRejectionAttributionTest`
- `SimulationResultComparatorSmokeTest`
- `SimulationPolicySweepSmokeTest`

The remaining PHPUnit output contained existing framework deprecation notices, not reconciliation failures.
