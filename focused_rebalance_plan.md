# Focused Rebalance Plan

## Objective

Run a qualification-grade rebalance campaign against the current official baseline on the reconciled truthful active economy surface and determine whether any candidate becomes comparator-approved and promotion-eligible without weakening standards.

## Baseline And Comparator

- Baseline season config: `simulation_output/current-db/export/current_season_economy_only.json`
- Comparator standard: official `qualification` profile
- Promotion standard: full `CandidatePromotionPipeline`, including strict preflight, targeted harnesses, official qualification comparator validation, and promotion eligibility marking

## Truthful Active Surface

Canonical live and searchable knobs after reconciliation:

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

Current baseline fact that narrows the practically active lane:

- `hoarding_sink_enabled=0`, so the hoarding sink subfamily is only baseline-searchable when a candidate explicitly enables the sink.

## Search Strategy

### Stage 1: Single-knob sweeps

Screen focused single-knob candidates through the coupling harnesses first:

- `starprice_max_upstep_fp=950`
- `starprice_max_downstep_fp=18000`
- `hoarding_min_factor_fp=70000`
- `hoarding_sink_enabled=1`

Advance only harness-cleared singles to full promotion/comparator validation.

### Stage 2: Pairwise exploration of winners

Only combine lanes that survived stage 1 harness screening:

- `starprice_max_upstep_fp=950` + `hoarding_min_factor_fp=70000`
- `hoarding_sink_enabled=1` + `hoarding_safe_hours=24`

Reject pairwise lanes that fail harnesses before qualification.

### Stage 3: Constrained bundles

Do not run 3+ knob bundles unless pairwise evidence shows a comparator-near lane. If pairwise evidence remains reject-grade, stop and report the structural blocker instead of broadening the search.

## Priority Failure Families

The campaign is explicitly scored against:

- `lock_in_down_but_expiry_dominance_up`
- `skip_rejoin_exploit_worsened`
- `long_run_concentration / dominance regressions`
- `affordability / star-pricing pressure`

## Success Criteria

A candidate is promotion-eligible only if it:

- passes schema validation
- passes effective-config preflight with canonical audit artifacts
- passes targeted harnesses
- passes full single-season validation
- passes multi-season exploit/regression validation
- is non-reject under the official qualification comparator with zero regression flags
- reaches `promotion_eligible=true`

## Stop Rule

If no candidate clears qualification after the focused single-knob and pairwise stages, stop the search and name the narrowest remaining structural blocker in the economy itself.
