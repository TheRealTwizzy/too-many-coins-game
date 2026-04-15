# Focused Rebalance Report

## A. Current active tuning surface

Canonical live and searchable knobs on the reconciled truthful surface:

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

Current baseline facts that matter for search:

- Baseline artifact: `simulation_output/current-db/export/current_season_economy_only.json`
- Baseline uses `hoarding_sink_enabled=0`
- That means the hoarding sink family is truthful and searchable, but only becomes active when a candidate explicitly enables the sink
- Fake knobs are still excluded: `target_spend_rate_per_tick`, `starprice_reactivation_window_ticks`, `market_affordability_bias_fp`, `starprice_demand_table`, `hoarding_window_ticks`, and `vault_config`

## B. Search stages executed

### Stage 1: Single-knob sweeps

Harness-screened singles:

- `focused-single-starprice-max-upstep-950`
  - patch: `starprice_max_upstep_fp=950`
  - harness: pass
- `focused-single-starprice-max-downstep-18000`
  - patch: `starprice_max_downstep_fp=18000`
  - harness: fail on `boost_underperformance`
  - dropped before qualification
- `focused-single-hoarding-min-factor-70000`
  - patch: `hoarding_min_factor_fp=70000`
  - harness: pass
- `focused-single-hoarding-sink-enabled`
  - patch: `hoarding_sink_enabled=1`
  - harness: pass

Qualification-run singles:

- `focused-single-starprice-max-upstep-950`
- `focused-single-hoarding-min-factor-70000`
- `focused-single-hoarding-sink-enabled`

### Stage 2: Pairwise exploration of promising winners

Harness-screened pairs:

- `focused-pair-starprice950-hoardingmin70000`
  - patch: `starprice_max_upstep_fp=950`, `hoarding_min_factor_fp=70000`
  - harness: pass
- `focused-pair-hoardingsink-safe24`
  - patch: `hoarding_sink_enabled=1`, `hoarding_safe_hours=24`
  - harness: pass

Qualification-run pairs:

- `focused-pair-starprice950-hoardingmin70000`
- `focused-pair-hoardingsink-safe24`

### Stage 3: Constrained bundles

- No 3+ knob bundle was run
- Reason: pairwise evidence did not produce a comparator-near non-reject lane; widening the search would have added noise without new support

## C. Best-performing candidates

### 1. `focused-pair-hoardingsink-safe24`

- What improved:
  - best campaign headline at `9 wins / 7 losses / 2 mixed`
  - clean harness pass
  - improved short-run comparator scorecard in simulator B
- What worsened:
  - official comparator still rejected it on `long_run_concentration_worsened`
  - triggered `candidate_improves_B_but_worsens_C`
  - also shifted dominant archetype
- Harnesses:
  - passed
- Official comparator qualification:
  - failed
  - disposition: `reject`
- Promotion eligibility:
  - no

### 2. `focused-single-hoarding-sink-enabled`

- What improved:
  - best single-knob headline at `7 wins / 8 losses / 2 mixed`
  - clean harness pass
  - smaller concentration delta than the safe-24 pair
- What worsened:
  - `lock_in_down_but_expiry_dominance_up`
  - `skip_rejoin_exploit_worsened`
  - `long_run_concentration_worsened`
  - `candidate_improves_B_but_worsens_C`
- Harnesses:
  - passed
- Official comparator qualification:
  - failed
  - disposition: `reject`
- Promotion eligibility:
  - no

### 3. `focused-single-hoarding-min-factor-70000`

- What improved:
  - clean harness pass
  - slightly better scoreboard than the star-price single
- What worsened:
  - `lock_in_down_but_expiry_dominance_up`
  - dominant-archetype rotation
- Harnesses:
  - passed
- Official comparator qualification:
  - failed
  - disposition: `reject`
- Promotion eligibility:
  - no

### 4. `focused-single-starprice-max-upstep-950`

- What improved:
  - clean harness pass
  - gentler than the earlier 750 and 900 probes while staying truthful
- What worsened:
  - `long_run_concentration_worsened`
  - `skip_rejoin_exploit_worsened`
  - dominant-archetype rotation
- Harnesses:
  - passed
- Official comparator qualification:
  - failed
  - disposition: `reject`
- Promotion eligibility:
  - no

## D. Dominant rejection reasons

Across the qualification runs, the recurring reject causes were:

- `long_run_concentration_worsened`
- `lock_in_down_but_expiry_dominance_up`
- `skip_rejoin_exploit_worsened`
- `dominant_archetype_shifted`
- `candidate_improves_B_but_worsens_C`

The failure pattern split by lane:

- star-price relief mostly paid for short-run gains with worse long-run concentration and skip/rejoin edge
- lower hoarding floor mostly weakened lock-in while raising expiry pressure
- sink activation improved the B-side scorecard, but the gain flipped into simulator-C concentration and dominance regressions

## E. Whether any candidate became comparator-approved

- No
- Comparator-approved candidates found: `0`

## F. Whether any candidate became promotion-eligible

- No
- Promotion-eligible candidates found: `0`

## G. Top ranked balance suggestions

1. If search must continue, keep it in the sink-enable timing lane, but only with smaller timing moves than `safe_hours=24`.
   Why: this was the best-performing family, but `24h` overshot into a large C-side concentration regression.
2. Avoid pairing `starprice_max_upstep` reductions with a lower `hoarding_min_factor_fp`.
   Why: `focused-pair-starprice950-hoardingmin70000` was the worst qualified pair at `3 wins / 13 losses / 2 mixed`.
3. Do not broaden the `starprice_max_downstep_fp` lane right now.
   Why: the truthful "relax faster" direction already failed harness on `boost_underperformance`.
4. Treat `hoarding_sink_enabled=1` without additional timing support as comparator-negative.
   Why: it improved some B-side outcomes but still worsened lock-in/expiry, skip/rejoin, and long-run concentration.

## H. If no candidate passed, the narrowest structural economy blocker

The narrowest remaining blocker is:

- short-run relief on the truthful active surface still converts into long-run concentration and strategy-takeover regressions

Why this is the narrowest blocker:

- search-surface integrity is no longer the issue
- multiple truthful lanes now clear preflight and harnesses
- the best pairwise lane still fails because simulator C re-concentrates even when simulator B improves
- the economy currently lacks a truthful active knob combination that improves short-run fairness without recreating a long-run dominance response

## I. Final recommendation

`reopen structural blocker Z`

Specifically:

- reopen `short_run_relief_trades_into_long_run_concentration`

Rationale:

- no candidate became comparator-approved
- no candidate became promotion-eligible
- the best evidence-rich pair still rejected on long-run concentration despite a stronger short-run scorecard
- further broadening on the same active surface would likely add noise before fixing the actual economic tradeoff

## Uncertainty

- Comparator attribution remains evidence-rich but not counterfactual proof; the paired simulator sample is still small
- The sink timing lane is the closest thing to a live next-step search lane, but this campaign did not justify continuing it without first acknowledging the structural concentration blocker
