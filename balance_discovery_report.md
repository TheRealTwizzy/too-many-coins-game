# Balance Discovery Report

## A. Baseline and comparator profile used

- Official baseline artifact: `simulation_output/current-db/export/current_season_economy_only.json`
- Comparator profile: official `qualification`
- Comparator settings: simulators `B,C`, `players_per_archetype=2`, `season_count=4`
- Comparator remained the source of truth for viability and promotion readiness throughout the campaign.

## B. Active tuning surface under the refreshed baseline

Observed baseline facts:

- `hoarding_sink_enabled=0` in the refreshed official baseline.
- The staged generator nominally exposes:
  - `base_ubi_active_per_tick`
  - `base_ubi_idle_factor_fp`
  - `target_spend_rate_per_tick`
  - `starprice_max_upstep_fp`
  - `starprice_max_downstep_fp`
  - `starprice_reactivation_window_ticks`
  - `market_affordability_bias_fp`
  - plus broader pricing / table knobs

Observed canonical-preflight reality:

- `market_affordability_bias_fp` fails as `inactive_unreferenced`
- `starprice_reactivation_window_ticks` fails as `inactive_unreferenced`
- `target_spend_rate_per_tick` fails as `inactive_unreferenced`
- hoarding family knobs remain suppressed while `hoarding_sink_enabled=0`

Effective consequence:

- the comparator-relevant lock-in / affordability search surface is materially smaller than the staged generator implies
- the only clearly active single-knob lane discovered in this campaign that also survived harnesses was `starprice_max_upstep_fp`

## C. Search strategy and stages executed

Stage 1: single-knob exploration

- Generated 6 baseline-aware stage-1 candidates from a diagnosis focused on lock-in/expiry, star pricing, boost viability, progression pacing, and a hoarding feature-state probe.
- Strict lint on the staged candidate document passed.
- Screened all 6 stage-1 candidates through the canonical preflight path via coupling harnesses.

Stage 2: pairwise combinations

- Generated 12 stage-2 candidates, but screened only the three most relevant pairwise combinations first:
  - `market_affordability_bias_fp + starprice_reactivation_window_ticks`
  - `market_affordability_bias_fp + starprice_max_downstep_fp`
  - `starprice_max_upstep_fp + starprice_reactivation_window_ticks`
- All three died in preflight because at least one component knob was `inactive_unreferenced`.

Stage 3: constrained bundles

- Not executed as fresh qualification runs.
- Reason: stage-2 support was not strong enough to justify bundles, because the promising pairwise lanes were blocked before harness/comparator evaluation.

Additional manual single-knob probes

- `starprice_max_upstep_fp=900`
- `base_ubi_idle_factor_fp=275000`

Reference evidence reused

- `focused-clean-baseline-equivalent`
- `phase-gated-safe-24h-v1`

## D. Candidate families explored

Generator-backed singles:

- `market_affordability_bias_fp`
- `starprice_reactivation_window_ticks`
- `starprice_max_downstep_fp`
- `starprice_max_upstep_fp`
- `base_ubi_active_per_tick`
- `target_spend_rate_per_tick`

Generator-backed pairs:

- `market_affordability_bias_fp + starprice_reactivation_window_ticks`
- `market_affordability_bias_fp + starprice_max_downstep_fp`
- `starprice_max_upstep_fp + starprice_reactivation_window_ticks`

Manual probes:

- `starprice_max_upstep_fp=900`
- `base_ubi_idle_factor_fp=275000`

Feature-state reference probe:

- `hoarding_sink_enabled=1 + hoarding_safe_hours=24` via `phase-gated-safe-24h-v1`

## E. Best-performing candidates and why

`manual-starprice_max_upstep_fp-900`

- passed candidate validation
- passed effective-config preflight
- passed targeted harnesses
- passed multi-season exploit/regression validation
- improved long-run concentration damage versus the stronger `750` variant
- still failed official qualification

Why it was the best fresh probe:

- it preserved the only single-knob lane that was both canonically active and harness-clean
- it produced a smaller long-run concentration regression than the stronger `750` variant

`stage1-starprice_max_upstep_fp-03`

- same path success through validation, preflight, harnesses, and multi-season validation
- rejected by qualification with the same primary gate as the `900` variant
- stronger knob, but worse secondary concentration evidence than `900`

## F. Rejected candidates and dominant rejection reasons

Preflight-rejected:

- `stage1-market_affordability_bias_fp-02`
- `stage1-starprice_reactivation_window_ticks-01`
- `stage1-target_spend_rate_per_tick-05`
- stage-2 pairwise combinations containing those knobs

Dominant reason:

- canonical effective-config preflight marked the knobs `inactive_unreferenced`

Harness-rejected:

- `stage1-starprice_max_downstep_fp-04`
  - dominant reason: `hoarding_pressure_imbalance`
- `stage1-base_ubi_active_per_tick-06`
  - dominant reasons: `skip_rejoin_exploit_worsened`, `hoarding_pressure_imbalance`, `star_affordability_pricing_instability`
- `manual-base_ubi_idle_factor_fp-275000`
  - dominant reasons: `skip_rejoin_exploit_worsened`, `hoarding_pressure_imbalance`, `star_affordability_pricing_instability`

Qualification-rejected after clearing earlier gates:

- `stage1-starprice_max_upstep_fp-03`
  - primary gate: `lock_in_down_but_expiry_dominance_up`
  - secondary regressions: `long_run_concentration_worsened`, `engagement_up_but_t6_supply_spike`
- `manual-starprice_max_upstep_fp-900`
  - primary gate: `lock_in_down_but_expiry_dominance_up`
  - secondary regressions: `long_run_concentration_worsened`, `engagement_up_but_t6_supply_spike`
- `phase-gated-safe-24h-v1`
  - primary gate: `long_run_concentration_worsened`
- `focused-clean-baseline-equivalent`
  - primary gate: `seasonal_fairness_improves_but_long_run_concentration_worsens`

## G. Whether any candidate became comparator-approved and promotion-eligible

No.

- comparator-approved candidates found: `0`
- promotion-eligible candidates found: `0`

Every candidate that reached official qualification was rejected.

## H. Narrowest real blocker preventing success

Narrowest blocker: `search_space_issue`

Why this is the narrowest true blocker:

- the knobs most directly aligned with the current qualification failure pattern
  - affordability support
  - reactivation / lock-in support
  - spend pacing support
  are currently not runnable under the canonical effective-config resolver because preflight classifies them as `inactive_unreferenced`
- that leaves a much smaller truly active surface than the staged generator suggests
- the remaining active lane that did survive harnesses, `starprice_max_upstep_fp`, still pushes the economy into the known `lock_in_down_but_expiry_dominance_up` failure and also worsens long-run concentration

Why I am not calling threshold issue first:

- the candidates were not borderline non-rejects; they were still triggering named regression families

Why I am not calling orchestration/performance first:

- the campaign completed within practical bounds and produced enough qualification evidence to decide

Secondary blocker visible behind the primary one:

- structural economy coupling between lock-in/expiry relief, engagement/T6 supply, and long-run concentration

## I. Ranked balance suggestions grounded in simulation evidence

1. Reconcile the staged generator surface with canonical preflight activity before running a larger search.
   Evidence: `market_affordability_bias_fp`, `starprice_reactivation_window_ticks`, and `target_spend_rate_per_tick` were proposed as viable singles/pairs, linted cleanly, then died as `inactive_unreferenced` at canonical preflight.

2. Once the active surface is reconciled, run a focused qualification search on lock-in / affordability support knobs first.
   Evidence: the current successful-to-harness single-knob lane (`starprice_max_upstep_fp`) still fails on `lock_in_down_but_expiry_dominance_up`, so the missing affordability / reactivation lanes remain the most plausible place to offset that gate.

3. Keep hoarding-enable probes separate from the baseline-active search.
   Evidence: `phase-gated-safe-24h-v1` shows that enabling the sink can improve short-run fairness but still worsens long-run concentration, so it should remain a feature-state investigation, not the default next move.

4. Avoid further investment in `base_ubi_active_per_tick` or `base_ubi_idle_factor_fp` as first-line singles under the current baseline.
   Evidence: both progression-style probes tripped multiple harness families before reaching qualification.

5. If lock-in / affordability knobs become runnable, retry mild pricing bundles before broader economy bundles.
   Evidence: the fresh campaign never earned stage-2 support for broader bundles because the relevant pairwise lanes were blocked at preflight.

## J. Final recommendation

Reopen blocker: `search-space issue`

Concrete next action:

- adjust the allowed tuning surface so the canonical effective-config resolver and staged generator agree on which lock-in / affordability knobs are truly active under the refreshed baseline
- then run a larger targeted search in the lock-in / star-pricing subsystem, starting again from single knobs and pairwise combinations under official qualification

Not recommended now:

- do not promote any candidate
- do not force broader bundle search while the most relevant comparator-facing knobs are still blocked or contradictory

## Uncertainty

- Qualification evidence is still based on a reduced official profile, not the broader full-campaign bundle.
- I did not run fresh stage-3 constrained bundles because stage-2 support was insufficient after canonical preflight filtered out the promising pairwise lanes.
- The baseline-equivalent comparator rejection remains a sign that the qualification surface is structurally hostile even before tuning, so there may still be deeper economy coupling behind the search-space blocker.
