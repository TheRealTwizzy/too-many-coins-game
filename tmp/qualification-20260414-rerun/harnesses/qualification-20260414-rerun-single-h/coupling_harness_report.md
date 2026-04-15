# Coupling Harness Report

Generated: 2026-04-15T03:19:14+00:00
Seed: `qualification-20260414-rerun-single-h`
Overall status: `fail`
Selected families: lock_in_down_but_expiry_dominance_up, skip_rejoin_exploit_worsened, hoarding_pressure_imbalance, boost_underperformance, star_affordability_pricing_instability

## Promotion Ladder

- This gate runs before tier2/tier3 promotion in the agentic ladder.
- A failing family is an early reject even if the local objective score looks good.

## Lock-in down while expiry dominance rises (`lock_in_down_but_expiry_dominance_up`)

- Status: `pass`
- Harness profile: `coupling_lockin_expiry`
- Simulators: B
- Estimated speedup vs tier3 full: 16000x
- Proves: Short-horizon lock-in and expiry incentives did not recreate the previously observed structural failure.
- Cannot prove: Does not prove long-run repeat-season retention or full-economy stability.
- Directional diagnostics:
  - `lock_in_total` pass | baseline=3 candidate=3 delta=0 | lock_in_total held by 0; goal-facing improvement=0 (need >= 0).
  - `expiry_rate_mean` pass | baseline=0 candidate=0 delta=0 | expiry_rate_mean held by 0; goal-facing improvement=0 (need >= 0).
  - `lock_in_timing_entropy` pass | baseline=0.91829583405449 candidate=0.91829583405449 delta=0 | lock_in_timing_entropy held by 0; goal-facing improvement=0 (need >= 0).

## Skip/rejoin exploit worsened (`skip_rejoin_exploit_worsened`)

- Status: `fail`
- Harness profile: `coupling_skip_rejoin`
- Simulators: C
- Estimated speedup vs tier3 full: 4000x
- Proves: Short lifecycle retention dynamics did not make the skip/rejoin exploit materially stronger.
- Cannot prove: Does not prove final long-run concentration is globally acceptable outside this reduced harness.
- Directional diagnostics:
  - `skip_strategy_edge` fail | baseline=4898.6666666667 candidate=4959.6666666667 delta=61 | skip_strategy_edge rose by 61; goal-facing improvement=-61 (need >= 0).
  - `repeat_season_viability` pass | baseline=0.33333333333333 candidate=0.33333333333333 delta=0 | repeat_season_viability held by 0; goal-facing improvement=0 (need >= 0).
  - `throughput_lock_in_rate` pass | baseline=1 candidate=1 delta=0 | throughput_lock_in_rate held by 0; goal-facing improvement=0 (need >= 0).

## Hoarding pressure imbalance (`hoarding_pressure_imbalance`)

- Status: `fail`
- Harness profile: `coupling_hoarding`
- Simulators: B, C
- Estimated speedup vs tier3 full: 2000x
- Proves: The cheap anti-hoarding harness did not show the candidate worsening safe-strategy dominance.
- Cannot prove: Does not prove cross-subsystem pricing or retention interactions remain healthy at full scale.
- Blocking flags: archetype_viability_regressed
- Directional diagnostics:
  - `hoarder_advantage_gap` pass | baseline=9412 candidate=7791 delta=-1621 | hoarder_advantage_gap fell by 1621; goal-facing improvement=1621 (need >= 0).
  - `dominant_strategy_pressure` pass | baseline=0.82200295237355 candidate=0.76430842607313 delta=-0.057694526300421 | dominant_strategy_pressure fell by 0.057695; goal-facing improvement=0.057695 (need >= 0).
  - `strategic_diversity` fail | baseline=0.79168959614061 candidate=0.79039423382151 delta=-0.0012953623191024 | strategic_diversity fell by 0.001295; goal-facing improvement=-0.001295 (need >= 0).

## Boost underperformance (`boost_underperformance`)

- Status: `pass`
- Harness profile: `coupling_boost`
- Simulators: B
- Estimated speedup vs tier3 full: 16000x
- Proves: The reduced boost harness did not show the candidate weakening boost payoff or deployment timing.
- Cannot prove: Does not prove boost changes remain neutral once all other subsystems mutate together.
- Directional diagnostics:
  - `boost_roi` pass | baseline=24.191171088747 candidate=25.480009149131 delta=1.2888380603843 | boost_roi rose by 1.288838; goal-facing improvement=1.288838 (need >= 0).
  - `boost_mid_late_share` pass | baseline=0.66338508140856 candidate=0.66338508140856 delta=0 | boost_mid_late_share held by 0; goal-facing improvement=0 (need >= 0).
  - `boost_focused_gap` pass | baseline=0 candidate=0 delta=0 | boost_focused_gap held by 0; goal-facing improvement=0 (need >= 0).

## Star affordability and pricing instability (`star_affordability_pricing_instability`)

- Status: `fail`
- Harness profile: `coupling_star_pricing`
- Simulators: B
- Estimated speedup vs tier3 full: 16000x
- Proves: The focused star-market slice did not show weaker affordability or more unstable price movement.
- Cannot prove: Does not prove production star-price behavior under the full player mix or full-duration seasons.
- Directional diagnostics:
  - `star_purchase_density` pass | baseline=1967 candidate=2427 delta=460 | star_purchase_density rose by 460; goal-facing improvement=460 (need >= 0).
  - `first_choice_viability` pass | baseline=1 candidate=1 delta=0 | first_choice_viability held by 0; goal-facing improvement=0 (need >= 0).
  - `star_price_cap_share` pass | baseline=0 candidate=0 delta=0 | star_price_cap_share held by 0; goal-facing improvement=0 (need >= 0).
  - `star_price_range_ratio` fail | baseline=0.079546584468529 candidate=0.099295005461225 delta=0.019748420992696 | star_price_range_ratio rose by 0.019748; goal-facing improvement=-0.019748 (need >= 0).
