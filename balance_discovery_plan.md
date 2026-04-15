# Balance Discovery Plan

## Objective

Run a qualification-grade balance discovery campaign against the refreshed official baseline and determine whether the current active search space can produce at least one comparator-approved, promotion-eligible candidate without weakening standards or bypassing qualification.

## Baseline and source-of-truth gates

- Baseline artifact: `simulation_output/current-db/export/current_season_economy_only.json`
- Comparator profile: official `qualification` profile (`B,C`, `players_per_archetype=2`, `season_count=4`)
- Promotion eligibility standard: candidate must survive strict validation, effective-config preflight, targeted harnesses, official qualification comparator validation, and must not trip known regression families.

## Baseline observations

- The refreshed official baseline has `hoarding_sink_enabled=0`.
- Star pricing, affordability, lock-in timing, boost pacing, and repeat-season pressure remain active and patchable.
- Hoarding-family tuning keys are feature-gated while the sink is disabled, so they are not part of the baseline-active single-knob surface unless the feature-enable knob itself is changed.
- Existing evidence shows:
  - a baseline-equivalent candidate still fails the official comparator on long-run concentration / archetype stability
  - `phase-gated-safe-24h-v1` passes focused harnesses but fails official qualification on long-run concentration and dominant-archetype shift

## Active tuning surface for this campaign

Primary active knobs to explore first:

- `market_affordability_bias_fp`
- `starprice_reactivation_window_ticks`
- `starprice_max_downstep_fp`
- `starprice_max_upstep_fp`
- `target_spend_rate_per_tick`
- `hoarding_min_factor_fp`
- `base_ubi_active_per_tick`
- `base_ubi_idle_factor_fp`

Conditional feature-state probe:

- `hoarding_sink_enabled`
- paired only with minimal supporting hoarding-safe values when feature activation is explicitly being tested

## Search strategy

### Stage 1: single-knob exploration

Run baseline-aware single-knob candidates centered on the active tensions:

- lock-in vs expiry support
- star affordability / pricing pressure
- boost viability / pacing
- repeat-season / rejoin pressure
- a tightly scoped hoarding-enable probe for feature-state evidence

For each candidate:

1. strict candidate lint
2. coupling harness pass/fail review
3. qualification comparator run for the best-supported singles

### Stage 2: pairwise combinations

Advance only knobs with support from stage 1. Prioritize pairings already suggested by the staged generator logic:

- `market_affordability_bias_fp` + `starprice_reactivation_window_ticks`
- `market_affordability_bias_fp` + `starprice_max_downstep_fp`
- `target_spend_rate_per_tick` + `hoarding_min_factor_fp`
- other pairwise candidates only if stage-1 evidence is favorable

### Stage 3: constrained bundles

Run only if pairwise evidence justifies it. Keep bundles small and evidence-led.

- likely cap at 3 knobs
- only if pairwise candidates improve the same failure family without introducing a new dominant regression

## Success criteria

A candidate is considered successful only if it:

- passes strict candidate validation
- passes effective-config preflight
- passes targeted coupling harnesses
- is non-reject under the official `qualification` comparator
- carries no blocking regression family
- is genuinely promotion-eligible

## Failure interpretation

If no candidate clears qualification, identify the narrowest real blocker:

- search-space issue
- threshold issue
- active baseline feature-state issue
- structural economy coupling
- performance/orchestration limit

## Planned outputs

- `balance_discovery_results.json`
- `balance_discovery_report.md`
